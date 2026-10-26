<?php

namespace App\Shipping\Drivers;

use App\Orders\DTOs\SellerOrderInvoiceDto;
use App\Orders\DTOs\SellerOrderShippingDetailsDto;
use App\Sellers\DTOs\SellerPickupAddressDto;
use App\Shipping\Contracts\ShippingCarrierInterface;
use App\Shipping\DTOs\CreateShipmentRequestDto;
use App\Shipping\DTOs\CreateShipmentResponseDto;
use App\Shipping\DTOs\SellerWarehouseRegistrationDto;
use App\Shipping\DTOs\ServiceabilityResultDto;
use App\Shipping\DTOs\TrackingStatusDto;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use Carbon\Carbon;
use DomainException;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ShiprocketCarrierDriver implements ShippingCarrierInterface
{
    protected string $baseUrl;

    protected string $email;

    protected string $password;

    protected string $courierSelectionRule;

    protected ?int $specificCourierId;

    protected int $timeoutSeconds;

    protected int $retryAttempts;

    protected bool $safeMode;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? config('shipping.shiprocket.base_url', 'https://apiv2.shiprocket.in/v1/external')), '/');
        $this->email = (string) ($config['email'] ?? config('shipping.shiprocket.email', ''));
        $this->password = (string) ($config['password'] ?? config('shipping.shiprocket.password', ''));
        $this->courierSelectionRule = (string) ($config['courier_selection_rule'] ?? config('shipping.shiprocket.courier_selection_rule', 'recommended'));
        $this->specificCourierId = ! empty($config['specific_courier_id']) ? (int) $config['specific_courier_id'] : config('shipping.shiprocket.specific_courier_id');
        $this->timeoutSeconds = (int) ($config['timeout_seconds'] ?? config('shipping.shiprocket.timeout_seconds', 15));
        $this->retryAttempts = (int) ($config['retry_attempts'] ?? config('shipping.shiprocket.retry_attempts', 3));
        $this->safeMode = (bool) (array_key_exists('safe_mode', $config) ? $config['safe_mode'] : config('shipping.safe_mode', true));
    }

    public function name(): string
    {
        return 'shiprocket';
    }

    /**
     * Centralized weight conversion from grams to kilograms (kg).
     * e.g. 500g -> 0.5kg, 1250g -> 1.25kg, 50g -> 0.05kg.
     */
    public static function gramsToKg(int $grams): float
    {
        return round($grams / 1000, 3);
    }

    /**
     * Get or fetch cached OAuth Bearer token.
     */
    public function getAccessToken(): string
    {
        if (empty($this->email)) {
            return 'fake-shiprocket-token';
        }

        $cacheKey = 'shiprocket_token_'.md5($this->email);

        return Cache::remember($cacheKey, now()->addDays(9), function () {
            $url = "{$this->baseUrl}/auth/login";

            $response = $this->baseRequest()
                ->retry($this->retryAttempts, 200, throw: false)
                ->post($url, [
                    'email' => $this->email,
                    'password' => $this->password,
                ]);

            if (! $response->successful()) {
                Log::error('Shiprocket authentication failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new DomainException('Shiprocket carrier authentication failed: '.$response->body());
            }

            $data = $response->json();
            $token = $data['token'] ?? null;
            if (! $token) {
                throw new DomainException('Shiprocket authentication returned empty token.');
            }

            return (string) $token;
        });
    }

    /**
     * Fetch wallet balance in INR.
     */
    public function getWalletBalance(): float
    {
        try {
            $response = $this->client()->get('/account/details/wallet-balance');
            if (! $response->successful()) {
                Log::warning('Shiprocket wallet balance check failed', ['body' => $response->body()]);

                return 0.0;
            }

            $data = $response->json();

            return (float) ($data['data']['balance_amount'] ?? 0.0);
        } catch (Exception $e) {
            Log::warning('Shiprocket wallet balance check exception', ['error' => $e->getMessage()]);

            return 0.0;
        }
    }

    /**
     * Build base pending request with timeout, JSON headers, and SSL options.
     */
    protected function baseRequest(): PendingRequest
    {
        $request = Http::timeout($this->timeoutSeconds)->acceptJson();

        $verify = config('shipping.ssl_verify', true);
        $caPath = config('shipping.ca_bundle_path');

        if (! empty($caPath) && file_exists($caPath)) {
            $request = $request->withOptions(['verify' => $caPath]);
        } elseif ($verify === false || $verify === 'false' || $verify === 0 || $verify === '0') {
            $request = $request->withOptions(['verify' => false]);
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $curlCa = (string) ini_get('curl.cainfo');
            $sslCa = (string) ini_get('openssl.cafile');

            if ((empty($curlCa) || ! file_exists($curlCa)) && (empty($sslCa) || ! file_exists($sslCa))) {
                $candidates = [
                    'D:/xampp/php/extras/ssl/cacert.pem',
                    'D:/xampp/apache/bin/curl-ca-bundle.crt',
                    'C:/xampp/php/extras/ssl/cacert.pem',
                    'C:/xampp/apache/bin/curl-ca-bundle.crt',
                ];
                foreach ($candidates as $candidate) {
                    if (file_exists($candidate)) {
                        $request = $request->withOptions(['verify' => $candidate]);
                        break;
                    }
                }
            }
        }

        return $request;
    }

    /**
     * Authenticated HTTP client with bearer token and base URL.
     */
    protected function client(): PendingRequest
    {
        return $this->baseRequest()
            ->baseUrl($this->baseUrl)
            ->withToken($this->getAccessToken());
    }

    public function checkServiceability(string $pincode, ?string $pickupPincode = null): ServiceabilityResultDto
    {
        $cleanPincode = (int) preg_replace('/\D/', '', $pincode);
        $cleanPickup = $pickupPincode ? (int) preg_replace('/\D/', '', $pickupPincode) : 560001;

        try {
            $response = $this->client()->get('/courier/serviceability/', [
                'pickup_postcode' => $cleanPickup,
                'delivery_postcode' => $cleanPincode,
                'weight' => 0.5,
                'cod' => 1,
            ]);

            if (! $response->successful()) {
                return new ServiceabilityResultDto(
                    serviceable: false,
                    codAvailable: false,
                    maxCodAmountPaise: 0,
                    errorMessage: 'Shiprocket serviceability check failed: '.$response->body(),
                );
            }

            $data = $response->json();
            $innerData = $data['data'] ?? [];
            $available = $innerData['available_courier_companies'] ?? [];

            if (empty($available)) {
                return new ServiceabilityResultDto(
                    serviceable: false,
                    codAvailable: false,
                    maxCodAmountPaise: 0,
                    errorMessage: 'No courier partners available for this route.',
                    rawResponse: $data,
                );
            }

            $selectedCourier = $this->selectCourier($available, $innerData);
            $cod = (bool) ($selectedCourier['cod'] ?? false);
            $rateInr = (float) ($selectedCourier['rate'] ?? $selectedCourier['freight_charge'] ?? 0.0);
            $tatDays = (int) ($selectedCourier['estimated_delivery_days'] ?? 4);
            $city = $selectedCourier['city'] ?? null;
            $state = $selectedCourier['state'] ?? null;

            return new ServiceabilityResultDto(
                serviceable: true,
                codAvailable: $cod,
                maxCodAmountPaise: 4999900,
                city: $city,
                state: $state,
                tatMinDays: max(1, $tatDays - 1),
                tatMaxDays: max(2, $tatDays + 2),
                rawResponse: $data,
            );
        } catch (Exception $e) {
            Log::warning('Shiprocket serviceability exception (fail open)', ['error' => $e->getMessage()]);

            return new ServiceabilityResultDto(
                serviceable: true,
                codAvailable: true,
                maxCodAmountPaise: 4999900,
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * Select courier partner from serviceability results based on strategy.
     *
     * @param  array<int, array<string, mixed>>  $available
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function selectCourier(array $available, array $data = []): array
    {
        if (empty($available)) {
            return [];
        }

        return match ($this->courierSelectionRule) {
            'cheapest' => collect($available)->sortBy(fn ($c) => (float) ($c['rate'] ?? $c['freight_charge'] ?? 99999))->first() ?? $available[0],
            'fastest' => collect($available)->sortBy(fn ($c) => (int) ($c['estimated_delivery_days'] ?? 999))->first() ?? $available[0],
            'specific' => collect($available)->firstWhere('courier_company_id', $this->specificCourierId)
                ?? collect($available)->firstWhere('courier_name', $this->specificCourierId)
                ?? $this->getRecommendedCourier($available, $data),
            default => $this->getRecommendedCourier($available, $data),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $available
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getRecommendedCourier(array $available, array $data): array
    {
        $recId = $data['recommended_courier_company_id'] ?? $data['shiprocket_recommended_courier_id'] ?? null;
        if ($recId) {
            $match = collect($available)->firstWhere('courier_company_id', $recId);
            if ($match) {
                return $match;
            }
        }

        return $available[0];
    }

    public function registerPickupAddress(SellerPickupAddressDto $address): SellerWarehouseRegistrationDto
    {
        $alias = 'WH-'.$address->sellerProfileId;
        $cleanPhone = preg_replace('/\D/', '', $address->phone);
        $cleanPincode = (int) preg_replace('/\D/', '', $address->postalCode);

        $payload = [
            'pickup_location' => $alias,
            'name' => substr($address->storeName ?: 'Seller Warehouse', 0, 100),
            'email' => $address->email ?: 'seller@khilauna.com',
            'phone' => substr($cleanPhone, -10) ?: '9876543210',
            'address' => substr($address->addressLine1, 0, 100),
            'address_2' => $address->addressLine2 ? substr($address->addressLine2, 0, 100) : '',
            'city' => $address->city,
            'state' => $address->state,
            'country' => 'India',
            'pin_code' => $cleanPincode,
        ];

        try {
            $response = $this->client()->post('/settings/company/addpickup', $payload);

            if (! $response->successful()) {
                // If registration failed because it already exists on Shiprocket, check existing list
                $existing = collect($this->getRegisteredAddresses())->firstWhere('pickup_location', $alias);
                if ($existing) {
                    $phoneVerified = (int) ($existing['phone_verified'] ?? 0);
                    $statusText = $phoneVerified === 1 ? 'registered' : 'pending_verification';
                    $msg = $phoneVerified === 1
                        ? 'Warehouse verified on Shiprocket.'
                        : 'Warehouse registered in Shiprocket; pending phone/OTP verification.';

                    return new SellerWarehouseRegistrationDto(
                        success: true,
                        alias: $alias,
                        message: $msg,
                        rawResponse: [
                            'success' => true,
                            'address' => $existing,
                            'status_text' => $statusText,
                            'phone_verified' => $phoneVerified === 1,
                        ],
                    );
                }

                return new SellerWarehouseRegistrationDto(
                    success: false,
                    alias: $alias,
                    message: $response->body(),
                    rawResponse: $response->json(),
                );
            }

            $data = $response->json();
            $success = (bool) ($data['success'] ?? false);
            $addr = $data['address'] ?? [];
            $phoneVerified = (int) ($addr['phone_verified'] ?? 0);

            $statusText = $phoneVerified === 1 ? 'registered' : 'pending_verification';
            $msg = $phoneVerified === 1
                ? 'Warehouse registered and verified successfully.'
                : 'Warehouse registered in Shiprocket; pending phone/OTP verification.';

            return new SellerWarehouseRegistrationDto(
                success: $success,
                alias: $alias,
                message: $msg,
                rawResponse: array_merge($data, ['status_text' => $statusText, 'phone_verified' => $phoneVerified === 1]),
            );
        } catch (Exception $e) {
            return new SellerWarehouseRegistrationDto(
                success: false,
                alias: $alias,
                message: $e->getMessage(),
            );
        }
    }

    public function getRegisteredAddresses(): array
    {
        try {
            $response = $this->client()->get('/settings/company/pickup');

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();

            return $data['data']['shipping_address'] ?? [];
        } catch (Exception $e) {
            Log::warning('Shiprocket getRegisteredAddresses error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function createShipment(
        CreateShipmentRequestDto $request,
        SellerOrderShippingDetailsDto $details,
        SellerOrderInvoiceDto $invoice,
        ?Shipment $existingShipment = null,
    ): CreateShipmentResponseDto {
        if ($this->safeMode) {
            throw new DomainException('Shipping safe mode is active. Live shipment creation is blocked.');
        }

        $attempt = $existingShipment?->fulfillment_attempt ?: 1;
        $step = $existingShipment?->creation_step ?: 'pending';
        $shiprocketOrderId = $existingShipment?->provider_order_id;
        $shiprocketShipmentId = $existingShipment?->provider_shipment_id ? (int) $existingShipment->provider_shipment_id : null;
        $awbCode = $existingShipment?->awb;
        $courierName = $existingShipment?->courier_name;
        $orderData = [];
        $awbData = [];

        $orderRef = str_replace('/', '-', $invoice->invoiceNumber)."-{$attempt}";
        $alias = $request->pickupLocationAlias ?: ('WH-'.$details->sellerProfileId);
        $weightKg = self::gramsToKg(max(50, $request->actualWeightGrams));

        $customerPhone = preg_replace('/\D/', '', $details->customerPhone);
        $phone10 = substr($customerPhone, -10) ?: '9876543210';

        $totalAmountInr = round($details->grandTotalPaise / 100, 2);

        // --- STEP 1: CREATE CUSTOM ORDER (if step is pending or no shipment_id yet) ---
        if ($step === 'pending' || empty($shiprocketShipmentId)) {
            $orderItems = [];
            foreach ($details->items as $item) {
                $orderItems[] = [
                    'name' => substr($item->title, 0, 100),
                    'sku' => substr($item->sku, 0, 50),
                    'units' => max(1, $item->quantity),
                    'selling_price' => round($item->unitPricePaise / 100, 2),
                    'tax' => round($item->taxAmountPaise / 100, 2),
                ];
            }

            $createPayload = [
                'order_id' => $orderRef,
                'order_date' => now()->format('Y-m-d H:i'),
                'pickup_location' => $alias,
                'billing_customer_name' => substr($details->customerName, 0, 100),
                'billing_last_name' => '.',
                'billing_address' => substr($details->customerAddress1, 0, 100),
                'billing_address_2' => $details->customerAddress2 ? substr($details->customerAddress2, 0, 100) : '',
                'billing_city' => $details->customerCity,
                'billing_pincode' => (int) preg_replace('/\D/', '', $details->customerPostalCode),
                'billing_state' => $details->customerState,
                'billing_country' => 'India',
                'billing_phone' => $phone10,
                'shipping_is_billing' => true,
                'order_items' => $orderItems,
                'payment_method' => $details->isCod ? 'COD' : 'Prepaid',
                'sub_total' => max(1, $totalAmountInr),
                'length' => max(1, $request->lengthCm),
                'breadth' => max(1, $request->widthCm),
                'height' => max(1, $request->heightCm),
                'weight' => $weightKg,
                'invoice_number' => $invoice->invoiceNumber,
            ];

            $orderResp = $this->client()->post('/orders/create/adhoc', $createPayload);
            if (! $orderResp->successful()) {
                $this->detectWalletBalanceError($orderResp->body());

                return new CreateShipmentResponseDto(
                    success: false,
                    awb: null,
                    vendorWbn: null,
                    publicTrackingUrl: null,
                    errorMessage: 'Shiprocket order creation failed: '.$orderResp->body(),
                    rawResponse: $orderResp->json(),
                );
            }

            $orderData = $orderResp->json();
            $shiprocketOrderId = $orderData['order_id'] ?? null;
            $shiprocketShipmentId = $orderData['shipment_id'] ?? null;

            if (! $shiprocketShipmentId) {
                return new CreateShipmentResponseDto(
                    success: false,
                    awb: null,
                    vendorWbn: null,
                    publicTrackingUrl: null,
                    errorMessage: 'Shiprocket order creation succeeded but returned no shipment_id.',
                    rawResponse: $orderData,
                );
            }

            $step = 'order_created';
            if ($existingShipment) {
                $existingShipment->update([
                    'provider_order_id' => (string) $shiprocketOrderId,
                    'provider_shipment_id' => (string) $shiprocketShipmentId,
                    'creation_step' => 'order_created',
                ]);
            }
        }

        // --- STEP 2: CHECK SERVICEABILITY & WALLET BALANCE BEFORE ASSIGNING AWB ---
        $estimatedChargeInr = 50.0;
        $selectedCourierId = null;

        if ($step === 'order_created' && empty($awbCode)) {
            $serviceability = $this->checkServiceability($details->customerPostalCode, $details->sellerPickupPostalCode);

            if ($serviceability->rawResponse && ! empty($serviceability->rawResponse['data']['available_courier_companies'])) {
                $selectedCourier = $this->selectCourier(
                    $serviceability->rawResponse['data']['available_courier_companies'],
                    $serviceability->rawResponse['data']
                );
                $selectedCourierId = $selectedCourier['courier_company_id'] ?? null;
                $courierName = $selectedCourier['courier_name'] ?? null;
                $estimatedChargeInr = (float) ($selectedCourier['rate'] ?? $selectedCourier['freight_charge'] ?? 50.0);
            }

            // Check wallet balance against courier charge
            $balance = $this->getWalletBalance();
            if ($balance < $estimatedChargeInr) {
                throw new DomainException('Shiprocket wallet balance (₹'.number_format($balance, 2).') is below the required courier charge (₹'.number_format($estimatedChargeInr, 2).'). Please recharge your Shiprocket account wallet.');
            }

            // --- STEP 3: ASSIGN AWB (NEVER assign a second AWB) ---
            $assignPayload = [
                'shipment_id' => $shiprocketShipmentId,
            ];
            if ($selectedCourierId) {
                $assignPayload['courier_id'] = $selectedCourierId;
            }

            $awbResp = $this->client()->post('/courier/assign/awb', $assignPayload);
            if (! $awbResp->successful()) {
                $this->detectWalletBalanceError($awbResp->body());

                return new CreateShipmentResponseDto(
                    success: false,
                    awb: null,
                    vendorWbn: null,
                    publicTrackingUrl: null,
                    errorMessage: 'Shiprocket AWB assignment failed: '.$awbResp->body(),
                    rawResponse: $awbResp->json(),
                );
            }

            $awbData = $awbResp->json();
            $awbCode = $awbData['response']['data']['awb_code'] ?? null;
            $courierName = $awbData['response']['data']['courier_name'] ?? $courierName;

            if (! $awbCode) {
                return new CreateShipmentResponseDto(
                    success: false,
                    awb: null,
                    vendorWbn: null,
                    publicTrackingUrl: null,
                    errorMessage: 'Shiprocket assigned no AWB code: '.json_encode($awbData),
                    rawResponse: $awbData,
                );
            }

            $step = 'awb_assigned';
            if ($existingShipment) {
                $existingShipment->update([
                    'awb' => (string) $awbCode,
                    'courier_name' => $courierName,
                    'creation_step' => 'awb_assigned',
                ]);
            }
        }

        // --- STEP 4: GENERATE PICKUP ---
        if ($step === 'awb_assigned') {
            try {
                $this->client()->post('/courier/generate/pickup', [
                    'shipment_id' => [$shiprocketShipmentId],
                ]);
                $step = 'pickup_generated';
                if ($existingShipment) {
                    $existingShipment->update(['creation_step' => 'pickup_generated']);
                }
            } catch (Exception $e) {
                Log::warning('Shiprocket generate pickup warning', ['error' => $e->getMessage()]);
            }
        }

        // --- STEP 5: GENERATE LABEL & DOWNLOAD IMMEDIATELY ---
        if ($step === 'pickup_generated' || $step === 'label_ready') {
            try {
                $labelResp = $this->client()->post('/courier/generate/label', [
                    'shipment_id' => [$shiprocketShipmentId],
                ]);

                if ($labelResp->successful()) {
                    $labelData = $labelResp->json();
                    $labelUrl = $labelData['label_url'] ?? null;
                    if ($labelUrl) {
                        $pdfBinary = $this->baseRequest()->timeout(15)->get($labelUrl)->body();
                        if (! empty($pdfBinary)) {
                            $labelPath = $existingShipment
                                ? "shipping_labels/{$existingShipment->id}.pdf"
                                : "shipping_labels/sr_{$shiprocketShipmentId}.pdf";
                            Storage::disk('local')->put($labelPath, $pdfBinary);
                            if ($existingShipment) {
                                $existingShipment->update([
                                    'label_path' => $labelPath,
                                    'label_generated_at' => now(),
                                    'creation_step' => 'label_ready',
                                ]);
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                Log::warning('Shiprocket label generation/download deferred', ['error' => $e->getMessage()]);
            }
        }

        $publicUrl = "https://shiprocket.co/tracking/{$awbCode}";

        return new CreateShipmentResponseDto(
            success: true,
            awb: (string) $awbCode,
            vendorWbn: (string) $shiprocketShipmentId,
            publicTrackingUrl: $publicUrl,
            estimatedChargesPaise: (int) round($estimatedChargeInr * 100),
            rawResponse: [
                'shiprocket_order_id' => $shiprocketOrderId,
                'shiprocket_shipment_id' => $shiprocketShipmentId,
                'courier_name' => $courierName,
                'order_response' => $orderData,
                'awb_response' => $awbData,
                'fulfillment_attempt' => $attempt,
            ],
        );
    }

    /**
     * Detect wallet balance error in response body and throw non-retryable DomainException.
     */
    protected function detectWalletBalanceError(string $responseBody): void
    {
        $lower = strtolower($responseBody);
        if (str_contains($lower, 'insufficient') || str_contains($lower, 'recharge') || str_contains($lower, 'wallet balance') || str_contains($lower, 'low balance')) {
            throw new DomainException('Shiprocket wallet balance is insufficient to create this shipment. Please recharge the Shiprocket account wallet.');
        }
    }

    public function downloadLabel(string $awb): string
    {
        $response = $this->client()->post('/courier/generate/label', [
            'shipment_id' => [(int) $awb],
        ]);

        if (! $response->successful()) {
            throw new DomainException('Shiprocket label generation failed: '.$response->body());
        }

        $data = $response->json();
        $labelUrl = $data['label_url'] ?? null;
        if (! $labelUrl) {
            throw new DomainException('Shiprocket returned no label URL.');
        }

        $pdfResponse = $this->baseRequest()->get($labelUrl);
        if (! $pdfResponse->successful()) {
            throw new DomainException('Failed to download Shiprocket label PDF from: '.$labelUrl);
        }

        return $pdfResponse->body();
    }

    /**
     * Generate and download manifest PDF immediately to private storage.
     */
    public function generateManifest(int|string $shipmentId): string
    {
        $response = $this->client()->post('/manifests/generate', [
            'shipment_id' => [(int) $shipmentId],
        ]);

        if (! $response->successful()) {
            throw new DomainException('Shiprocket manifest generation failed: '.$response->body());
        }

        $data = $response->json();
        $manifestUrl = $data['manifest_url'] ?? null;
        if (! $manifestUrl) {
            throw new DomainException('Shiprocket returned no manifest URL.');
        }

        $pdf = $this->baseRequest()->get($manifestUrl)->body();
        $path = "shipping_manifests/sr_{$shipmentId}.pdf";
        Storage::disk('local')->put($path, $pdf);

        return $path;
    }

    public function setDispatchDate(array $awbs, string $date): bool
    {
        // Shiprocket handles pickup dates via /courier/generate/pickup
        return true;
    }

    public function cancelShipment(string $awb): bool
    {
        $response = $this->client()->post('/orders/cancel/shipment/awbs', [
            'awbs' => [$awb],
        ]);

        return $response->successful();
    }

    public function track(string $awb): TrackingStatusDto
    {
        $response = $this->client()->get("/courier/track/awb/{$awb}");

        if (! $response->successful()) {
            throw new DomainException("Shiprocket tracking query failed for {$awb}: ".$response->body());
        }

        $data = $response->json();
        $trackingData = $data['tracking_data'] ?? [];
        $numericStatusCode = (int) ($trackingData['shipment_status'] ?? 0);

        $tracks = $trackingData['shipment_track'] ?? [];
        $latestTrack = $tracks[0] ?? [];

        $rawStatusString = (string) ($latestTrack['current_status'] ?? '');
        $courierName = $latestTrack['courier_name'] ?? null;
        $location = $latestTrack['destination'] ?? $latestTrack['origin'] ?? null;
        $edd = ! empty($latestTrack['edd']) ? Carbon::parse($latestTrack['edd']) : null;
        $pickupDate = ! empty($latestTrack['pickup_date']) ? Carbon::parse($latestTrack['pickup_date']) : null;

        $activities = $trackingData['shipment_track_activities'] ?? [];
        $latestActivity = $activities[0] ?? null;
        $description = $latestActivity['activity'] ?? $rawStatusString;
        $occurredAt = ! empty($latestActivity['date']) ? Carbon::parse($latestActivity['date']) : now();

        $status = $this->mapShiprocketStatus($numericStatusCode, $rawStatusString);

        return new TrackingStatusDto(
            awb: $awb,
            status: $status ?? ShipmentStatus::InTransit,
            providerStatus: $rawStatusString ?: (string) $numericStatusCode,
            providerNdrStatus: $numericStatusCode === 21 || $numericStatusCode === 40 ? 'NDR' : null,
            location: $location,
            description: $description,
            occurredAt: $occurredAt,
            estimatedDeliveryAt: $edd,
            pickedUpAt: $pickupDate,
            rawResponse: $data,
        );
    }

    /**
     * Map Shiprocket status code/label to normalized domain ShipmentStatus enum.
     * Returns null if status causes no domain transition (e.g. Code 16 Cancellation Requested).
     */
    public function mapShiprocketStatus(int $code, string $label = ''): ?ShipmentStatus
    {
        // 1. Numeric code mapping verified against Postman collection tables
        return match ($code) {
            // Created
            1, 2, 4, 5, 11, 27, 52 => ShipmentStatus::Created,

            // Pickup Scheduled
            3, 15, 19 => ShipmentStatus::PickupScheduled,

            // Picked Up
            42, 51 => ShipmentStatus::PickedUp,

            // In Transit
            6, 18, 22, 38, 48, 49, 50 => ShipmentStatus::InTransit,

            // Out For Delivery
            17 => ShipmentStatus::OutForDelivery,

            // Delivered
            7 => ShipmentStatus::Delivered,

            // Delivery Failed (NDR)
            21, 40 => ShipmentStatus::DeliveryFailed,

            // RTO Initiated
            9, 14, 41, 46 => ShipmentStatus::RtoInitiated,

            // RTO Delivered
            10 => ShipmentStatus::RtoDelivered,

            // Cancelled
            8, 45 => ShipmentStatus::Cancelled,

            // Lost
            12, 44 => ShipmentStatus::Lost,

            // Exceptions (including 23 Partial_Delivered, 26 Fulfilled, and 43 Self Fulfilled per admin review rules)
            13, 20, 23, 24, 25, 26, 39, 43, 47 => ShipmentStatus::Exception,

            // Cancellation Requested -> NO STATUS CHANGE
            16 => null,

            // Fallback to text label matching if code is 0 or unmapped
            default => $this->mapLabelFallback($label),
        };
    }

    /**
     * Fallback mapping based on string labels.
     */
    protected function mapLabelFallback(string $label): ?ShipmentStatus
    {
        $normalized = strtoupper(trim($label));

        return match ($normalized) {
            'DELIVERED' => ShipmentStatus::Delivered,
            'OUT FOR DELIVERY', 'OFD' => ShipmentStatus::OutForDelivery,
            'SHIPPED', 'IN TRANSIT', 'IN_TRANSIT', 'DELAYED' => ShipmentStatus::InTransit,
            'PICKED UP', 'PICKED_UP' => ShipmentStatus::PickedUp,
            'PICKUP SCHEDULED', 'PICKUP_SCHEDULED', 'PICKUP RESCHEDULED', 'OUT FOR PICKUP' => ShipmentStatus::PickupScheduled,
            'UNDELIVERED', 'NDR', 'RTO_NDR' => ShipmentStatus::DeliveryFailed,
            'RTO INITIATED', 'RTO_INITIATED', 'RTO ACKNOWLEDGED', 'RTO OFD', 'RTO IN INTRANSIT' => ShipmentStatus::RtoInitiated,
            'RTO DELIVERED', 'RTO_DELIVERED' => ShipmentStatus::RtoDelivered,
            'CANCELLED', 'CANCELED', 'CANCELLED_BEFORE_DISPATCHED' => ShipmentStatus::Cancelled,
            'CANCELLATION REQUESTED', 'CANCELLATION_REQUESTED' => null, // No status change
            'LOST', 'DISPOSED OFF', 'DISPOSED_OFF' => ShipmentStatus::Lost,
            'PARTIAL_DELIVERED', 'PARTIAL DELIVERED', 'SELF FULFILLED', 'SELF_FULFILLED', 'FULFILLED', 'DESTROYED', 'DAMAGED', 'PICKUP ERROR', 'PICKUP EXCEPTION', 'MISROUTED', 'QC FAILED' => ShipmentStatus::Exception,
            'AWB ASSIGNED', 'LABEL GENERATED', 'PENDING', 'NEW', 'READY TO SHIP' => ShipmentStatus::Created,
            default => ShipmentStatus::InTransit,
        };
    }
}
