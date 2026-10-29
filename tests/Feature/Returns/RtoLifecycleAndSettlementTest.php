<?php

use App\Models\Admin;
use App\Models\Listing;
use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Events\SellerOrderReturnedToOrigin;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\SellerOrder;
use App\Returns\Contracts\ReturnsLookupInterface;
use App\Returns\Enums\RtoAdminDecision;
use App\Returns\Enums\RtoConditionStatus;
use App\Settlements\Contracts\SellerLedgerServiceInterface;
use App\Settlements\Enums\LedgerEntryType;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('permissions:sync');

    $this->admin = Admin::factory()->create();
    $role = Role::findOrCreate('Super Admin', 'admin');
    $this->admin->assignRole($role);

    $this->sellerUser = User::factory()->create();
    $this->sellerProfile = SellerProfile::factory()->approved()->create(['user_id' => $this->sellerUser->id]);

    $this->customer = User::factory()->create();
    $this->order = Order::create([
        'order_number' => 'ORD-RTO-TEST-1',
        'user_id' => $this->customer->id,
        'checkout_intent_id' => (string) Str::ulid(),
        'status' => OrderStatus::Placed,
        'delivery_address' => [
            'name' => 'Buyer',
            'phone' => '9876543210',
            'address_line' => '123 Test St',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560001',
        ],
        'delivery_state' => 'Karnataka',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Pending,
        'items_mrp_total' => 100000,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => 100000,
        'placed_at' => now(),
    ]);

    $this->listing = Listing::factory()->create([
        'seller_profile_id' => $this->sellerProfile->id,
        'stock' => 10,
        'selling_price' => 100000,
    ]);

    $this->sellerOrder = SellerOrder::create([
        'order_id' => $this->order->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'seller_store_name' => $this->sellerProfile->store_name,
        'status' => SellerOrderStatus::Shipped,
        'subtotal' => 100000,
        'discount_total' => 0,
        'grand_total' => 100000,
        'placed_at' => now(),
        'fee_snapshot' => [
            'commission_fee' => 10000,
            'closing_fee' => 2000,
            'shipping_fee' => 5000,
            'payment_gateway_fee' => 2000,
            'gst_on_fees' => 3420,
            'tds_194o' => 1000,
            'tcs_gst' => 1000,
            'net_payout' => 76580,
        ],
    ]);

    $this->orderItem = OrderItem::create([
        'seller_order_id' => $this->sellerOrder->id,
        'listing_id' => $this->listing->id,
        'product_variant_id' => $this->listing->product_variant_id,
        'sku' => 'SKU-TOY-1',
        'upid' => 'UPID-TOY-1',
        'seller_name' => $this->sellerProfile->store_name,
        'title' => 'Test Toy',
        'mrp' => 100000,
        'qty' => 1,
        'unit_price' => 100000,
        'line_total' => 100000,
    ]);
});

