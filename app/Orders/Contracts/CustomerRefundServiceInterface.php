<?php

namespace App\Orders\Contracts;

use App\Orders\DTOs\CustomerRefundDto;

interface CustomerRefundServiceInterface
{
    /**
     * Refund units of a seller order's items for one source (e.g. an undelivered item or a return item).
     * Amounts are coupon-aware per unit; previously refunded units are read from stored refunds;
     * coupon usage is released with the Coupons partial-return rule. One refund per source.
     *
     * @param  array<int, int>  $units  order item id => units to refund now
     * @param  bool  $customerPaid  false when the customer was never charged for these units (status not_collected)
     * @param  int|null  $amountPaise  a smaller amount than the units are worth (partial refund); never more
     */
    public function createRefund(string $sourceType, string $sourceId, string $sellerOrderId, array $units, bool $customerPaid, ?int $amountPaise = null): CustomerRefundDto;

    /**
     * Units of an order item already covered by refunds.
     */
    public function refundedUnits(int $orderItemId): int;

    /**
     * Does the customer still have money owed back (pending or failed refunds)? Blocks account deletion.
     */
    public function hasOpenRefunds(int $userId): bool;

    /**
     * Was the customer charged for units that were never delivered? (COD: no — only delivered items were collected.)
     */
    public function customerChargedForUndeliveredUnits(string $sellerOrderId): bool;

    /**
     * What the customer still owes for these undelivered units, after their coupon allocation (COD to collect on a
     * reship); 0 when nothing is owed.
     *
     * @param  array<int, int>  $units  order item id => units
     */
    public function amountOwedForUnits(string $sellerOrderId, array $units): int;

    /**
     * What these units are worth to the customer after their coupon allocation (whether or not it is owed), e.g. the
     * value declared to the courier for a reship.
     *
     * @param  array<int, int>  $units  order item id => units
     */
    public function unitsValue(string $sellerOrderId, array $units): int;

    /**
     * Customer sets where the refund goes (UPI or bank). Validated; stored encrypted. A failed refund goes
     * back to pending for retry.
     *
     * @param  array<string, mixed>  $details  payout_method (upi|bank), upi_id | bank_account_holder, bank_account_number, bank_ifsc
     */
    public function updatePayoutDetails(string $refundId, int $userId, array $details): CustomerRefundDto;

    /**
     * Admin records a manual payout with its UTR. The customer is notified.
     */
    public function markPaid(string $refundId, string $utr, int $adminId): CustomerRefundDto;

    /**
     * Admin records a failed payout. Customer and refund managers are notified; the customer can update details to retry.
     */
    public function markFailed(string $refundId, string $reason, int $adminId): CustomerRefundDto;
}
