<?php

namespace App\Returns\Models;

use App\Returns\Enums\ReturnReason;
use App\Returns\Enums\ReturnRequestStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's request to return units of one seller package.
 *
 * @property string $id
 * @property string $order_id
 * @property string $seller_order_id
 * @property int $user_id
 * @property ReturnReason $reason
 * @property string $preference
 * @property ReturnRequestStatus $status
 * @property string|null $customer_notes
 * @property array<int, string>|null $photos
 * @property string|null $info_request_message
 * @property string|null $customer_response
 * @property string|null $rejection_reason
 * @property string|null $closed_reason
 * @property string|null $reverse_shipment_id
 * @property Carbon|null $received_at
 * @property Carbon|null $report_deadline_at
 * @property string|null $seller_condition
 * @property string|null $seller_report_notes
 * @property array<int, string>|null $seller_report_photos
 * @property Carbon|null $seller_reported_at
 * @property string|null $qc_result
 * @property string|null $qc_outcome
 * @property string|null $qc_fault
 * @property string|null $qc_notes
 * @property int|null $qc_by_admin_id
 * @property Carbon|null $qc_at
 * @property string|null $refund_id
 * @property string|null $replacement_seller_order_id
 * @property string|null $ship_back_shipment_id
 * @property Carbon|null $return_deadline_at
 * @property int|null $window_override_by_admin_id
 * @property int|null $decided_by_admin_id
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ReturnRequestItem> $items
 */
class ReturnRequest extends Model
{
    use HasUlids;

    public const PREFERENCE_REFUND = 'refund';

    public const PREFERENCE_REPLACEMENT = 'replacement';

    public const QC_PASSED = 'passed';

    public const QC_FAILED = 'failed';

    /** Refund source type: one refund per return request. */
    public const REFUND_SOURCE = 'return_request';

    protected $table = 'return_requests';

    protected $fillable = [
        'order_id',
        'seller_order_id',
        'user_id',
        'reason',
        'preference',
        'status',
        'customer_notes',
        'photos',
        'info_request_message',
        'customer_response',
        'rejection_reason',
        'closed_reason',
        'reverse_shipment_id',
        'received_at',
        'report_deadline_at',
        'seller_condition',
        'seller_report_notes',
        'seller_report_photos',
        'seller_reported_at',
        'qc_result',
        'qc_outcome',
        'qc_fault',
        'qc_notes',
        'qc_by_admin_id',
        'qc_at',
        'refund_id',
        'replacement_seller_order_id',
        'ship_back_shipment_id',
        'return_deadline_at',
        'window_override_by_admin_id',
        'decided_by_admin_id',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'reason' => ReturnReason::class,
            'status' => ReturnRequestStatus::class,
            'photos' => 'array',
            'received_at' => 'datetime',
            'report_deadline_at' => 'datetime',
            'seller_report_photos' => 'array',
            'seller_reported_at' => 'datetime',
            'qc_by_admin_id' => 'integer',
            'qc_at' => 'datetime',
            'return_deadline_at' => 'datetime',
            'window_override_by_admin_id' => 'integer',
            'decided_by_admin_id' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ReturnRequestItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnRequestItem::class, 'return_request_id');
    }

    /**
     * Seller report missing past its deadline: the admin decides QC without it.
     */
    public function sellerReportMissed(): bool
    {
        return $this->seller_reported_at === null && $this->report_deadline_at !== null && now()->greaterThan($this->report_deadline_at);
    }

    /**
     * @return array<int, array{units: int, line_qty: int}>
     */
    public function lines(): array
    {
        return $this->items->mapWithKeys(fn (ReturnRequestItem $item) => [
            $item->order_item_id => ['units' => $item->units, 'line_qty' => $item->line_qty],
        ])->all();
    }
}
