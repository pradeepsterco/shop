<?php

use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Contracts\SellerOrderFulfillmentInterface;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\SellerInvoiceSequence;
use App\Orders\Models\SellerOrder;
use App\Orders\Services\SellerOrderFulfillmentService;
use Carbon\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    Carbon::setTestNow('2026-10-25 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function createTestSellerOrder(int $sellerProfileId, int $grandTotalPaise = 50000): SellerOrder
{
    $user = User::factory()->create();

    $order = Order::create([
        'order_number' => 'KH-'.date('Ymd').'-'.strtoupper(Str::random(6)),
        'user_id' => $user->id,
        'checkout_intent_id' => (string) Str::ulid(),
        'status' => OrderStatus::Placed,
        'delivery_address' => [
            'name' => 'Test Buyer',
            'phone' => '9876543210',
            'address_line' => '123 MG Road',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560001',
        ],
        'delivery_state' => 'Karnataka',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Pending,
        'items_mrp_total' => $grandTotalPaise,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => $grandTotalPaise,
        'placed_at' => now(),
    ]);

    return SellerOrder::create([
        'order_id' => $order->id,
        'seller_profile_id' => $sellerProfileId,
        'seller_store_name' => 'Seller Store '.$sellerProfileId,
        'status' => SellerOrderStatus::Packed,
        'subtotal' => $grandTotalPaise,
        'discount_total' => 0,
        'grand_total' => $grandTotalPaise,
        'placed_at' => now(),
    ]);
}

test('calculates Indian financial year correctly with April 1 cutoff in Asia/Kolkata', function () {
    // October 25, 2026 -> FY 2026-27 (2627)
    $octDate = Carbon::parse('2026-10-25 15:30:00', 'Asia/Kolkata');
    expect(SellerOrderFulfillmentService::calculateFinancialYear($octDate))->toBe('2627');

    // March 31, 2027 23:59:59 -> Still FY 2026-27 (2627)
    $marDate = Carbon::parse('2027-03-31 23:59:59', 'Asia/Kolkata');
    expect(SellerOrderFulfillmentService::calculateFinancialYear($marDate))->toBe('2627');

    // April 1, 2027 00:00:00 -> Rollover to FY 2027-28 (2728)
    $aprDate = Carbon::parse('2027-04-01 00:00:00', 'Asia/Kolkata');
    expect(SellerOrderFulfillmentService::calculateFinancialYear($aprDate))->toBe('2728');
});

test('generates GST compliant invoice number with format S{seller}/{FY}/{seq} and 16 char max', function () {
    $seller = SellerProfile::factory()->create();
    $sellerOrder = createTestSellerOrder($seller->id);

    $service = app(SellerOrderFulfillmentInterface::class);
    $dto = $service->getOrCreateInvoiceNumber($sellerOrder->id);

    expect($dto->invoiceNumber)->toBe("S{$seller->id}/2627/00001")
        ->and(strlen($dto->invoiceNumber))->toBeLessThanOrEqual(16)
        ->and(preg_match('/^[A-Za-z0-9\-\/]+$/', $dto->invoiceNumber))->toBe(1);

    // Assert written to seller_orders
    $sellerOrder->refresh();
    expect($sellerOrder->invoice_number)->toBe("S{$seller->id}/2627/00001")
        ->and($sellerOrder->invoice_date)->not->toBeNull();
});

test('subsequent orders for the same seller in the same FY increment sequence gap-free', function () {
    $seller = SellerProfile::factory()->create();
    $order1 = createTestSellerOrder($seller->id);
    $order2 = createTestSellerOrder($seller->id);

    $service = app(SellerOrderFulfillmentInterface::class);

    $dto1 = $service->getOrCreateInvoiceNumber($order1->id);
    $dto2 = $service->getOrCreateInvoiceNumber($order2->id);

    expect($dto1->invoiceNumber)->toBe("S{$seller->id}/2627/00001")
        ->and($dto2->invoiceNumber)->toBe("S{$seller->id}/2627/00002");

    $seq = SellerInvoiceSequence::where('seller_profile_id', $seller->id)
        ->where('financial_year', '2627')
        ->first();

    expect($seq->last_sequence)->toBe(2);
});

test('invoice sequence rolls over to 1 on April 1 for the new financial year', function () {
    $seller = SellerProfile::factory()->create();
    $service = app(SellerOrderFulfillmentInterface::class);

    // On 31 March 2027
    Carbon::setTestNow('2027-03-31 18:00:00');
    $orderMarch = createTestSellerOrder($seller->id);
    $dtoMarch = $service->getOrCreateInvoiceNumber($orderMarch->id);
    expect($dtoMarch->invoiceNumber)->toBe("S{$seller->id}/2627/00001");

    // On 1 April 2027 -> New FY 2728, sequence starts at 1
    Carbon::setTestNow('2027-04-01 09:00:00');
    $orderApril = createTestSellerOrder($seller->id);
    $dtoApril = $service->getOrCreateInvoiceNumber($orderApril->id);
    expect($dtoApril->invoiceNumber)->toBe("S{$seller->id}/2728/00001");
});

test('fails loudly if generated invoice number would exceed 16 characters', function () {
    // If a seller profile ID is very large such that S{id}/2627/00001 > 16 chars:
    // e.g. sellerProfileId = 12345678 (8 digits) -> "S12345678/2627/00001" is 20 chars!
    $seller = SellerProfile::factory()->create(['id' => 12345678]);
    $sellerOrder = createTestSellerOrder($seller->id);

    $service = app(SellerOrderFulfillmentInterface::class);

    expect(fn () => $service->getOrCreateInvoiceNumber($sellerOrder->id))
        ->toThrow(DomainException::class, 'exceeds maximum allowed GST length of 16 characters');
});
