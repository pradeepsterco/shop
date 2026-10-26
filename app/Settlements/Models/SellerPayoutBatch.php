<?php

namespace App\Settlements\Models;

use App\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $batch_number
 * @property Carbon $cutoff_date
 * @property string $status
 * @property int $total_sellers_count
 * @property int $total_amount
 * @property int|null $created_by_admin_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SellerPayoutBatch extends Model
{
    use HasUlids;

    protected $table = 'seller_payout_batches';

    protected $fillable = [
        'id',
        'batch_number',
        'cutoff_date',
        'status',
        'total_sellers_count',
        'total_amount',
        'created_by_admin_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date',
            'total_sellers_count' => 'integer',
            'total_amount' => 'integer',
        ];
    }

    /**
     * @return HasMany<SellerPayout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(SellerPayout::class, 'payout_batch_id');
    }

    public function getTotalAmountMoney(): Money
    {
        return Money::fromPaise($this->total_amount);
    }
}
