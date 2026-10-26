<?php

namespace App\Shipping\Http\Controllers;

use App\Shipping\Models\Shipment;
use App\Shipping\Models\ShipmentEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShiprocketWebhookController
{
    public function handle(Request $request): JsonResponse
    {
        $expectedToken = config('shipping.shiprocket.webhook_token');

        if (empty($expectedToken)) {
            Log::info('Shiprocket webhook rejected: webhook token not configured, relying on polling');

            return response()->json([
                'status' => false,
                'message' => 'Webhooks not configured — using polling.',
            ], 403);
        }

        $incomingToken = $request->header('x-api-key');
        if (empty($incomingToken) || ! hash_equals($expectedToken, $incomingToken)) {
            Log::warning('Shiprocket webhook rejected: invalid x-api-key header');

            return response()->json([
                'status' => false,
                'message' => 'Invalid webhook token.',
            ], 401);
        }

        $payload = $request->json()->all();
        $awb = (string) ($payload['awb'] ?? $payload['awb_code'] ?? $payload['data']['awb'] ?? $payload['data']['awb_code'] ?? '');
        $shipmentId = (string) ($payload['shipment_id'] ?? $payload['data']['shipment_id'] ?? '');
        $orderId = (string) ($payload['order_id'] ?? $payload['data']['order_id'] ?? '');

        /** @var Shipment|null $shipment */
        $shipment = null;
        if (! empty($awb)) {
            $shipment = Shipment::where('awb', $awb)->first();
        }
        if (! $shipment && ! empty($shipmentId)) {
            $shipment = Shipment::where('provider_shipment_id', $shipmentId)->first();
        }
        if (! $shipment && ! empty($orderId)) {
            $shipment = Shipment::where('provider_order_id', $orderId)->first();
        }

        if (! $shipment) {
            return response()->json([
                'status' => true,
                'message' => 'Shipment not found in local system.',
            ], 200);
        }

        $contentHash = hash('sha256', $request->getContent());
        $eventKey = "shiprocket_webhook:{$shipment->id}:{$contentHash}";

        if (ShipmentEvent::where('event_key', $eventKey)->exists()) {
            return response()->json([
                'status' => true,
                'message' => 'Duplicate webhook event ignored.',
            ], 200);
        }

        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'event_key' => $eventKey,
            'provider' => 'shiprocket',
            'status' => $shipment->status,
            'provider_status' => 'webhook_received',
            'provider_ndr_status' => null,
            'location' => null,
            'description' => 'Raw Shiprocket webhook payload received; status mapping pending sample payloads',
            'occurred_at' => now(),
            'received_at' => now(),
            'raw_payload' => $payload,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Webhook received and stored.',
        ], 200);
    }
}