test('rto settlement reverses sale and fees, retains forward shipping, debits rto shipping, and carries net negative balance', function () {
    config(['returns.rto_shipping_multiplier' => 1.5]);

    $settlement = SellerOrderSettlement::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'status' => SettlementStatus::Pending,
        'gross_amount' => 100000,
        'total_deductions' => 23420,
        'net_payout' => 76580,
        'claimable_credits' => 0,
        'return_window_days' => 7,
        'hold_until' => now()->addDays(7),
        'is_partial_delivery_hold' => false,
    ]);

    SellerLedgerEntry::create([
        'seller_profile_id' => $this->sellerProfile->id,
        'seller_order_id' => $this->sellerOrder->id,
        'order_item_id' => $this->orderItem->id,
        'type' => LedgerEntryType::Sale,
        'amount' => 100000,
        'description' => 'Item sale credit',
        'source_key' => "test:sale:{$this->sellerOrder->id}",
        'created_at' => now(),
    ]);

    SellerLedgerEntry::create([
        'seller_profile_id' => $this->sellerProfile->id,
        'seller_order_id' => $this->sellerOrder->id,
        'order_item_id' => $this->orderItem->id,
        'type' => LedgerEntryType::ShippingFee,
        'amount' => -5000,
        'description' => 'Forward shipping fee',
        'source_key' => "test:shipping:{$this->sellerOrder->id}",
        'created_at' => now(),
    ]);

    expect($settlement)->not->toBeNull()
        ->and($settlement->status)->toBe(SettlementStatus::Pending);

    // Dispatch RTO delivery event
    event(new SellerOrderReturnedToOrigin(
        sellerOrderId: $this->sellerOrder->id,
        orderId: $this->order->id,
        sellerProfileId: (int) $this->sellerProfile->id,
        reason: 'Customer rejected delivery / returned to origin',
    ));

    $freshSettlement = $settlement->fresh();

    // RTO settlement must NOT end as Void with uncollected charges!
    expect($freshSettlement->status)->toBe(SettlementStatus::Eligible)
        ->and($freshSettlement->status)->not->toBe(SettlementStatus::Void);

    // Forward shipping was 5000 paise. Multiplier is 1.5 => RTO shipping is 7500 paise.
    // Total shipping debit = 5000 + 7500 = 12500 paise.
    expect($freshSettlement->net_payout)->toBe(-12500);

    // Verify ledger entries
    $rtoEntry = SellerLedgerEntry::where('seller_order_id', $this->sellerOrder->id)
        ->where('source_key', "settlement:{$this->sellerOrder->id}:rto_shipping")
        ->first();

    expect($rtoEntry)->not->toBeNull()
        ->and($rtoEntry->amount)->toBe(-7500);

    // Verify next seller balance carries this negative balance
    /** @var SellerLedgerServiceInterface $ledgerService */
    $ledgerService = app(SellerLedgerServiceInterface::class);
    $balance = $ledgerService->getSellerBalance($this->sellerProfile->id);

    expect($balance->carriedNegativePaise)->toBe(12500);
});

test('seller can submit rto inspection report with condition and notes', function () {
    $this->sellerOrder->update([
        'status' => SellerOrderStatus::ReturnedToOrigin,
        'rto_delivered_at' => now(),
    ]);

    $shipment = Shipment::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake',
        'status' => ShipmentStatus::RtoDelivered,
        'awb' => 'SIM-RTO-DEL',
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    Livewire::actingAs($this->sellerUser)
        ->test('pages::seller.orders-show', ['sellerOrder' => $this->sellerOrder->id])
        ->set('rtoCondition', 'damaged')
        ->set('rtoNotes', 'Outer box crushed and retail seal broken')
        ->call('submitRtoInspection')
        ->assertHasNoErrors()
        ->assertSee('RTO inspection report submitted successfully.');

    $report = app(ReturnsLookupInterface::class)->getRtoReportForSellerOrder($this->sellerOrder->id);

    expect($report)->not->toBeNull()
        ->and($report->conditionStatus)->toBe(RtoConditionStatus::Damaged)
        ->and($report->notes)->toBe('Outer box crushed and retail seal broken')
        ->and($report->adminDecision)->toBe(RtoAdminDecision::Pending);
});

test('admin can restock rto inventory which restores stock and refreshes buy box', function () {
    $this->sellerOrder->update([
        'status' => SellerOrderStatus::ReturnedToOrigin,
        'rto_delivered_at' => now(),
    ]);

    $shipment = Shipment::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake',
        'status' => ShipmentStatus::RtoDelivered,
        'awb' => 'SIM-RTO-RESTOCK',
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    $initialStock = $this->listing->fresh()->stock;

    // Admin restocks
    Livewire::actingAs($this->admin, 'admin')
        ->test('pages::admin.shipments-show', ['shipment' => $shipment->id])
        ->set('rtoNotes', 'Package returned intact, restocking.')
        ->call('submitRtoDecision', 'restocked')
        ->assertHasNoErrors();

    expect($this->listing->fresh()->stock)->toBe($initialStock + 1);
});
