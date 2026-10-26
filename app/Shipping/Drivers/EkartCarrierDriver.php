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
use Carbon\Carbon;
use DomainException;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EkartCarrierDriver implements ShippingCarrierInterface
{
    protected string $baseUrl;

    protected string $clientId;

    protected string $username;

    protected string $password;

    protected string $service;

    protected int $timeoutSeconds;

    protected int $retryAttempts;

    protected bool $safeMode;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? config('shipping.ekart.base_url', 'https://app.elite.ekartlogistics.in')), '/');
        $this->clientId = (string) ($config['client_id'] ?? config('shipping.ekart.client_id', ''));
        $this->username = (string) ($config['username'] ?? config('shipping.ekart.username', ''));
        $this->password = (string) ($config['password'] ?? config('shipping.ekart.password', ''));
        $this->service = (string) ($config['service'] ?? config('shipping.ekart.service', 'SURFACE'));
        $this->timeoutSeconds = (int) ($config['timeout_seconds'] ?? config('shipping.ekart.timeout_seconds', 15));
        $this->retryAttempts = (int) ($config['retry_attempts'] ?? config('shipping.ekart.retry_attempts', 3));
        $this->safeMode = (bool) (array_key_exists('safe_mode', $config) ? $config['safe_mode'] : config('shipping.safe_mode', true));
    }

    public function name(): string
    {
        return 'ekart';
    }

    /**
     * Get or fetch cached OAuth Bearer token.
     */
    public function getAccessToken(): string
    {
        if (empty($this->clientId)) {
            return 'fake-ekart-token';
        }

        $cacheKey = "ekart_auth_token_{$this->clientId}";

        return Cache::remember($cacheKey, now()->addHours(20), function () {
            $url = "{$this->baseUrl}/integrations/v2/auth/token/{$this->clientId}";

            $response = $this->baseRequest()
                ->retry($this->retryAttempts, 200, throw: false)
                ->post($url, [
                    'username' => $this->username,
                    'password' => $this->password,
                ]);

            if (! $response->successful()) {
                Log::error('Ekart authentication failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new DomainException('Ekart carrier authentication failed: '.$response->body());
            }

            $data = $response->json();
            $token = $data['access_token'] ?? null;
            if (! $token) {
                throw new DomainException('Ekart authentication returned empty access_token.');
            }

            return (string) $token;
        });
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

        try {
            $response = $this->client()->get("/api/v2/serviceability/{$cleanPincode}");

            if (! $response->successful()) {
                return new ServiceabilityResultDto(
                    serviceable: false,
                    codAvailable: false,
                    maxCodAmountPaise: 0,
                    errorMessage: 'Carrier serviceability check failed: '.$response->body(),
                );
            }

            $data = $response->json();
            $status = (bool) ($data['status'] ?? false);
            $details = $data['details'] ?? [];

            $cod = (bool) ($details['cod'] ?? false);
            $maxCodInr = (int) ($details['max_cod_amount'] ?? 0);
            $maxCodPaise = $maxCodInr * 100;
            $city = $details['city'] ?? null;
            $state = $details['state'] ?? null;

            return new ServiceabilityResultDto(
                serviceable: $status,
                codAvailable: $status && $cod,
                maxCodAmountPaise: $maxCodPaise,
                city: $city,
                state: $state,
                tatMinDays: 2,
                tatMaxDays: 5,
                rawResponse: $data,
            );
        } catch (Exception $e) {
            Log::warning('Ekart serviceability exception', ['error' => $e->getMessage()]);

            return new ServiceabilityResultDto(
                serviceable: true,
                codAvailable: true,
                maxCodAmountPaise: 4999900,
                errorMessage: $e->getMessage(),
            );
        }
    }

    public function registerPickupAddress(SellerPickupAddressDto $address): SellerWarehouseRegistrationDto
    {
        $alias = 'WH-'.$address->sellerProfileId;
        $cleanPhone = (int) preg_replace('/\D/', '', $address->phone);
        $cleanPincode = (int) preg_replace('/\D/', '', $address->postalCode);

        $payload = [
            'alias' => $alias,
            'phone' => $cleanPhone ?: 9876543210,
            'address_line1' => substr($address->addressLine1, 0, 100),
            'address_line2' => $address->addressLine2 ? substr($address->addressLine2, 0, 100) : null,
            'pincode' => $cleanPincode,
            'city' => $address->city,
            'state' => $address->state,
            'country' => 'India',
        ];

        try {
            $response = $this->client()->post('/api/v2/address', $payload);

            if (! $response->successful()) {
                return new SellerWarehouseRegistrationDto(
                    success: false,
                    alias: $alias,
                    message: $response->body(),
                    rawResponse: $response->json(),
                );
            }

            $data = $response->json();
            $success = (bool) ($data['status'] ?? false);

            return new SellerWarehouseRegistrationDto(
                success: $success,
                alias: $alias,
                message: $data['remark'] ?? ($success ? 'Warehouse registered successfully' : 'Failed'),
                rawResponse: $data,
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
            $response = $this->client()->get('/api/v2/addresses');

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();

            return is_array($data) ? $data : [];
        } catch (Exception $e) {
            Log::warning('Ekart getRegisteredAddresses error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function createShipment(
        CreateShipmentRequestDto $request,
        SellerOrderShippingDetailsDto $details,
        SellerOrderInvoiceDto $invoice,
    ): CreateShipmentResponseDto {
        if ($this->safeMode) {
            throw new DomainException('Shipping safe mode is active. Live shipment creation is blocked.');
        }

        $totalAmountInr = round($details->grandTotalPaise / 100, 2);
        $taxableAmountInr = $totalAmountInr;
        $taxValueInr = 0.0;

        $itemsSummary = [];
        $totalQty = 0;
        foreach ($details->items as $item) {
            $itemsSummary[] = "{$item->title} (x{$item->quantity})";
            $totalQty += $item->quantity;
            $taxValueInr += round($item->taxAmountPaise / 100, 2);
        }
        $taxableAmountInr = max(1, round($totalAmountInr - $taxValueInr, 2));

        $codAmountInr = $details->isCod ? min(49999, round($details->codAmountPaise / 100, 2)) : 0;
        $alias = $request->pickupLocationAlias ?: ('WH-'.$details->sellerProfileId);

        $customerPhone = preg_replace('/\D/', '', $details->customerPhone);
        $altPhone = $details->customerAlternatePhone ? preg_replace('/\D/', '', $details->customerAlternatePhone) : null;
        if (empty($altPhone) || strlen($altPhone) < 10) {
            $altPhone = strlen($customerPhone) >= 10 ? $customerPhone : '9876543210';
        }

        $payload = [
            'seller_name' => substr($details->sellerStoreName, 0, 100),
            'seller_address' => substr((string) ($details->sellerPickupAddress1 ?: 'Registered Warehouse'), 0, 100),
            'seller_gst_tin' => $details->sellerGstin ?: 'URP',
            'seller_gst_amount' => $taxValueInr,
            'consignee_gst_amount' => 0,
            'order_number' => $details->orderNumber,
            'invoice_number' => $invoice->invoiceNumber,
            'invoice_date' => $invoice->invoiceDate->format('Y-m-d'),
            'consignee_name' => substr($details->customerName, 0, 100),
            'consignee_alternate_phone' => substr($altPhone, -10),
            'payment_mode' => $details->isCod ? 'COD' : 'Prepaid',
            'category_of_goods' => 'Toys & Games',
            'products_desc' => substr(implode(', ', $itemsSummary), 0, 150),
            'total_amount' => max(1, $totalAmountInr),
            'cod_amount' => $codAmountInr,
            'taxable_amount' => max(1, $taxableAmountInr),
            'commodity_value' => (string) max(1, $taxableAmountInr),
            'tax_value' => $taxValueInr,
            'return_reason' => '',
            'quantity' => max(1, $totalQty),
            'weight' => max(50, $request->actualWeightGrams),
            'length' => max(1, $request->lengthCm),
            'width' => max(1, $request->widthCm),
            'height' => max(1, $request->heightCm),
            'service' => $this->service,
            'delayed_dispatch' => $request->delayedDispatch,
            'drop_location' => [
                'name' => substr($details->customerName, 0, 100),
                'phone' => (int) substr($customerPhone, -10) ?: 9876543210,
                'address_line1' => substr($details->customerAddress1, 0, 100),
                'address_line2' => $details->customerAddress2 ? substr($details->customerAddress2, 0, 100) : null,
                'pincode' => (int) preg_replace('/\D/', '', $details->customerPostalCode),
                'city' => $details->customerCity,
                'state' => $details->customerState,
                'country' => 'India',
            ],
            'pickup_location' => [
                'name' => $alias,
            ],
            'return_location' => [
                'name' => $alias,
            ],
        ];

        if (! $request->delayedDispatch && ! empty($request->preferredDispatchDate)) {
            $payload['preferred_dispatch_date'] = $request->preferredDispatchDate;
        }

        $response = $this->client()->put('/api/v1/package/create', $payload);

        if (! $response->successful()) {
            return new CreateShipmentResponseDto(
                success: false,
                awb: null,
                vendorWbn: null,
                publicTrackingUrl: null,
                errorMessage: 'Ekart package creation failed: '.$response->body(),
                rawResponse: $response->json(),
            );
        }

        $data = $response->json();
        $trackingId = $data['tracking_id'] ?? null;
        $vendorWbn = $data['barcodes']['wbn'] ?? null;
        $publicUrl = $trackingId ? "{$this->baseUrl}/track/{$trackingId}" : null;

        return new CreateShipmentResponseDto(
            success: true,
            awb: $trackingId,
            vendorWbn: $vendorWbn,
            publicTrackingUrl: $publicUrl,
            estimatedChargesPaise: 0,
            rawResponse: $data,
        );
    }

    public function downloadLabel(string $awb): string
    {
        $response = $this->client()->post('/api/v1/package/label?json_only=false', [
            'ids' => [$awb],
        ]);

        if (! $response->successful()) {
            throw new DomainException('Ekart label download failed: '.$response->body());
        }

        return $response->body();
    }

    public function setDispatchDate(array $awbs, string $date): bool
    {
        $response = $this->client()->post('/data/shipment/dispatch-date', [
            'ids' => $awbs,
            'dispatchDate' => $date,
        ]);

        return $response->successful();
    }

    public function cancelShipment(string $awb): bool
    {
        $response = $this->client()->delete('/api/v1/package/cancel', [
            'tracking_id' => $awb,
        ]);

        return $response->successful();
    }

    /**
     * Register a webhook endpoint with Ekart (POST /api/v2/webhook).
     *
     * @param  array<int, string>  $topics
     */
    public function registerWebhook(string $url, array $topics = ['track_updated']): bool
    {
        $header = config('shipping.ekart.webhook_header');
        $algo = config('shipping.ekart.webhook_algorithm');
        $secret = config('shipping.ekart.webhook_secret');

        if (empty($header) || empty($algo) || empty($secret)) {
            throw new DomainException('Cannot register Ekart webhook: Webhook header, algorithm, and secret are not configured. Relying on polling.');
        }

        $response = $this->client()->post('/api/v2/webhook', [
            'url' => $url,
            'secret' => $secret,
            'topics' => $topics,
        ]);

        return $response->successful();
    }

    public function track(string $awb): TrackingStatusDto
    {
        $response = $this->client()->get("/api/v1/track/{$awb}");

        if (! $response->successful()) {
            throw new DomainException("Ekart tracking query failed for {$awb}: ".$response->body());
        }

        $data = $response->json();
        $track = $data['track'] ?? [];
        $providerStatus = (string) ($track['status'] ?? 'Created');
        $providerNdrStatus = $track['ndrStatus'] ?? null;
        $location = $track['location'] ?? null;
        $description = $track['desc'] ?? null;

        $occurredAt = ! empty($track['ctime']) ? Carbon::createFromTimestampMs($track['ctime']) : now();
        $edd = ! empty($data['edd']) ? Carbon::createFromTimestampMs($data['edd']) : null;
        $pickedUpAt = ! empty($track['pickupTime']) ? Carbon::createFromTimestampMs($track['pickupTime']) : null;

        $status = $this->mapSwiftStatus($providerStatus);

        return new TrackingStatusDto(
            awb: $awb,
            status: $status,
            providerStatus: $providerStatus,
            providerNdrStatus: $providerNdrStatus,
            location: $location,
            description: $description,
            occurredAt: $occurredAt,
            estimatedDeliveryAt: $edd,
            pickedUpAt: $pickedUpAt,
            rawResponse: $data,
        );
    }

    /**
     * Map Ekart swift_status to domain ShipmentStatus enum.
     */
    public function mapSwiftStatus(string $swiftStatus): ShipmentStatus
    {
        return match ($swiftStatus) {
            'Delivered' => ShipmentStatus::Delivered,
            'Out for Delivery' => ShipmentStatus::OutForDelivery,
            'In Transit', 'Shipment Delayed', 'Out for Pickup' => ShipmentStatus::InTransit,
            'Picked Up' => ShipmentStatus::PickedUp,
            'Pickup Scheduled', 'Pickup Pending' => ShipmentStatus::PickupScheduled,
            'Undelivered' => ShipmentStatus::DeliveryFailed,
            'RTO Requested', 'Seller RTO Requested', 'RTO In Transit', 'RTO Out for Delivery', 'RTO Shipment Delayed' => ShipmentStatus::RtoInitiated,
            'RTO Delivered' => ShipmentStatus::RtoDelivered,
            'Cancelled', 'Seller Cancelled', 'Pickup Cancelled' => ShipmentStatus::Cancelled,
            'Lost' => ShipmentStatus::Lost,
            'Not Picked', 'Not Serviceable', 'Damaged' => ShipmentStatus::Exception,
            'Order Placed' => ShipmentStatus::Created,
            default => ShipmentStatus::InTransit,
        };
    }
}
