<?php

namespace App\Orders\Services;

use App\Orders\Contracts\SellerOrderFulfillmentInterface;
use App\Orders\DTOs\SellerOrderInvoiceDto;
use App\Orders\Enums\SellerOrderStatus;
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
