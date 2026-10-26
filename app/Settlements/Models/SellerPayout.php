<?php

namespace App\Settlements\Models;

use App\Settlements\Enums\PayoutStatus;
use App\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $payout_batch_id
 * @property int $seller_profile_id
 * @property int $gross_settlement_amount
 * @property int $carried_balance_deduction
 * @property int $net_amount
 * @property PayoutStatus $status
 * @property string $bank_account_holder
 * @property string $bank_account_number_masked
 * @property string $bank_ifsc
 * @property string $bank_fingerprint
 * @property string|null $utr
 * @property Carbon|null $paid_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property int|null $paid_by_admin_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SellerPayout extends Model
{
    use HasUlids;

    protected $table = 'seller_payouts';

    protected $fillable = [
        'id',
        'payout_batch_id',
        'seller_profile_id',
        'gross_settlement_amount',
        'carried_balance_deduction',
        'net_amount',
        'status',
        'bank_account_holder',
        'bank_account_number_masked',
        'bank_ifsc',
        'bank_fingerprint',
        'utr',
        'paid_at',
        'failed_at',
        'failure_reason',
        'paid_by_admin_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gross_settlement_amount' => 'integer',
            'carried_balance_deduction' => 'integer',
            'net_amount' => 'integer',
            'status' => PayoutStatus::class,
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SellerPayoutBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SellerPayoutBatch::class, 'payout_batch_id');
    }

    /**
     * @return HasMany<SellerOrderSettlement, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(SellerOrderSettlement::class, 'payout_id');
    }

    /**
     * @return HasMany<SellerLedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SellerLedgerEntry::class, 'payout_id');
    }

    public function getNetAmountMoney(): Money
    {
        return Money::fromPaise($this->net_amount);
    }

    public function getGrossSettlementMoney(): Money
    {
        return Money::fromPaise($this->gross_settlement_amount);
    }

    public function getCarriedBalanceMoney(): Money
    {
        return Money::fromPaise($this->carried_balance_deduction);
    }
}
