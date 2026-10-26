<?php

namespace App\Fees\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $fee_schedule_id
 * @property int $min_price
 * @property int|null $max_price
 * @property int $fee_amount
 * @property-read FeeSchedule $schedule
 */
class FeeClosingSlab extends Model
{
    protected $table = 'fee_closing_slabs';

    protected $fillable = [
        'fee_schedule_id',
        'min_price',
        'max_price',
        'fee_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fee_schedule_id' => 'integer',
            'min_price' => 'integer',
            'max_price' => 'integer',
            'fee_amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<FeeSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(FeeSchedule::class, 'fee_schedule_id');
    }
}
