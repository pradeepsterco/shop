<?php

use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use App\Shipping\Models\ShipmentEvent;

beforeEach(function () {
    config([
        'shipping.shiprocket.webhook_token' => 'test-valid-shiprocket-secret',
    ]);
});

test('rejects webhook with 403 when webhook token is not configured', function () {
    config(['shipping.shiprocket.webhook_token' => null]);

    $response = $this->postJson('/webhooks/shipping/shiprocket', [
        'awb' => 'SR-123456789',
        'current_status' => 'DELIVERED',
    ], ['x-api-key' => 'any-token']);

    $response->assertStatus(403)
        ->assertJson([
            'status' => false,
            'message' => 'Webhooks not configured — using polling.',
        ]);
});

test('rejects webhook with 401 when x-api-key header is missing or incorrect', function () {
    // Missing header
    $this->postJson('/webhooks/shipping/shiprocket', ['awb' => 'SR-123456789'])
        ->assertStatus(401)
        ->assertJson([
            'status' => false,
            'message' => 'Invalid webhook token.',
        ]);

    // Wrong header
    $this->postJson('/webhooks/shipping/shiprocket', ['awb' => 'SR-123456789'], ['x-api-key' => 'wrong-secret'])
        ->assertStatus(401)
        ->assertJson([
            'status' => false,
            'message' => 'Invalid webhook token.',
        ]);
});

test('returns 200 when shipment is not found in local system', function () {
    $response = $this->postJson('/webhooks/shipping/shiprocket', [
        'awb' => 'SR-NONEXISTENT',
        'current_status' => 'DELIVERED',
    ], ['x-api-key' => 'test-valid-shiprocket-secret']);

    $response->assertStatus(200)
        ->assertJson([
            'status' => true,
            'message' => 'Shipment not found in local system.',
        ]);
});

test('persists raw payload to shipment event with provider_status webhook_received leaving shipment status unchanged', function () {
    $shipment = Shipment::create([
        'seller_order_id' => 'so-webhook-1',
        'order_id' => 'ord-webhook-1',
        'seller_profile_id' => 1,
        'provider' => 'shiprocket',
        'courier_name' => 'Delhivery',
        'awb' => 'SR-AWB-WEBHOOK-1',
        'vendor_wbn' => 'WBN-123',
        'fulfillment_attempt' => 1,
        'creation_step' => 'label_ready',
        'status' => ShipmentStatus::InTransit,
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 300,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
        'is_cod' => false,
        'cod_amount_paise' => 0,
    ]);

    $payload = [
        'awb' => 'SR-AWB-WEBHOOK-1',
        'current_status' => 'DELIVERED',
        'shipment_status_id' => 7,
        'location' => 'Bengaluru Hub',
        'scans' => [
            ['activity' => 'Delivered to recipient', 'date' => '2026-10-25 18:00:00'],
        ],
    ];

    $response = $this->postJson('/webhooks/shipping/shiprocket', $payload, [
        'x-api-key' => 'test-valid-shiprocket-secret',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'status' => true,
            'message' => 'Webhook received and stored.',
        ]);

    // Verify raw payload persisted in shipment_events
    $event = ShipmentEvent::where('shipment_id', $shipment->id)->first();
    expect($event)->not->toBeNull()
        ->and($event->provider)->toBe('shiprocket')
        ->and($event->provider_status)->toBe('webhook_received')
        ->and($event->raw_payload)->toBe($payload);

    // Shipment status remains InTransit (no status mapping until real sample payloads provided)
    expect($shipment->fresh()->status)->toBe(ShipmentStatus::InTransit);
});

test('deduplicates duplicate webhooks idempotently producing only one event', function () {
    $shipment = Shipment::create([
        'seller_order_id' => 'so-webhook-dedup',
        'order_id' => 'ord-webhook-dedup',
        'seller_profile_id' => 1,
        'provider' => 'shiprocket',
        'courier_name' => 'Delhivery',
        'awb' => 'SR-AWB-DEDUP',
        'vendor_wbn' => 'WBN-DEDUP',
        'fulfillment_attempt' => 1,
        'creation_step' => 'label_ready',
        'status' => ShipmentStatus::InTransit,
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 300,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
        'is_cod' => false,
        'cod_amount_paise' => 0,
    ]);

    $payload = [
        'awb' => 'SR-AWB-DEDUP',
        'current_status' => 'IN TRANSIT',
        'timestamp' => 1730000000,
    ];

    $headers = ['x-api-key' => 'test-valid-shiprocket-secret'];

    // Send 1st webhook
    $res1 = $this->postJson('/webhooks/shipping/shiprocket', $payload, $headers);
    $res1->assertStatus(200);

    // Send duplicate 2nd webhook
    $res2 = $this->postJson('/webhooks/shipping/shiprocket', $payload, $headers);
    $res2->assertStatus(200);

    // Only 1 event recorded
    $count = ShipmentEvent::where('shipment_id', $shipment->id)->count();
    expect($count)->toBe(1);
});
