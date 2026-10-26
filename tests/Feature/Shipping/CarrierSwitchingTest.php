<?php

use App\Shipping\Contracts\CarrierManagerInterface;
use App\Shipping\Contracts\ShipmentServiceInterface;
use App\Shipping\Drivers\EkartCarrierDriver;
use App\Shipping\Drivers\FakeCarrierDriver;
use App\Shipping\Drivers\ManualCarrierDriver;
use App\Shipping\Drivers\ShiprocketCarrierDriver;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\SellerShippingProfile;
use App\Shipping\Models\Shipment;
use Illuminate\Support\Facades\Http;

test('carrier manager resolves drivers correctly and supports runtime switching', function () {
    $manager = app(CarrierManagerInterface::class);

    $ekart = $manager->driver('ekart');
    expect($ekart)->toBeInstanceOf(EkartCarrierDriver::class)
        ->and($ekart->name())->toBe('ekart');

    $shiprocket = $manager->driver('shiprocket');
    expect($shiprocket)->toBeInstanceOf(ShiprocketCarrierDriver::class)
        ->and($shiprocket->name())->toBe('shiprocket');

    $manual = $manager->driver('manual');
    expect($manual)->toBeInstanceOf(ManualCarrierDriver::class)
        ->and($manual->name())->toBe('manual');

    $fake = $manager->driver('fake');
    expect($fake)->toBeInstanceOf(FakeCarrierDriver::class)
        ->and($fake->name())->toBe('fake');
});

test('seller shipping profile supports both ekart and shiprocket concurrently via compound unique', function () {
    $sellerId = 99;

    $ekartProfile = SellerShippingProfile::create([
        'seller_profile_id' => $sellerId,
        'provider' => 'ekart',
        'warehouse_alias' => 'WH-99-EKART',
        'is_registered' => true,
        'registration_status' => 'registered',
        'pincode' => 560001,
        'phone_verified' => true,
    ]);

    $shiprocketProfile = SellerShippingProfile::create([
        'seller_profile_id' => $sellerId,
        'provider' => 'shiprocket',
        'warehouse_alias' => 'WH-99-SHIPROCKET',
        'is_registered' => true,
        'registration_status' => 'registered',
        'pincode' => 560001,
        'phone_verified' => true,
    ]);

    expect($ekartProfile->id)->not->toBeNull()
        ->and($shiprocketProfile->id)->not->toBeNull()
        ->and(SellerShippingProfile::where('seller_profile_id', $sellerId)->count())->toBe(2);
});

test('existing ekart shipments continue using ekart driver for tracking even when shiprocket is active', function () {
    $manager = app(CarrierManagerInterface::class);
    $manager->setActiveDriver('shiprocket');
    expect($manager->getActiveDriverName())->toBe('shiprocket');

    // Create an existing shipment whose provider is 'ekart'
    $ekartShipment = Shipment::create([
        'seller_order_id' => 'so-switch-test',
        'order_id' => 'ord-switch-test',
        'seller_profile_id' => 1,
        'provider' => 'ekart',
        'awb' => 'EKART-TRACK-12345',
        'vendor_wbn' => 'WBN-EKART',
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

    // Mock Ekart tracking endpoint
    Http::fake([
        'https://app.elite.ekartlogistics.in/integrations/v2/auth/token/*' => Http::response(['access_token' => 'ekart-token'], 200),
        'https://app.elite.ekartlogistics.in/api/v1/track/EKART-TRACK-12345' => Http::response([
            'status' => true,
            'track' => [
                'status' => 'In Transit',
                'location' => 'Bengaluru Hub',
                'desc' => 'Shipment is moving',
            ],
        ], 200),
    ]);

    /** @var ShipmentServiceInterface $shipmentService */
    $shipmentService = app(ShipmentServiceInterface::class);
    $shipmentService->syncTracking($ekartShipment);

    // Verified: Ekart tracking API was hit (not Shiprocket)
    Http::assertSent(function ($req) {
        return str_contains($req->url(), '/api/v1/track/EKART-TRACK-12345');
    });

    expect($ekartShipment->fresh()->status)->toBe(ShipmentStatus::InTransit);
});
