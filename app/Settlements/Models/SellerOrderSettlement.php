<?php

namespace App\Settlements\Models;

use App\Settlements\Enums\SettlementStatus;
use App\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $seller_order_id
 * @property string $order_id
 * @property int $seller_profile_id
 * @property int $gross_amount
 * @property int $total_deductions
 * @property int $net_payout
 * @property int $claimable_credits
 * @property SettlementStatus $status
 * @property bool $is_partial_delivery_hold
 * @property bool $is_return_hold
 * @property string $kind
 * @property string $reference_id
 * @property int $return_window_days
 * @property Carbon|null $hold_until
 * @property string|null $payout_id
 * @property Carbon|null $paid_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SellerOrderSettlement extends Model
{
    use HasUlids;

    protected $table = 'seller_order_settlements';

    public const KIND_ORDER = 'order';

    public const KIND_RESHIP = 'reship';

    public const KIND_RETURN = 'return';

    protected $attributes = [
        'kind' => self::KIND_ORDER,
        'reference_id' => '',
        'claimable_credits' => 0,
        'is_partial_delivery_hold' => false,
        'is_return_hold' => false,
    ];

    protected $fillable = [
        'id',
        'seller_order_id',
        'kind',
        'reference_id',
        'order_id',
        'seller_profile_id',
        'gross_amount',
        'total_deductions',
        'net_payout',
        'claimable_credits',
        'status',
        'is_partial_delivery_hold',
        'is_return_hold',
        'return_window_days',
        'hold_until',
        'payout_id',
        'paid_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gross_amount' => 'integer',
            'total_deductions' => 'integer',
            'net_payout' => 'integer',
            'claimable_credits' => 'integer',
            'status' => SettlementStatus::class,
            'is_partial_delivery_hold' => 'boolean',
            'is_return_hold' => 'boolean',
            'return_window_days' => 'integer',
            'hold_until' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SellerLedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SellerLedgerEntry::class, 'seller_order_id', 'seller_order_id');
    }

    /**
     * @return BelongsTo<SellerPayout, $this>
     */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(SellerPayout::class, 'payout_id');
    }

    public function getGrossMoney(): Money
    {
        return Money::fromPaise($this->gross_amount);
    }

    public function getDeductionsMoney(): Money
    {
        return Money::fromPaise($this->total_deductions);
    }

    public function getNetPayoutMoney(): Money
    {
        return Money::fromPaise($this->net_payout);
    }

    public function getClaimableCreditsMoney(): Money
    {
        return Money::fromPaise($this->claimable_credits);
    }

    /**
     * The seller order's own settlement (not a reship settlement).
     *
     * @param  Builder<SellerOrderSettlement>  $query
     * @return Builder<SellerOrderSettlement>
     */
    public function scopeForSellerOrder(Builder $query, string $sellerOrderId): Builder
    {
        return $query->where('seller_order_id', $sellerOrderId)->where('kind', self::KIND_ORDER);
    }

    /**
     * The settlement of one reship of undelivered items, keyed by the reship shipment.
     *
     * @param  Builder<SellerOrderSettlement>  $query
     * @return Builder<SellerOrderSettlement>
     */
    public function scopeForReship(Builder $query, string $reshipShipmentId): Builder
    {
        return $query->where('kind', self::KIND_RESHIP)->where('reference_id', $reshipShipmentId);
    }
}
