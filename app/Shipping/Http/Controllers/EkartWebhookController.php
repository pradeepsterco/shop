<?php

namespace App\Shipping\Http\Controllers;

use App\Shipping\Contracts\ShipmentServiceInterface;
use App\Shipping\Drivers\EkartCarrierDriver;
use App\Shipping\DTOs\TrackingStatusDto;
use App\Shipping\Models\Shipment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EkartWebhookController
{
    public function __construct(
        protected ShipmentServiceInterface $shipmentService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $headerName = config('shipping.ekart.webhook_header');
        $algorithm = config('shipping.ekart.webhook_algorithm');
        $secret = config('shipping.ekart.webhook_secret');

        // If webhook header or algorithm are not configured, reject incoming webhooks
        if (empty($headerName) || empty($algorithm) || empty($secret)) {
            Log::info('Ekart webhook rejected: webhook verification not configured, relying on polling');

            return response()->json([
                'status' => false,
                'message' => 'Webhooks not configured — using polling.',
            ], 403);
        }

        $signature = $request->header($headerName);
        if (empty($signature)) {
            return response()->json([
                'status' => false,
                'message' => 'Missing webhook signature header.',
            ], 401);
        }

        $computed = hash_hmac($algorithm, $request->getContent(), $secret);
        if (! hash_equals($computed, $signature)) {
            Log::warning('Ekart webhook signature mismatch', [
                'header' => $headerName,
                'algorithm' => $algorithm,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        $payload = $request->json()->all();
        $trackingId = $payload['id'] ?? $payload['tracking_id'] ?? null;
        $wbn = $payload['wbn'] ?? null;

        if (empty($trackingId) && empty($wbn)) {
            return response()->json([
                'status' => false,
                'message' => 'Payload missing tracking id or waybill.',
            ], 400);
        }

        /** @var Shipment|null $shipment */
        $shipment = Shipment::where('awb', $trackingId)
            ->orWhere('vendor_wbn', $wbn)
            ->first();

        if (! $shipment) {
            return response()->json([
                'status' => true,
                'message' => 'Shipment not found in local system.',
            ], 200);
        }

        $ekartDriver = new EkartCarrierDriver;
        $swiftStatus = (string) ($payload['status'] ?? 'In Transit');
        $domainStatus = $ekartDriver->mapSwiftStatus($swiftStatus);

        $occurredAt = ! empty($payload['ctime']) ? Carbon::createFromTimestampMs($payload['ctime']) : null;
        $edd = ! empty($payload['edd']) ? Carbon::createFromTimestampMs($payload['edd']) : null;
        $pickedUpAt = ! empty($payload['pickupTime']) ? Carbon::createFromTimestampMs($payload['pickupTime']) : null;

        $trackingDto = new TrackingStatusDto(
            awb: $shipment->awb ?? (string) $trackingId,
            status: $domainStatus,
            providerStatus: $swiftStatus,
            providerNdrStatus: $payload['ndrStatus'] ?? null,
            location: $payload['location'] ?? null,
            description: $payload['desc'] ?? null,
            occurredAt: $occurredAt,
            estimatedDeliveryAt: $edd,
            pickedUpAt: $pickedUpAt,
            rawResponse: $payload,
        );

        $this->shipmentService->recordShipmentEvent($shipment, $trackingDto);

        return response()->json([
            'status' => true,
            'message' => 'Webhook processed successfully.',
        ], 200);
    }
}
