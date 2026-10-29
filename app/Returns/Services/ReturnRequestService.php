<?php

namespace App\Returns\Services;

use App\Mail\ReturnUpdateMail;
use App\Models\Admin;
use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Contracts\CustomerRefundServiceInterface;
use App\Orders\Contracts\OrderRestockInterface;
use App\Orders\Contracts\ReplacementOrderServiceInterface;
use App\Orders\Contracts\ReturnableOrderLookupInterface;
use App\Orders\Contracts\SellerOrderFulfillmentInterface;
use App\Orders\Contracts\SellerOrderReturnWindowLookupInterface;
use App\Orders\Enums\SellerOrderStatus;
use App\Returns\Contracts\CourierClaimServiceInterface;
use App\Returns\Contracts\ReturnRequestServiceInterface;
use App\Returns\Enums\ReturnFault;
use App\Returns\Enums\ReturnReason;
use App\Returns\Enums\ReturnRequestStatus;
use App\Returns\Enums\RtoConditionStatus;
use App\Returns\Models\PlatformLoss;
use App\Returns\Models\ReturnRequest;
use App\Returns\Models\ReturnRequestItem;
use App\Returns\Models\UndeliveredItem;
use App\Services\AdminActivityLogger;
use App\Settlements\Contracts\SettlementServiceInterface;
use App\Shipping\Contracts\ShipmentLookupInterface;
use App\Shipping\Contracts\ShipmentServiceInterface;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ReturnRequestService implements ReturnRequestServiceInterface
{
    public function __construct(
        protected ReturnableOrderLookupInterface $orders,
        protected SellerOrderReturnWindowLookupInterface $currentWindows,
        protected SettlementServiceInterface $settlements,
        protected ShipmentServiceInterface $shipments,
        protected ShipmentLookupInterface $shipmentLookup,
        protected CustomerRefundServiceInterface $refunds,
        protected OrderRestockInterface $inventory,
        protected ReplacementOrderServiceInterface $replacements,
        protected SellerOrderFulfillmentInterface $orderFulfillment,
        protected CourierClaimServiceInterface $claims,
    ) {}

    public function returnableLines(string $sellerOrderId): array
    {
        $order = $this->orders->forSellerOrder($sellerOrderId);
        if (! $order) {
            return [];
        }

        $delivered = in_array($order->status, [SellerOrderStatus::Delivered, SellerOrderStatus::PartiallyDelivered, SellerOrderStatus::PartiallyRefunded], true)
            && $order->deliveredAt !== null;

        // The window saved when the package was delivered; later vertical edits never change it.
        // Only if there is no settlement (fees never calculated) do the verticals' current windows apply.
        $windowDays = $this->settlements->savedReturnWindowDays($sellerOrderId) ?? $this->currentWindows->returnWindowDays($sellerOrderId);

        $undelivered = UndeliveredItem::where('seller_order_id', $sellerOrderId)->get();
        $inReturns = ReturnRequestItem::query()
            ->join('return_requests', 'return_requests.id', '=', 'return_request_items.return_request_id')
            ->where('return_requests.seller_order_id', $sellerOrderId)
            ->whereNotIn('return_requests.status', [ReturnRequestStatus::Rejected->value, ReturnRequestStatus::Closed->value])
            ->selectRaw('return_request_items.order_item_id, sum(return_request_items.units) as units')
            ->groupBy('return_request_items.order_item_id')
            ->pluck('units', 'order_item_id');

        $lines = [];
        foreach ($order->items as $orderItemId => $item) {
            $lineUndelivered = $undelivered->where('order_item_id', $orderItemId);
            $notDelivered = (int) $lineUndelivered->where('status', '!=', UndeliveredItem::STATUS_RESHIPPED)->sum('units');

            // Reshipped units were delivered later: their window runs from the reship delivery
            $reshipped = $lineUndelivered->firstWhere('status', UndeliveredItem::STATUS_RESHIPPED);
            $reshipDeliveredAt = $reshipped?->reship_shipment_id
                ? $this->shipmentLookup->getShipmentById($reshipped->reship_shipment_id)?->deliveredAt
                : null;
            $deliveredAt = $reshipDeliveredAt ?? $order->deliveredAt;

            $lines[$orderItemId] = [
                'title' => $item->title,
                'available' => $delivered ? max(0, $item->qty - $notDelivered - (int) ($inReturns[$orderItemId] ?? 0)) : 0,
                'deadline' => $delivered && $deliveredAt ? CarbonImmutable::instance($deliveredAt)->addDays($windowDays) : null,
                'returnable' => $item->isReturnable,
            ];
        }

        return $lines;
    }

    public function create(
        int $userId,
        string $sellerOrderId,
        ReturnReason $reason,
        string $preference,
        array $units,
        array $photos = [],
        ?string $notes = null,
        ?int $overrideByAdminId = null,
        ?string $overrideReason = null,
    ): ReturnRequest {
        if ($overrideByAdminId && trim((string) $overrideReason) === '') {
            throw new DomainException('Give a reason for creating this return outside the normal rules.');
        }

        $order = $this->orders->forSellerOrder($sellerOrderId);
        if (! $order || $order->userId !== $userId) {
            throw new DomainException('This order does not belong to you.');
        }

        if (! in_array($preference, [ReturnRequest::PREFERENCE_REFUND, ReturnRequest::PREFERENCE_REPLACEMENT], true)) {
            throw new DomainException('Choose a refund or a replacement.');
        }

        // A replacement unit already used its one replacement: returning it again can only be refunded
        if ($preference === ReturnRequest::PREFERENCE_REPLACEMENT && $this->replacements->isReplacement($sellerOrderId)) {
            throw new DomainException('A replacement can only be returned for a refund.');
        }

        $units = array_filter(array_map('intval', $units), fn (int $n) => $n > 0);
        if ($units === []) {
            throw new DomainException('Choose at least one item to return.');
        }

        if ($reason->requiresPhotos()) {
            Validator::make(['photos' => $photos], [
                'photos' => ['required', 'array', 'min:1', 'max:5'],
                'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ], [
                'photos.required' => 'Add at least one photo showing the problem.',
                'photos.min' => 'Add at least one photo showing the problem.',
            ])->validate();
        } elseif (count($photos) > 0) {
            Validator::make(['photos' => $photos], [
                'photos' => ['array', 'max:5'],
                'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ])->validate();
        }

        // Photos go to the private disk first; removed again if the request is refused
        $requestId = (string) Str::ulid();
        $storedPhotos = array_map(fn ($photo) => $photo->store("return_photos/{$requestId}", 'local'), $photos);

        try {
            $request = DB::transaction(function () use ($requestId, $userId, $order, $reason, $preference, $units, $storedPhotos, $notes, $overrideByAdminId) {
                $hasActive = ReturnRequest::where('seller_order_id', $order->sellerOrderId)
                    ->whereIn('status', ReturnRequestStatus::activeValues())
                    ->lockForUpdate()
                    ->exists();
                if ($hasActive) {
                    throw new DomainException('This package already has an open return.');
                }

                $lines = $this->returnableLines($order->sellerOrderId);
                $deadlines = [];

                foreach ($units as $orderItemId => $count) {
                    $line = $lines[$orderItemId] ?? null;
                    if (! $line) {
                        throw new DomainException('That item is not part of this package.');
                    }

                    if ($count > $line['available']) {
                        throw new DomainException("Only {$line['available']} unit(s) of {$line['title']} can be returned.");
                    }

                    if (! $line['returnable'] && ! $reason->isClaim()) {
                        throw new DomainException("{$line['title']} can only be returned if it arrived damaged, defective, wrong or incomplete.");
                    }

                    if ($line['deadline'] && now()->greaterThan($line['deadline']) && ! $overrideByAdminId) {
                        throw new DomainException("The return window for {$line['title']} closed on ".format_datetime($line['deadline']).'.');
                    }

                    $deadlines[] = $line['deadline'];
                }

                /** @var ReturnRequest $request */
                $request = ReturnRequest::forceCreate([
                    'id' => $requestId,
                    'order_id' => $order->orderId,
                    'seller_order_id' => $order->sellerOrderId,
                    'user_id' => $userId,
                    'reason' => $reason,
                    'preference' => $preference,
                    'status' => ReturnRequestStatus::Requested,
                    'customer_notes' => $notes,
                    'photos' => $storedPhotos,
                    'return_deadline_at' => collect($deadlines)->filter()->min(),
                    'window_override_by_admin_id' => $overrideByAdminId,
                ]);

                foreach ($units as $orderItemId => $count) {
                    ReturnRequestItem::create([
                        'return_request_id' => $request->id,
                        'order_item_id' => $orderItemId,
                        'units' => $count,
                        'line_qty' => $order->items[$orderItemId]->qty,
                    ]);
                }

                $this->settlements->placeReturnHold($order->sellerOrderId);

                return $request;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($storedPhotos);

            throw $e;
        }

        if ($overrideByAdminId) {
            AdminActivityLogger::log('returns.window_override', $request, [
                'seller_order_id' => $sellerOrderId,
                'units' => $units,
                'deadline' => $request->return_deadline_at?->toIso8601String(),
                'reason' => trim((string) $overrideReason),
            ], Admin::find($overrideByAdminId));
        }

        return $request->load('items');
    }

    public function requestInfo(string $returnRequestId, string $message, int $adminId): ReturnRequest
    {
        $message = trim($message);
        if ($message === '') {
            throw new DomainException('Tell the customer what information is needed.');
        }

        return DB::transaction(function () use ($returnRequestId, $message, $adminId) {
            $request = $this->lockInStatus($returnRequestId, [ReturnRequestStatus::Requested]);
            $request->update([
                'status' => ReturnRequestStatus::InfoRequested,
                'info_request_message' => $message,
                'decided_by_admin_id' => $adminId,
            ]);

            return $request->fresh();
        });
    }

    public function respond(string $returnRequestId, int $userId, string $response, array $photos = []): ReturnRequest
    {
        $response = trim($response);
        if ($response === '') {
            throw new DomainException('Add your answer before sending.');
        }

        Validator::make(['photos' => $photos], [
            'photos' => ['array', 'max:5'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ])->validate();

        $stored = array_map(fn ($photo) => $photo->store("return_photos/{$returnRequestId}", 'local'), $photos);

        try {
            return DB::transaction(function () use ($returnRequestId, $userId, $response, $stored) {
                $request = $this->lockInStatus($returnRequestId, [ReturnRequestStatus::InfoRequested]);
                if ($request->user_id !== $userId) {
                    throw new DomainException('This return does not belong to you.');
                }

                $allPhotos = array_merge($request->photos ?? [], $stored);
                if (count($allPhotos) > 5) {
                    throw new DomainException('A return can have at most 5 photos.');
                }

                $request->update([
                    'status' => ReturnRequestStatus::Requested,
                    'customer_response' => $response,
                    'photos' => $allPhotos,
                ]);

                return $request->fresh();
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($stored);

            throw $e;
        }
    }

    public function approve(string $returnRequestId, int $adminId): ReturnRequest
    {
        $request = DB::transaction(function () use ($returnRequestId, $adminId) {
            $request = $this->lockInStatus($returnRequestId, [ReturnRequestStatus::Requested, ReturnRequestStatus::InfoRequested]);
            $request->load('items');
            $units = $request->items->pluck('units', 'order_item_id')->all();
            $decision = ['decided_by_admin_id' => $adminId, 'decided_at' => now()];

            if (! $request->reason->needsPickup()) {
                // Missing item: the seller did not ship it. Reverse sale and fees for those units, then hand them to the
                // refund/reship queue (the customer paid for them).
                $this->settlements->reverseUnitsForReturnClaim($request->seller_order_id, $request->id, $request->lines());
                $originalShipmentId = $this->shipmentLookup->getShipmentForSellerOrder($request->seller_order_id)?->id;

                foreach ($request->items as $item) {
                    UndeliveredItem::create([
                        'seller_order_id' => $request->seller_order_id,
                        'shipment_id' => (string) $originalShipmentId,
                        'order_item_id' => $item->order_item_id,
                        'units' => $item->units,
                        'line_qty' => $item->line_qty,
                        'status' => UndeliveredItem::STATUS_PENDING,
                        'origin' => UndeliveredItem::ORIGIN_MISSING_ITEM_CLAIM,
                        'customer_paid' => true,
                        'return_request_id' => $request->id,
                        'recorded_by_admin_id' => $adminId,
                    ]);
                }

                $request->update(['status' => ReturnRequestStatus::Resolved] + $decision);
                $this->settlements->releaseReturnHold($request->seller_order_id);

                return $request;
            }

            $pickup = $this->shipments->createReturnPickupBooking(
                sellerOrderId: $request->seller_order_id,
                units: $units,
                declaredValuePaise: $this->refunds->unitsValue($request->seller_order_id, $units),
                returnReason: $request->reason->label(),
            );

            $request->update(['status' => ReturnRequestStatus::Approved, 'reverse_shipment_id' => $pickup->id] + $decision);

            return $request;
        });

        // Courier booking after commit, no locks held; a failure leaves a retryable booking on the shipment page
        if ($request->reverse_shipment_id && $request->status === ReturnRequestStatus::Approved) {
            $this->shipments->bookShipment($request->reverse_shipment_id);
        }

        return $request->fresh();
    }

    public function reject(string $returnRequestId, string $reason, int $adminId): ReturnRequest
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Give the customer a reason for the rejection.');
        }

        return DB::transaction(function () use ($returnRequestId, $reason, $adminId) {
            $request = $this->lockInStatus($returnRequestId, [ReturnRequestStatus::Requested, ReturnRequestStatus::InfoRequested]);
            $request->update([
                'status' => ReturnRequestStatus::Rejected,
                'rejection_reason' => $reason,
                'decided_by_admin_id' => $adminId,
                'decided_at' => now(),
            ]);
            $this->settlements->releaseReturnHold($request->seller_order_id);

            return $request->fresh();
        });
    }

    public function pickupCancelled(string $reverseShipmentId, ?string $reason = null): void
    {
        DB::transaction(function () use ($reverseShipmentId, $reason) {
            /** @var ReturnRequest|null $request */
            $request = ReturnRequest::where('reverse_shipment_id', $reverseShipmentId)
                ->where('status', ReturnRequestStatus::Approved->value)
                ->lockForUpdate()
                ->first();
            if (! $request) {
                return;
            }

            $request->update([
                'status' => ReturnRequestStatus::Closed,
                'closed_reason' => $reason ?: 'Return pickup could not be completed',
            ]);
            $this->settlements->releaseReturnHold($request->seller_order_id);
        });
    }

    public function pickupReceived(string $reverseShipmentId): void
    {
        $request = DB::transaction(function () use ($reverseShipmentId) {
            /** @var ReturnRequest|null $request */
            $request = ReturnRequest::where('reverse_shipment_id', $reverseShipmentId)
                ->where('status', ReturnRequestStatus::Approved->value)
                ->lockForUpdate()
                ->first();
            if (! $request) {
                return null;
            }

            // The seller reports the condition within return_report_days (shown in India time)
            $request->update([
                'status' => ReturnRequestStatus::Received,
                'received_at' => now(),
                'report_deadline_at' => now()->addDays((int) config('returns.return_report_days', 3)),
            ]);

            return $request;
        });

        if ($request) {
            $this->notify($request, 'received', seller: true);
        }
    }

    public function submitSellerReport(string $returnRequestId, int $sellerProfileId, string $condition, ?string $notes, array $photos = []): ReturnRequest
    {
        $conditionEnum = RtoConditionStatus::tryFrom($condition);
        if (! $conditionEnum) {
            throw new DomainException('Choose the condition the item arrived in.');
        }

        Validator::make(['photos' => $photos], [
            'photos' => ['array', 'max:5'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ])->validate();

        // Same pattern as the RTO intake report: private disk, own packages only, read-only once submitted
        $stored = array_map(fn ($photo) => $photo->store("return_reports/{$returnRequestId}", 'local'), $photos);

        try {
            return DB::transaction(function () use ($returnRequestId, $sellerProfileId, $conditionEnum, $notes, $stored) {
                $request = $this->lockInStatus($returnRequestId, [ReturnRequestStatus::Received]);

                if ($this->orders->forSellerOrder($request->seller_order_id)?->sellerProfileId !== $sellerProfileId) {
                    throw new DomainException('This return is not for one of your packages.');
                }

                if ($request->seller_reported_at) {
                    throw new DomainException('The condition report has already been submitted.');
                }

                if ($request->sellerReportMissed()) {
                    throw new DomainException('The report deadline has passed; the marketplace will decide without it.');
                }

                $request->update([
                    'seller_condition' => $conditionEnum->value,
                    'seller_report_notes' => $notes,
                    'seller_report_photos' => $stored,
                    'seller_reported_at' => now(),
                ]);

                return $request->fresh();
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($stored);

            throw $e;
        }
    }

    public function decideQc(
        string $returnRequestId,
        int $adminId,
        bool $passed,
        ?string $failOutcome = null,
        ?int $partialAmountPaise = null,
        ?ReturnFault $fault = null,
        ?string $notes = null,
    ): ReturnRequest {
        $decided = DB::transaction(function () use ($returnRequestId, $adminId, $passed, $failOutcome, $partialAmountPaise, $fault, $notes) {
            // Received and undecided only: a second decision finds the request resolved
            $request = $this->lockInStatus($returnRequestId, [ReturnRequestStatus::Received]);
            $request->load('items');

            if (! $request->seller_reported_at && ! $request->sellerReportMissed()) {
                throw new DomainException('Wait for the seller\'s condition report (due '.format_datetime($request->report_deadline_at).').');
            }

            $sellerOrderId = $request->seller_order_id;
            $units = $request->items->pluck('units', 'order_item_id')->all();
            $lines = $request->items->mapWithKeys(fn (ReturnRequestItem $item) => [
                $item->order_item_id => ['units' => $item->units, 'line_qty' => $item->line_qty, 'return_item_id' => $item->id],
            ])->all();
            // On pass the fault follows the reason unless the admin says otherwise. On a failed check a refund is a
            // platform goodwill gesture unless the admin explicitly blames the seller.
            $sellerBlamed = $fault === ReturnFault::SellerFault;
            $fault ??= $passed ? $request->reason->fault() : ReturnFault::CustomerRemorse;
            $goodwill = false;
            // The seller pays the return pickup in every case
            $shipping = ['return_shipping' => (float) config('returns.return_shipping_multiplier', 1.0)];
            $replacementMultiplier = (float) config('returns.replacement_shipping_multiplier', 1.0);
            $value = $this->refunds->unitsValue($sellerOrderId, $units);
            $refund = null;
            $replacementId = null;
            $shipBackId = null;
            $reverseSale = false;
            $ratio = [1, 1];

            if ($passed) {
                // Restock only on pass (buy box refreshed)
                foreach ($request->items as $item) {
                    $this->inventory->restockOrderItemUnits($item->order_item_id, $item->units);
                }

                if ($request->preference === ReturnRequest::PREFERENCE_REPLACEMENT && ! $this->replacements->isReplacement($sellerOrderId)) {
                    try {
                        $replacementId = $this->replacements->createReplacement($sellerOrderId, $units, $request->id);
                        $shipping['replacement_shipping'] = $replacementMultiplier;
                    } catch (DomainException) {
                        // Out of stock or listing inactive: becomes a refund automatically
                    }
                }

                if ($replacementId) {
                    $outcome = 'replacement';
                } else {
                    $refund = $this->refunds->createRefund(ReturnRequest::REFUND_SOURCE, $request->id, $sellerOrderId, $units, true);
                    $outcome = $request->preference === ReturnRequest::PREFERENCE_REPLACEMENT ? 'converted_to_refund' : 'refund';
                    $reverseSale = true;
                }
            } else {
                $outcome = match ($failOutcome) {
                    'ship_back', 'partial_refund', 'full_refund' => $failOutcome,
                    default => throw new DomainException('Choose what happens to a failed return: ship back, partial refund or full refund.'),
                };

                if ($outcome === 'ship_back') {
                    $shipBackId = $this->shipments->createShipBackBooking($sellerOrderId, $units, $value)->id;
                    $shipping['ship_back_shipping'] = $replacementMultiplier;
                } else {
                    $amount = $outcome === 'partial_refund' ? $partialAmountPaise : null;
                    if ($outcome === 'partial_refund' && ($amount === null || $amount < 1 || $amount > $value)) {
                        throw new DomainException('Enter a partial refund between ₹0.01 and ₹'.number_format($value / 100, 2).'.');
                    }

                    $refund = $this->refunds->createRefund(ReturnRequest::REFUND_SOURCE, $request->id, $sellerOrderId, $units, true, $amount);

                    if ($sellerBlamed) {
                        $reverseSale = true;
                        $ratio = $amount !== null ? [$amount, $value] : [1, 1];
                    } else {
                        // Goodwill: the seller keeps the sale and fees; the platform absorbs the refund
                        $goodwill = true;
                        PlatformLoss::create([
                            'loss_type' => PlatformLoss::TYPE_GOODWILL_REFUND,
                            'seller_order_id' => $sellerOrderId,
                            'shipment_id' => (string) $request->reverse_shipment_id,
                            'loss_amount_paise' => $refund->amountPaise,
                            'declared_value_paise' => $value,
                            'admin_id' => $adminId,
                            'notes' => $notes ?? 'Goodwill refund after a failed return check',
                        ]);
                    }
                }
            }

            $this->settlements->settleReturn($sellerOrderId, $request->id, $lines, [
                'reverse_sale' => $reverseSale,
                'reverse_fees' => $fault->reversesFees(),
                'refund_ratio' => $ratio,
                'shipping' => $shipping,
            ]);

            $request->update([
                'status' => ReturnRequestStatus::Resolved,
                'qc_result' => $passed ? ReturnRequest::QC_PASSED : ReturnRequest::QC_FAILED,
                'qc_outcome' => $outcome,
                'qc_fault' => $goodwill ? 'platform_goodwill' : $fault->value,
                'qc_notes' => $notes,
                'qc_by_admin_id' => $adminId,
                'qc_at' => now(),
                'refund_id' => $refund?->id,
                'replacement_seller_order_id' => $replacementId,
                'ship_back_shipment_id' => $shipBackId,
            ]);

            $this->settlements->releaseReturnHold($sellerOrderId);
            if ($refund) {
                $this->orderFulfillment->recordReturnRefund($sellerOrderId);
            }

            return $request;
        });

        // Courier booking after commit, no locks held
        if ($decided->ship_back_shipment_id) {
            $this->shipments->bookShipment($decided->ship_back_shipment_id);
        }

        $event = $decided->qc_result === ReturnRequest::QC_PASSED ? 'qc_passed' : 'qc_failed';
        $this->notify($decided, $decided->qc_outcome === 'converted_to_refund' ? 'converted_to_refund' : $event);
        $this->notify($decided, $event, seller: true);

        return $decided->fresh();
    }

    public function pickupLost(string $reverseShipmentId): void
    {
        $request = DB::transaction(function () use ($reverseShipmentId) {
            /** @var ReturnRequest|null $request */
            $request = ReturnRequest::with('items')
                ->where('reverse_shipment_id', $reverseShipmentId)
                ->where('status', ReturnRequestStatus::Approved->value)
                ->lockForUpdate()
                ->first();
            if (! $request) {
                return null;
            }

            $sellerOrderId = $request->seller_order_id;
            $units = $request->items->pluck('units', 'order_item_id')->all();
            $value = $this->refunds->unitsValue($sellerOrderId, $units);

            // The customer handed the item over: refunded in full. The seller's sale is NOT reversed (courier loss).
            $refund = $this->refunds->createRefund(ReturnRequest::REFUND_SOURCE, $request->id, $sellerOrderId, $units, true);

            $claim = $this->claims->recordClaim(
                shipmentId: $reverseShipmentId,
                sellerOrderId: $sellerOrderId,
                carrierName: (string) $this->shipmentLookup->getShipmentById($reverseShipmentId)?->provider,
                claimType: 'lost_shipment',
                claimAmountPaise: $value,
                bearer: (string) config('returns.courier_loss_bearer', 'platform'),
                notes: 'Return pickup lost by the courier after pickup',
            );

            PlatformLoss::create([
                'loss_type' => PlatformLoss::TYPE_LOST_RETURN,
                'seller_order_id' => $sellerOrderId,
                'shipment_id' => $reverseShipmentId,
                'courier_claim_id' => $claim->id,
                'loss_amount_paise' => $refund->amountPaise,
                'declared_value_paise' => $value,
                'notes' => 'Customer refunded for a return the courier lost',
            ]);

            $request->update([
                'status' => ReturnRequestStatus::Resolved,
                'qc_outcome' => 'lost_refund',
                'qc_fault' => ReturnFault::CourierFault->value,
                'refund_id' => $refund->id,
            ]);

            $this->settlements->releaseReturnHold($sellerOrderId);
            $this->orderFulfillment->recordReturnRefund($sellerOrderId);

            return $request;
        });

        if ($request) {
            $this->notify($request, 'lost_refund');
        }
    }

    public function replacementShipped(string $replacementSellerOrderId): void
    {
        $request = ReturnRequest::where('replacement_seller_order_id', $replacementSellerOrderId)->first();
        if ($request) {
            $this->notify($request, 'replacement_shipped');
        }
    }

    protected function notify(ReturnRequest $request, string $event, bool $seller = false): void
    {
        $order = $this->orders->forSellerOrder($request->seller_order_id);
        $email = $seller
            ? SellerProfile::find($order?->sellerProfileId)?->user?->email
            : User::find($request->user_id)?->email;

        if ($email) {
            Mail::to($email)->queue(new ReturnUpdateMail($request, $event, $seller, (string) $order?->orderNumber));
        }
    }

    public function hasOpenReturns(int $userId): bool
    {
        return ReturnRequest::where('user_id', $userId)->whereIn('status', ReturnRequestStatus::activeValues())->exists();
    }

    /**
     * @param  list<ReturnRequestStatus>  $allowed
     */
    protected function lockInStatus(string $returnRequestId, array $allowed): ReturnRequest
    {
        /** @var ReturnRequest $request */
        $request = ReturnRequest::lockForUpdate()->findOrFail($returnRequestId);

        if (! in_array($request->status, $allowed, true)) {
            throw new DomainException("This return is already {$request->status->label()}.");
        }

        return $request;
    }
}
