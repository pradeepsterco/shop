<?php

namespace App\Orders\Services;

use App\Coupons\Contracts\CouponEvaluatorInterface;
use App\Coupons\Contracts\CouponRedemptionInterface;
use App\Coupons\DTOs\CartContext;
use App\Coupons\DTOs\CartLineContext;
use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Mail\CustomerOrderConfirmationMail;
use App\Mail\SellerNewOrderMail;
use App\Models\CustomerAddress;
use App\Models\Listing;
use App\Models\User;
use App\Orders\Contracts\OrderRestockInterface;
use App\Orders\DTOs\PlaceOrderData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Events\OrderPlaced;
use App\Orders\Events\SellerOrderCancelled;
use App\Orders\Events\SellerOrderDelivered;
use App\Orders\Events\SellerOrderReturnedToOrigin;
use App\Orders\Events\SellerOrderShipped;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\OrderStatusHistory;
use App\Orders\Models\SellerOrder;
use App\Services\ProductBuyBoxService;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OrderService implements OrderRestockInterface
{
    /**
     * Place an order from PlaceOrderData in a single database transaction.
     */
    public function place(PlaceOrderData $data): Order
    {
        // 1. Idempotency check before transaction
        /** @var Order|null $existingOrder */
        $existingOrder = Order::with(['sellerOrders.items'])->where('checkout_intent_id', $data->idempotencyKey)->first();
        if ($existingOrder) {
            return $existingOrder;
        }

        // 2. Validate customer & address ownership
        $user = User::with('sellerProfile')->findOrFail($data->userId);
        $address = CustomerAddress::where('id', $data->addressId)->where('user_id', $user->id)->first();
        if (! $address) {
            throw new DomainException('The selected delivery address does not belong to your account.');
        }

        if (empty($data->lines)) {
            throw new DomainException('Cannot place an order with no items.');
        }

        // 3. Pre-load listings and validate availability & prices
        $lineQtys = [];
        foreach ($data->lines as $line) {
            $lid = (int) $line['listing_id'];
            $lineQtys[$lid] = (int) $line['qty'];
        }

        $listings = Listing::with(['variant.product.vertical', 'variant.product.images', 'sellerProfile'])
            ->whereIn('id', array_keys($lineQtys))
            ->get()
            ->keyBy('id');

        $liveTotal = 0;
        $itemsMrpTotal = 0;
        $sellerGroups = [];

        foreach ($lineQtys as $listingId => $qty) {
            /** @var Listing|null $listing */
            $listing = $listings->get($listingId);
            if (! $listing) {
                throw new DomainException('One or more selected items are no longer available.');
            }

            // Check own listing
            if ($user->sellerProfile && $listing->seller_profile_id === $user->sellerProfile->id) {
                throw new DomainException('You cannot purchase your own listings.');
            }

            // Availability validation
            $this->validateListingAvailability($listing);

            if ($listing->stock < $qty) {
                $title = $listing->variant->product->title ?? 'Item';
                throw new DomainException("Item '{$title}' is out of stock or does not have enough quantity.");
            }

            $unitPrice = (int) $listing->selling_price;
            $mrp = (int) max($listing->mrp, $unitPrice);
            $lineTotal = $unitPrice * $qty;
            $lineMrpTotal = $mrp * $qty;

            $liveTotal += $lineTotal;
            $itemsMrpTotal += $lineMrpTotal;

            $sellerId = $listing->seller_profile_id;
            if (! isset($sellerGroups[$sellerId])) {
                $sellerGroups[$sellerId] = [
                    'sellerProfile' => $listing->sellerProfile,
                    'items' => [],
                    'subtotal' => 0,
                    'mrp_total' => 0,
                ];
            }

            $sellerGroups[$sellerId]['items'][] = [
                'listing' => $listing,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'mrp' => $mrp,
                'line_total' => $lineTotal,
            ];
            $sellerGroups[$sellerId]['subtotal'] += $lineTotal;
            $sellerGroups[$sellerId]['mrp_total'] += $lineMrpTotal;
        }

        // 4. Coupon evaluation & live total verification
        $couponEval = null;
        $couponDiscountTotal = 0;
        $couponCode = null;
        $couponLineAllocations = [];
        $couponFundedBy = null;

        if (! empty($data->couponCode)) {
            $cartLines = [];
            foreach ($lineQtys as $listingId => $qty) {
                /** @var Listing $listing */
                $listing = $listings->get($listingId);
                $uPrice = (int) $listing->selling_price;
                $lTotal = $uPrice * $qty;
                $v = $listing->variant;
                $p = $v->product;
                $vert = $p->vertical;
                $cartLines[] = new CartLineContext(
                    lineId: $listingId,
                    sellerId: (int) $listing->seller_profile_id,
                    productId: (int) $v->product_id,
                    categoryIds: $vert?->category_id ? [(int) $vert->category_id] : [],
                    brandId: $p->brand_id,
                    unitPrice: $uPrice,
                    quantity: $qty,
                    lineTotal: $lTotal,
                );
            }

            $cartContext = new CartContext(
                customerReference: (string) $user->id,
                lines: $cartLines,
                shippingAmount: 0,
            );

            /** @var CouponEvaluatorInterface $couponEvaluator */
            $couponEvaluator = app(CouponEvaluatorInterface::class);
            $couponEval = $couponEvaluator->evaluate($cartContext, $data->couponCode);

            if (! $couponEval->isValid) {
                throw new DomainException("Coupon error: {$couponEval->message}");
            }

            $couponDiscountTotal = $couponEval->totalDiscount;
            $couponCode = $couponEval->couponCode;
            $couponLineAllocations = $couponEval->lineAllocations;
            $couponFundedBy = $couponEval->fundedBy;
        }

        $deliveryFee = 0;
        $calculatedPayable = max(100, $liveTotal - $couponDiscountTotal) + $deliveryFee;

        if ($calculatedPayable !== $data->expectedTotal) {
            $formattedLive = number_format($calculatedPayable / 100);
            throw new DomainException("Price has changed since your checkout session started. Live total is ₹{$formattedLive}. Please review and confirm your order.");
        }

        $discountTotal = max(0, $itemsMrpTotal - $liveTotal);
        $grandTotal = $calculatedPayable;

        // 5. Execute transaction: claim order first, lock listings, decrement stock
        $order = DB::transaction(function () use (
            $data,
            $user,
            $address,
            $itemsMrpTotal,
            $discountTotal,
            $couponCode,
            $couponDiscountTotal,
            $couponLineAllocations,
            $couponFundedBy,
            $couponEval,
            $deliveryFee,
            $grandTotal,
            $sellerGroups,
            $lineQtys
        ) {
            // Claim checkout intent idempotently by inserting the order first
            try {
                $orderNumber = $this->generateUniqueOrderNumber();
                $order = Order::create([
                    'order_number' => $orderNumber,
                    'user_id' => $user->id,
                    'checkout_intent_id' => $data->idempotencyKey,
                    'status' => OrderStatus::Placed,
                    'delivery_address' => $address->toSnapshot(),
                    'delivery_state' => $address->state,
                    'payment_method' => PaymentMethod::CashOnDelivery,
                    'payment_status' => PaymentStatus::Pending,
                    'items_mrp_total' => $itemsMrpTotal,
                    'discount_total' => $discountTotal,
                    'coupon_code' => $couponCode,
                    'coupon_discount_total' => $couponDiscountTotal,
                    'delivery_fee' => $deliveryFee,
                    'grand_total' => $grandTotal,
                    'placed_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Double click / concurrent submit -> return existing order
                return Order::with(['sellerOrders.items'])->where('checkout_intent_id', $data->idempotencyKey)->firstOrFail();
            }

            // Lock listing rows ordered by id ascending to prevent deadlocks
            $sortedListingIds = array_keys($lineQtys);
            sort($sortedListingIds);

            Listing::whereIn('id', $sortedListingIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            // Atomic guarded stock decrement
            foreach ($lineQtys as $listingId => $qty) {
                $affected = Listing::where('id', $listingId)
                    ->where('stock', '>=', $qty)
                    ->decrement('stock', $qty);

                if ($affected === 0) {
                    throw new DomainException('Stock changed during checkout. One or more items are no longer available in the requested quantity.');
                }
            }

            // Create Seller Orders and Order Items
            foreach ($sellerGroups as $group) {
                $seller = $group['sellerProfile'];
                $sellerSubtotal = $group['subtotal'];
                $sellerMrpTotal = $group['mrp_total'];
                $sellerDiscount = max(0, $sellerMrpTotal - $sellerSubtotal);

                $sellerCouponDiscount = 0;
                foreach ($group['items'] as $itemData) {
                    $sellerCouponDiscount += (int) ($couponLineAllocations[$itemData['listing']->id] ?? 0);
                }

                $sellerGrandTotal = max(0, $sellerSubtotal - $sellerCouponDiscount);

                $sellerOrder = SellerOrder::create([
                    'order_id' => $order->id,
                    'seller_profile_id' => $seller->id,
                    'seller_store_name' => $seller->store_name,
                    'seller_gstin' => $seller->gstin,
                    'seller_pickup_state' => $seller->pickup_state,
                    'status' => SellerOrderStatus::Placed,
                    'subtotal' => $sellerSubtotal,
                    'discount_total' => $sellerDiscount,
                    'coupon_discount_total' => $sellerCouponDiscount,
                    'grand_total' => $sellerGrandTotal,
                    'placed_at' => now(),
                ]);

                // Create Order Items
                foreach ($group['items'] as $itemData) {
                    /** @var Listing $listing */
                    $listing = $itemData['listing'];
                    $qty = $itemData['qty'];
                    $unitPrice = $itemData['unit_price'];
                    $mrp = $itemData['mrp'];
                    $lineTotal = $itemData['line_total'];

                    $variant = $listing->variant;
                    $product = $variant->product;
                    $vertical = $product->vertical;

                    $hsnCode = $vertical?->hsn_code;
                    $gstRate = (float) ($vertical ? $vertical->gst_rate : 0);
                    $gstAmount = (int) round($lineTotal - ($lineTotal / (1 + ($gstRate / 100))));

                    $primaryImg = $product->images->first(fn ($img) => $img->status === ProductImageStatus::Live && $img->is_primary)
                        ?? $product->images->first(fn ($img) => $img->status === ProductImageStatus::Live)
                        ?? $product->images->first();

                    $labels = [];
                    if (! empty($variant->variant_attributes)) {
                        foreach ($variant->variant_attributes as $code => $val) {
                            $labels[] = $variant->optionDisplayLabel($code) ?: (string) $val;
                        }
                    }
                    $variantTitle = implode(', ', array_filter($labels));

                    $itemCouponDiscount = (int) ($couponLineAllocations[$listing->id] ?? 0);

                    OrderItem::create([
                        'seller_order_id' => $sellerOrder->id,
                        'listing_id' => $listing->id,
                        'product_variant_id' => $variant->id,
                        'title' => $product->title,
                        'variant_title' => $variantTitle ?: null,
                        'sku' => $listing->sku,
                        'upid' => $variant->upid,
                        'image_path' => $primaryImg ? $primaryImg->url() : null,
                        'seller_name' => $seller->store_name,
                        'unit_price' => $unitPrice,
                        'mrp' => $mrp,
                        'qty' => $qty,
                        'line_total' => $lineTotal,
                        'coupon_discount_amount' => $itemCouponDiscount,
                        'coupon_funded_by' => $couponFundedBy,
                        'hsn_code' => $hsnCode,
                        'gst_rate' => $gstRate,
                        'gst_amount' => $gstAmount,
                    ]);
                }

                // Initial status history log
                OrderStatusHistory::create([
                    'seller_order_id' => $sellerOrder->id,
                    'from_status' => null,
                    'to_status' => SellerOrderStatus::Placed->value,
                    'changed_by_type' => 'customer',
                    'changed_by_id' => $user->id,
                    'reason' => 'Order placed with Cash on Delivery',
                    'created_at' => now(),
                ]);
            }

            // Redeem coupon inside the same transaction
            if ($couponEval && $couponEval->isValid) {
                /** @var CouponRedemptionInterface $redemptionService */
                $redemptionService = app(CouponRedemptionInterface::class);
                $redemptionService->redeem(
                    evaluation: $couponEval,
                    customerReference: (string) $user->id,
                    orderReference: $order->order_number,
                    idempotencyKey: $data->idempotencyKey,
                );
            }

            return $order;
        });

        // 6. After Commit side effects: refresh buy boxes and fire event
        $affectedProductIds = [];
        foreach ($listings as $listing) {
            if ($listing->variant->product_id) {
                $affectedProductIds[] = $listing->variant->product_id;
            }
        }
        $affectedProductIds = array_unique($affectedProductIds);

        $buyBoxService = app(ProductBuyBoxService::class);
        foreach ($affectedProductIds as $pId) {
            $buyBoxService->refresh($pId);
        }

        // Fire OrderPlaced event (cart cleans up lines when source == 'cart')
        event(new OrderPlaced($order->id, $user->id, $data->lines, $data->source));

        // Queue order confirmation notifications after commit
        Mail::to($user->email)->queue(new CustomerOrderConfirmationMail($order));

        foreach ($order->sellerOrders as $sellerOrder) {
            $sellerUser = $sellerOrder->sellerProfile?->user;
            if ($sellerUser && $sellerUser->email) {
                Mail::to($sellerUser->email)->queue(new SellerNewOrderMail($sellerOrder));
            }
        }

        return $order;
    }

    /**
     * Transition a seller order's status and record audit log.
     */
    public function transitionSellerOrderStatus(
        SellerOrder $sellerOrder,
        SellerOrderStatus $newStatus,
        string $actorType,
        int $actorId,
        ?string $reason = null,
        ?string $courierName = null,
        ?string $trackingNumber = null,
    ): void {
        $oldStatus = $sellerOrder->status;
        if ($oldStatus === $newStatus) {
            return;
        }

        DB::transaction(function () use ($sellerOrder, $oldStatus, $newStatus, $actorType, $actorId, $reason, $courierName, $trackingNumber) {
            $updates = ['status' => $newStatus];

            match ($newStatus) {
                SellerOrderStatus::Confirmed => $updates['confirmed_at'] = now(),
                SellerOrderStatus::Packed => $updates['packed_at'] = now(),
                SellerOrderStatus::Shipped => [
                    $updates['shipped_at'] = now(),
                    $updates['courier_name'] = $courierName,
                    $updates['tracking_number'] = $trackingNumber,
                ],
                SellerOrderStatus::Delivered, SellerOrderStatus::PartiallyDelivered => $updates['delivered_at'] = now(),
                SellerOrderStatus::ReturnedToOrigin => $updates['rto_delivered_at'] = now(),
                SellerOrderStatus::Cancelled => [
                    $updates['cancelled_at'] = now(),
                    $updates['cancellation_reason'] = $reason,
                    $updates['cancelled_by_type'] = $actorType,
                    $updates['cancelled_by_id'] = $actorId,
                ],
                default => null,
            };

            $sellerOrder->update($updates);

            OrderStatusHistory::create([
                'seller_order_id' => $sellerOrder->id,
                'from_status' => $oldStatus->value,
                'to_status' => $newStatus->value,
                'changed_by_type' => $actorType,
                'changed_by_id' => $actorId,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            // Update parent order status
            $this->updateParentOrderStatus($sellerOrder->order);

            // Fire domain events after DB commit
            DB::afterCommit(function () use ($sellerOrder, $newStatus, $actorType, $actorId, $reason) {
                $profileId = (int) $sellerOrder->seller_profile_id;
                $timestamp = now();

                match ($newStatus) {
                    SellerOrderStatus::Shipped => event(new SellerOrderShipped(
                        sellerOrderId: (string) $sellerOrder->id,
                        sellerProfileId: $profileId,
                        timestamp: $timestamp,
                        actorType: $actorType,
                        actorId: $actorId,
                    )),
                    SellerOrderStatus::Delivered => event(new SellerOrderDelivered(
                        sellerOrderId: (string) $sellerOrder->id,
                        sellerProfileId: $profileId,
                        timestamp: $timestamp,
                        actorType: $actorType,
                        actorId: $actorId,
                    )),
                    SellerOrderStatus::ReturnedToOrigin => event(new SellerOrderReturnedToOrigin(
                        sellerOrderId: (string) $sellerOrder->id,
                        orderId: (string) $sellerOrder->order_id,
                        sellerProfileId: $profileId,
                        reason: $reason,
                    )),
                    SellerOrderStatus::Cancelled => event(new SellerOrderCancelled(
                        sellerOrderId: (string) $sellerOrder->id,
                        sellerProfileId: $profileId,
                        timestamp: $timestamp,
                        actorType: $actorType,
                        actorId: $actorId,
                        reason: $reason,
                    )),
                    default => null,
                };
            });
        });
    }

    /**
     * Cancel a seller order, restock its listings, and update parent order.
     */
    public function cancelSellerOrder(SellerOrder $sellerOrder, string $actorType, int $actorId, string $reason): void
    {
        if ($sellerOrder->status === SellerOrderStatus::Cancelled) {
            return;
        }

        if ($actorType === 'customer' && ! $sellerOrder->status->canCancelByCustomer()) {
            throw new DomainException('This order cannot be cancelled as it has already been processed.');
        }

        if ($actorType === 'seller' && ! $sellerOrder->status->canCancelBySeller()) {
            throw new DomainException('This order cannot be cancelled at this stage.');
        }

        DB::transaction(function () use ($sellerOrder, $actorType, $actorId, $reason) {
            // Restock listings
            $affectedProductIds = [];
            foreach ($sellerOrder->items as $item) {
                if ($item->listing_id) {
                    Listing::where('id', $item->listing_id)->increment('stock', $item->qty);
                }
                if ($item->variant && $item->variant->product_id) {
                    $affectedProductIds[] = $item->variant->product_id;
                }
            }

            $this->transitionSellerOrderStatus(
                $sellerOrder,
                SellerOrderStatus::Cancelled,
                $actorType,
                $actorId,
                $reason
            );

            // Release coupon allocation if applied
            if ($sellerOrder->coupon_discount_total > 0) {
                app(CouponRedemptionInterface::class)->release(
                    $sellerOrder->order->order_number,
                    (int) $sellerOrder->coupon_discount_total
                );
            }

            // Refresh buy boxes for restocked products
            $buyBoxService = app(ProductBuyBoxService::class);
            foreach (array_unique($affectedProductIds) as $pId) {
                $buyBoxService->refresh($pId);
            }
        });
    }

    /**
     * Restock inventory for items in a seller order and refresh buy box.
     *
     * @param  array<int>|null  $orderItemIds
     */
    public function restockSellerOrder(string $sellerOrderId, ?array $orderItemIds = null): void
    {
        /** @var SellerOrder $sellerOrder */
        $sellerOrder = SellerOrder::with('items')->findOrFail($sellerOrderId);

        $affectedProductIds = [];

        DB::transaction(function () use ($sellerOrder, $orderItemIds, &$affectedProductIds) {
            foreach ($sellerOrder->items as $item) {
                if ($orderItemIds !== null && ! in_array($item->id, $orderItemIds, true)) {
                    continue;
                }

                if ($item->listing_id) {
                    Listing::where('id', $item->listing_id)->increment('stock', $item->qty);

                    /** @var Listing|null $listing */
                    $listing = Listing::with('variant')->find($item->listing_id);
                    if ($listing?->variant?->product_id) {
                        $affectedProductIds[] = $listing->variant->product_id;
                    }
                }
            }
        });

        // Refresh buy boxes for restocked products
        $buyBoxService = app(ProductBuyBoxService::class);
        foreach (array_unique($affectedProductIds) as $pId) {
            $buyBoxService->refresh($pId);
        }
    }

    public function reserveStockForReship(int $orderItemId, int $units): void
    {
        /** @var OrderItem $item */
        $item = OrderItem::findOrFail($orderItemId);

        // Conditional decrement: only an active listing with enough stock is touched (safe against concurrent sales)
        $taken = $item->listing_id
            ? Listing::where('id', $item->listing_id)
                ->where('status', ListingStatus::Active->value)
                ->where('stock', '>=', $units)
                ->decrement('stock', $units)
            : 0;

        if ($taken === 0) {
            throw new DomainException("{$item->title} is out of stock or its listing is not active; refund the customer instead.");
        }

        $this->refreshBuyBoxForListing($item->listing_id);
    }

    public function restockOrderItemUnits(int $orderItemId, int $units): void
    {
        /** @var OrderItem $item */
        $item = OrderItem::findOrFail($orderItemId);

        if (! $item->listing_id || $units <= 0) {
            return;
        }

        Listing::where('id', $item->listing_id)->increment('stock', $units);
        $this->refreshBuyBoxForListing($item->listing_id);
    }

    protected function refreshBuyBoxForListing(int $listingId): void
    {
        /** @var Listing|null $listing */
        $listing = Listing::with('variant')->find($listingId);
        if ($listing?->variant?->product_id) {
            app(ProductBuyBoxService::class)->refresh($listing->variant->product_id);
        }
    }

    /**
     * Update parent Order status based on all child seller orders.
     */
    public function updateParentOrderStatus(Order $order): void
    {
        $sellerOrders = $order->sellerOrders()->get();
        if ($sellerOrders->isEmpty()) {
            return;
        }

        // RTO and lost packages close like a cancellation for the customer: nothing to collect.
        // A partially delivered package counts as delivered (COD is collected for the delivered items).
        $allCancelled = $sellerOrders->every(fn (SellerOrder $so) => $so->status->isClosedUndelivered());
        $anyCancelled = $sellerOrders->contains(fn (SellerOrder $so) => $so->status->isClosedUndelivered());
        $allDelivered = $sellerOrders->every(fn (SellerOrder $so) => $so->status->isDeliveredToCustomer());

        // Returns: every package fully refunded, or some refunded with the rest finished (delivered or closed)
        $allRefunded = $sellerOrders->every(fn (SellerOrder $so) => $so->status === SellerOrderStatus::Refunded);
        $anyRefunded = $sellerOrders->contains(fn (SellerOrder $so) => $so->status->isRefunded());
        $allFinished = $sellerOrders->every(fn (SellerOrder $so) => $so->status->isRefunded() || $so->status->isDeliveredToCustomer() || $so->status->isClosedUndelivered());

        if ($allRefunded) {
            $order->status = OrderStatus::Refunded;
        } elseif ($anyRefunded && $allFinished) {
            $order->status = OrderStatus::PartiallyRefunded;
        } elseif ($allCancelled) {
            $order->status = OrderStatus::Cancelled;
            match ($order->payment_method) {
                PaymentMethod::CashOnDelivery => $order->payment_status = PaymentStatus::Cancelled,
            };
        } elseif ($allDelivered) {
            $order->status = OrderStatus::Completed;
            match ($order->payment_method) {
                PaymentMethod::CashOnDelivery => $order->payment_status = PaymentStatus::Completed,
            };
        } elseif ($anyCancelled) {
            $order->status = OrderStatus::PartiallyCancelled;
        } else {
            $order->status = OrderStatus::Placed;
        }

        $order->save();
    }

    /**
     * Generate unique order number with retries on collision.
     */
    private function generateUniqueOrderNumber(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $number = 'KH-'.date('Ymd').'-'.strtoupper(Str::random(6));
            if (! Order::where('order_number', $number)->exists()) {
                return $number;
            }
        }

        return 'KH-'.date('Ymd').'-'.strtoupper(Str::random(10));
    }

    /**
     * Check listing full availability.
     */
    private function validateListingAvailability(Listing $listing): void
    {
        if ($listing->trashed() || $listing->status !== ListingStatus::Active) {
            throw new DomainException('An item in your order is no longer available.');
        }

        $variant = $listing->variant;
        if ($variant->trashed() || $variant->qc_status !== VariantQcStatus::Approved) {
            throw new DomainException('An item variant in your order is no longer available.');
        }

        $product = $variant->product;
        if ($product->qc_status !== ProductQcStatus::Approved) {
            throw new DomainException('A product in your order is no longer available.');
        }

        $seller = $listing->sellerProfile;
        if ($seller->status !== SellerStatus::Approved) {
            throw new DomainException('A seller in your order is currently inactive or suspended.');
        }
    }
}
