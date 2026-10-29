<?php

namespace App\Returns\Contracts;

use App\Returns\Enums\ReturnFault;
use App\Returns\Enums\ReturnReason;
use App\Returns\Models\ReturnRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

interface ReturnRequestServiceInterface
{
    /**
     * What can still be returned from a package, per line: units available (delivered − in returns − not delivered),
     * the return deadline (delivery date + the window saved at delivery) and whether the vertical is returnable.
     *
     * @return array<int, array{title: string, available: int, deadline: ?CarbonImmutable, returnable: bool}>
     */
    public function returnableLines(string $sellerOrderId): array;

    /**
     * Customer requests a return of units of one package. Places the return hold on the package settlement.
     * $overrideByAdminId lets an admin create it after the window closed; $overrideReason is required and logged.
     *
     * @param  array<int, int>  $units  order item id => units
     * @param  array<int, UploadedFile>  $photos
     */
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
    ): ReturnRequest;

    public function requestInfo(string $returnRequestId, string $message, int $adminId): ReturnRequest;

    /**
     * Customer answers an information request; the request goes back to review.
     */
    /**
     * @param  array<int, UploadedFile>  $photos  extra photos (JPG/PNG/WebP), at most 5 on the request in total
     */
    public function respond(string $returnRequestId, int $userId, string $response, array $photos = []): ReturnRequest;

    /**
     * Approve: book the return pickup (outside any transaction), or for a missing item reverse the seller's sale and
     * fees for those units and hand them to the refund/reship queue.
     */
    public function approve(string $returnRequestId, int $adminId): ReturnRequest;

    public function reject(string $returnRequestId, string $reason, int $adminId): ReturnRequest;

    /**
     * The return pickup failed for good (or was cancelled): the request closes and the hold is released.
     */
    public function pickupCancelled(string $reverseShipmentId, ?string $reason = null): void;

    /**
     * The return pickup reached the seller.
     */
    public function pickupReceived(string $reverseShipmentId): void;

    /**
     * Seller reports the condition of a received return (notes + up to 5 JPG/PNG/WebP photos, private disk), own
     * packages only, once, before the report deadline.
     *
     * @param  array<int, UploadedFile>  $photos
     */
    public function submitSellerReport(string $returnRequestId, int $sellerProfileId, string $condition, ?string $notes, array $photos = []): ReturnRequest;

    /**
     * Admin QC of a received return, decided once. Pass: restock, then refund or replacement (out of stock becomes a
     * refund). Fail: ship back to the customer, partial refund (<= refundable) or full refund. Settles the seller
     * (sale reversal per unit, fees by fault, return/replacement/ship-back shipping) and releases the return hold.
     */
    public function decideQc(
        string $returnRequestId,
        int $adminId,
        bool $passed,
        ?string $failOutcome = null,
        ?int $partialAmountPaise = null,
        ?ReturnFault $fault = null,
        ?string $notes = null,
    ): ReturnRequest;

    /**
     * The courier lost the return after pickup: the customer is refunded in full; the seller's sale stands; the
     * platform loss and courier claim are recorded.
     */
    public function pickupLost(string $reverseShipmentId): void;

    /**
     * The replacement package was shipped: tell the customer.
     */
    public function replacementShipped(string $replacementSellerOrderId): void;

    /**
     * Any open return for this customer (blocks account deletion).
     */
    public function hasOpenReturns(int $userId): bool;
}
