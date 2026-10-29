<?php

namespace App\Orders\Services;

use App\Coupons\Contracts\CouponRedemptionInterface;
use App\Orders\Contracts\SellerOrderFulfillmentInterface;
use App\Orders\DTOs\SellerOrderInvoiceDto;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Events\SellerOrderPartialDelivered;
use App\Orders\Models\CustomerRefundItem;
use App\Orders\Models\OrderStatusHistory;
use App\Orders\Models\SellerInvoiceSequence;
use App\Orders\Models\SellerOrder;
use Carbon\Carbon;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class SellerOrderFulfillmentService implements SellerOrderFulfillmentInterface
{
    public function __construct(
        protected OrderService $orderService,
    ) {}

    /**
     * Calculate Indian Financial Year (starts April 1 in Asia/Kolkata).
     * e.g. 25 Oct 2026 -> '2627', 31 Mar 2027 -> '2627', 01 Apr 2027 -> '2728'.
     */
    public static function calculateFinancialYear(?DateTimeInterface $date = null): string
    {
        $carbon = $date ? Carbon::instance($date)->setTimezone('Asia/Kolkata') : now('Asia/Kolkata');
        $year = (int) $carbon->format('Y');
        $month = (int) $carbon->format('n');

        if ($month >= 4) {
            $startYear = $year;
            $endYear = $year + 1;
        } else {
            $startYear = $year - 1;
            $endYear = $year;
        }

        return sprintf('%02d%02d', $startYear % 100, $endYear % 100);
    }

    /**
     * Get or generate a GST-compliant invoice number for the seller order.
     */
    public function getOrCreateInvoiceNumber(string $sellerOrderId): SellerOrderInvoiceDto
    {
        /** @var SellerOrder $sellerOrder */
        $sellerOrder = SellerOrder::findOrFail($sellerOrderId);

        $today = now('Asia/Kolkata')->startOfDay();
        $fy = self::calculateFinancialYear($today);

        if (! empty($sellerOrder->invoice_number)) {
            return new SellerOrderInvoiceDto(
                sellerOrderId: (string) $sellerOrder->id,
                sellerProfileId: (int) $sellerOrder->seller_profile_id,
                invoiceNumber: (string) $sellerOrder->invoice_number,
                invoiceDate: $sellerOrder->invoice_date ?? $today,
                financialYear: $fy,
                sequenceNumber: 0,
            );
        }

        return DB::transaction(function () use ($sellerOrder, $fy, $today) {
            /** @var SellerInvoiceSequence|null $seqRecord */
            $seqRecord = SellerInvoiceSequence::where('seller_profile_id', $sellerOrder->seller_profile_id)
                ->where('financial_year', $fy)
                ->lockForUpdate()
                ->first();

            if (! $seqRecord) {
                $seqRecord = SellerInvoiceSequence::create([
                    'seller_profile_id' => $sellerOrder->seller_profile_id,
                    'financial_year' => $fy,
                    'last_sequence' => 0,
                ]);

                // Re-lock newly created row
                $seqRecord = SellerInvoiceSequence::where('id', $seqRecord->id)->lockForUpdate()->firstOrFail();
            }

            $nextSeq = $seqRecord->last_sequence + 1;
            $paddedSeq = str_pad((string) $nextSeq, 5, '0', STR_PAD_LEFT);
            $invoiceNumber = "S{$sellerOrder->seller_profile_id}/{$fy}/{$paddedSeq}";

            if (strlen($invoiceNumber) > 16) {
                throw new DomainException("Generated invoice number '{$invoiceNumber}' exceeds maximum allowed GST length of 16 characters.");
            }

            if (! preg_match('/^[A-Za-z0-9\-\/]+$/', $invoiceNumber)) {
                throw new DomainException("Generated invoice number '{$invoiceNumber}' contains invalid characters for GST rules.");
            }

            $seqRecord->update(['last_sequence' => $nextSeq]);

            $sellerOrder->update([
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $today->toDateString(),
            ]);

            return new SellerOrderInvoiceDto(
                sellerOrderId: (string) $sellerOrder->id,
                sellerProfileId: (int) $sellerOrder->seller_profile_id,
                invoiceNumber: $invoiceNumber,
                invoiceDate: $today,
                financialYear: $fy,
                sequenceNumber: $nextSeq,
            );
        });
    }

    public function markShipped(
        string $sellerOrderId,
        string $courierName,
        string $trackingNumber,
        ?DateTimeInterface $shippedAt = null,
    ): void {
        /** @var SellerOrder $sellerOrder */
        $sellerOrder = SellerOrder::findOrFail($sellerOrderId);

        if ($sellerOrder->status === SellerOrderStatus::Shipped) {
            return;
        }

        $this->orderService->transitionSellerOrderStatus(
            sellerOrder: $sellerOrder,
            newStatus: SellerOrderStatus::Shipped,
            actorType: 'shipping_service',
            actorId: 0,
            reason: 'Shipment created and manifested',
            courierName: $courierName,
            trackingNumber: $trackingNumber,
        );
    }

    public function markDelivered(
        string $sellerOrderId,
        ?DateTimeInterface $deliveredAt = null,
    ): void {
        /** @var SellerOrder $sellerOrder */
        $sellerOrder = SellerOrder::findOrFail($sellerOrderId);

        if ($sellerOrder->status === SellerOrderStatus::Delivered) {
            return;
        }

        $this->orderService->transitionSellerOrderStatus(
            sellerOrder: $sellerOrder,
            newStatus: SellerOrderStatus::Delivered,
            actorType: 'shipping_service',
            actorId: 0,
            reason: 'Delivered by carrier',
        );
    }

    public function markReturnedToOrigin(
        string $sellerOrderId,
        ?string $reason = null,
    ): void {
        /** @var SellerOrder $sellerOrder */
        $sellerOrder = SellerOrder::findOrFail($sellerOrderId);

        if ($sellerOrder->status === SellerOrderStatus::ReturnedToOrigin) {
            return;
        }

        $this->orderService->transitionSellerOrderStatus(
            sellerOrder: $sellerOrder,
            newStatus: SellerOrderStatus::ReturnedToOrigin,
            actorType: 'shipping_service',
            actorId: 0,
            reason: $reason ?? 'Carrier marked shipment as Returned to Origin (RTO)',
        );

        $this->restoreCouponUsageIfConfigured($sellerOrder);
    }

    /**
     * RTO and lost packages never reached the customer; both follow the same coupon-restore switch.
     */
    protected function restoreCouponUsageIfConfigured(SellerOrder $sellerOrder): void
    {
        if ($sellerOrder->coupon_discount_total > 0 && config('coupons.restore_usage_on_rto', false)) {
            app(CouponRedemptionInterface::class)->release(
                $sellerOrder->order->order_number,
                (int) $sellerOrder->coupon_discount_total
            );
        }
    }

    public function markLostInTransit(
        string $sellerOrderId,
        ?string $reason = null,
    ): void {
        /** @var SellerOrder $sellerOrder */
        $sellerOrder = SellerOrder::findOrFail($sellerOrderId);

        if ($sellerOrder->status === SellerOrderStatus::LostInTransit) {
            return;
        }

        if ($sellerOrder->status !== SellerOrderStatus::Shipped) {
            throw new DomainException("Only a shipped order can be marked lost in transit (current status: {$sellerOrder->status->label()}).");
        }

        $this->orderService->transitionSellerOrderStatus(
            sellerOrder: $sellerOrder,
            newStatus: SellerOrderStatus::LostInTransit,
            actorType: 'returns_service',
            actorId: 0,
            reason: $reason ?? 'Shipment lost in transit by courier',
        );

        $this->restoreCouponUsageIfConfigured($sellerOrder);
    }

    public function confirmPartialDelivery(
        string $sellerOrderId,
        array $deliveredItemIds,
        array $undeliveredItemIds,
    ): array {
        /** @var SellerOrder $sellerOrder */
        $sellerOrder = SellerOrder::with(['items', 'order'])->findOrFail($sellerOrderId);

        if ($sellerOrder->status !== SellerOrderStatus::Shipped) {
            throw new DomainException("Only a shipped order can be confirmed as partially delivered (current status: {$sellerOrder->status->label()}).");
        }

        $delivered = array_values(array_unique(array_map('intval', $deliveredItemIds)));
        $undelivered = array_values(array_unique(array_map('intval', $undeliveredItemIds)));
        $orderItemIds = $sellerOrder->items->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $submitted = array_merge($delivered, $undelivered);
        sort($submitted);

        if (array_intersect($delivered, $undelivered) !== [] || $submitted !== $orderItemIds) {
            throw new DomainException('Every item of the order must be marked either delivered or not delivered, exactly once.');
        }

        if ($delivered === []) {
            throw new DomainException('No items were delivered; this is not a partial delivery.');
        }

        if ($undelivered === []) {
            $this->markDelivered($sellerOrderId);

            return [];
        }

        // COD is collected only for the delivered items, net of their coupon discount
        $expectedCod = match ($sellerOrder->order->payment_method) {
            PaymentMethod::CashOnDelivery => (int) $sellerOrder->items
                ->whereIn('id', $delivered)
                ->sum(fn ($item) => max(0, (int) $item->line_total - (int) $item->coupon_discount_amount)),
        };

        $sellerOrder->update(['expected_cod_amount_paise' => $expectedCod]);

        $this->orderService->transitionSellerOrderStatus(
            sellerOrder: $sellerOrder,
            newStatus: SellerOrderStatus::PartiallyDelivered,
            actorType: 'returns_service',
            actorId: 0,
            reason: count($delivered).' item(s) delivered, '.count($undelivered).' not delivered',
        );

        // Whole lines are undelivered: every unit of each line
        return $sellerOrder->items
            ->whereIn('id', $undelivered)
            ->mapWithKeys(fn ($item) => [(int) $item->id => ['units' => (int) $item->qty, 'line_qty' => (int) $item->qty]])
            ->all();
    }

    public function recordReturnRefund(string $sellerOrderId): void
    {
        DB::transaction(function () use ($sellerOrderId) {
            /** @var SellerOrder $sellerOrder */
            $sellerOrder = SellerOrder::with('items')->lockForUpdate()->findOrFail($sellerOrderId);

            $allowed = [SellerOrderStatus::Delivered, SellerOrderStatus::PartiallyDelivered, SellerOrderStatus::PartiallyRefunded];
            if (! in_array($sellerOrder->status, $allowed, true)) {
                return;
            }

            $totalUnits = (int) $sellerOrder->items->sum('qty');
            $refundedUnits = (int) CustomerRefundItem::whereIn('order_item_id', $sellerOrder->items->pluck('id'))->sum('units');
            if ($refundedUnits === 0) {
                return;
            }

            $this->orderService->transitionSellerOrderStatus(
                sellerOrder: $sellerOrder,
                newStatus: $refundedUnits >= $totalUnits ? SellerOrderStatus::Refunded : SellerOrderStatus::PartiallyRefunded,
                actorType: 'returns_service',
                actorId: 0,
                reason: "{$refundedUnits} of {$totalUnits} unit(s) refunded after return",
            );
        });
    }

    public function recordReshipDelivered(string $sellerOrderId, int $codCollectedPaise, bool $allItemsDelivered): void
    {
        DB::transaction(function () use ($sellerOrderId, $codCollectedPaise, $allItemsDelivered) {
            /** @var SellerOrder $sellerOrder */
            $sellerOrder = SellerOrder::lockForUpdate()->findOrFail($sellerOrderId);

            if ($codCollectedPaise > 0 && $sellerOrder->expected_cod_amount_paise !== null) {
                $sellerOrder->update(['expected_cod_amount_paise' => $sellerOrder->expected_cod_amount_paise + $codCollectedPaise]);
            }

            if ($allItemsDelivered && $sellerOrder->status === SellerOrderStatus::PartiallyDelivered) {
                $this->orderService->transitionSellerOrderStatus(
                    sellerOrder: $sellerOrder,
                    newStatus: SellerOrderStatus::Delivered,
                    actorType: 'returns_service',
                    actorId: 0,
                    reason: 'Remaining items delivered by reship',
                );
            }
        });
    }

    public function markPartialDelivered(
        string $sellerOrderId,
        ?string $reason = null,
    ): void {
        /** @var SellerOrder $sellerOrder */
        $sellerOrder = SellerOrder::findOrFail($sellerOrderId);

        event(new SellerOrderPartialDelivered(
            sellerOrderId: $sellerOrder->id,
            sellerProfileId: (int) $sellerOrder->seller_profile_id,
            reason: $reason ?? 'Carrier marked package as Partial Delivered',
        ));
    }

    public function revertToPacked(
        string $sellerOrderId,
        ?string $reason = null,
    ): void {
        /** @var SellerOrder $sellerOrder */
        $sellerOrder = SellerOrder::findOrFail($sellerOrderId);

        if ($sellerOrder->status === SellerOrderStatus::Packed) {
            return;
        }

        $oldStatus = $sellerOrder->status;

        DB::transaction(function () use ($sellerOrder, $oldStatus, $reason) {
            $sellerOrder->update([
                'status' => SellerOrderStatus::Packed,
                'courier_name' => null,
                'tracking_number' => null,
                'shipped_at' => null,
            ]);

            OrderStatusHistory::create([
                'seller_order_id' => $sellerOrder->id,
                'from_status' => $oldStatus->value,
                'to_status' => SellerOrderStatus::Packed->value,
                'changed_by_type' => 'shipping_service',
                'changed_by_id' => 0,
                'reason' => $reason ?? 'Shipment cancelled — reverted to packed',
                'created_at' => now(),
            ]);

            $this->orderService->updateParentOrderStatus($sellerOrder->order);
        });
    }
}
