<?php

namespace App\Orders\Services;

use App\Mail\SellerNewOrderMail;
use App\Orders\Contracts\OrderRestockInterface;
use App\Orders\Contracts\ReplacementOrderServiceInterface;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\SellerOrder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ReplacementOrderService implements ReplacementOrderServiceInterface
{
    public function __construct(
        protected OrderRestockInterface $inventory,
    ) {}

    public function createReplacement(string $originalSellerOrderId, array $units, string $returnRequestId): string
    {
        $sellerOrder = DB::transaction(function () use ($originalSellerOrderId, $units, $returnRequestId) {
            /** @var SellerOrder $original */
            $original = SellerOrder::with(['order', 'items'])->findOrFail($originalSellerOrderId);
            $parent = $original->order;

            $lines = [];
            foreach ($units as $orderItemId => $count) {
                /** @var OrderItem|null $item */
                $item = $original->items->firstWhere('id', $orderItemId);
                if (! $item) {
                    throw new DomainException("Order item {$orderItemId} does not belong to this order.");
                }

                // Out of stock or inactive listing throws here and rolls back everything
                $this->inventory->reserveStockForReship((int) $item->id, (int) $count);
                $lines[] = [$item, (int) $count];
            }

            $mrpTotal = array_sum(array_map(fn ($line) => (int) $line[0]->mrp * $line[1], $lines));
            $sequence = SellerOrder::where('replacement_of_return_request_id', '!=', null)->count() + 1;

            /** @var Order $order */
            $order = Order::create([
                'order_number' => $parent->order_number.'-R'.$sequence,
                'user_id' => $parent->user_id,
                'checkout_intent_id' => (string) Str::ulid(),
                'status' => OrderStatus::Placed,
                // Same delivery address snapshot as the original order
                'delivery_address' => $parent->delivery_address,
                'delivery_state' => $parent->delivery_state,
                'payment_method' => $parent->payment_method,
                'payment_status' => PaymentStatus::Pending,
                'items_mrp_total' => $mrpTotal,
                'discount_total' => $mrpTotal,
                'delivery_fee' => 0,
                'grand_total' => 0,
                'placed_at' => now(),
            ]);

            /** @var SellerOrder $replacement */
            $replacement = SellerOrder::create([
                'order_id' => $order->id,
                'seller_profile_id' => $original->seller_profile_id,
                'seller_store_name' => $original->seller_store_name,
                'seller_gstin' => $original->seller_gstin,
                'seller_pickup_state' => $original->seller_pickup_state,
                'status' => SellerOrderStatus::Placed,
                'subtotal' => 0,
                'discount_total' => $mrpTotal,
                'grand_total' => 0,
                'placed_at' => now(),
                'replacement_of_return_request_id' => $returnRequestId,
            ]);

            foreach ($lines as [$item, $count]) {
                OrderItem::create([
                    'seller_order_id' => $replacement->id,
                    'listing_id' => $item->listing_id,
                    'product_variant_id' => $item->product_variant_id,
                    'title' => $item->title,
                    'variant_title' => $item->variant_title,
                    'sku' => $item->sku,
                    'upid' => $item->upid,
                    'image_path' => $item->image_path,
                    'seller_name' => $item->seller_name,
                    // ₹0: the original sale stands; no fees are calculated on a replacement
                    'unit_price' => 0,
                    'mrp' => $item->mrp,
                    'qty' => $count,
                    'line_total' => 0,
                    'coupon_discount_amount' => 0,
                    'hsn_code' => $item->hsn_code,
                    'gst_rate' => $item->gst_rate,
                    'gst_amount' => 0,
                ]);
            }

            return $replacement;
        });

        // Like any new order, the seller is told to confirm, pack and ship it
        $sellerEmail = $sellerOrder->sellerProfile?->user?->email;
        if ($sellerEmail) {
            DB::afterCommit(fn () => Mail::to($sellerEmail)->queue(new SellerNewOrderMail($sellerOrder)));
        }

        return $sellerOrder->id;
    }

    public function isReplacement(string $sellerOrderId): bool
    {
        return SellerOrder::where('id', $sellerOrderId)->whereNotNull('replacement_of_return_request_id')->exists();
    }
}
