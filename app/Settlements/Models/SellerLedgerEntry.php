<?php

namespace App\Settlements\Models;

use App\Settlements\Enums\LedgerEntryType;
use App\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $seller_profile_id
 * @property string|null $seller_order_id
 * @property int|null $order_item_id
 * @property string|null $payout_id
 * @property LedgerEntryType $type
 * @property int $amount
 * @property string $description
 * @property string $source_key
 * @property Carbon $created_at
 */
class SellerLedgerEntry extends Model
{
    public $timestamps = false;

    protected $table = 'seller_ledger_entries';

    protected $fillable = [
        'seller_profile_id',
        'seller_order_id',
        'order_item_id',
        'payout_id',
        'type',
        'amount',
        'description',
        'source_key',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'type' => LedgerEntryType::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SellerOrderSettlement, $this>
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(SellerOrderSettlement::class, 'seller_order_id', 'seller_order_id');
    }

    /**
     * @return BelongsTo<SellerPayout, $this>
     */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(SellerPayout::class, 'payout_id');
    }

    public function getAmountMoney(): Money
    {
        return Money::fromPaise(abs($this->amount));
    }
}
