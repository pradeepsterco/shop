<?php

namespace App\Fees\Models;

use App\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_item_id
 * @property string $seller_order_id
 * @property string $order_id
 * @property int $fee_schedule_id
 * @property int $schedule_version
 * @property int $selling_price
 * @property int $qty
 * @property float $product_gst_rate
 * @property int $commission_amount
 * @property int $closing_fee_amount
 * @property int $shipping_fee_amount
 * @property int $collection_fee_amount
 * @property int $gst_on_fees_amount
 * @property int $tcs_amount
 * @property int $tds_amount
 * @property int $total_deductions
 * @property int $net_payout
 * @property int $claimable_credits
 * @property array<string, mixed> $snapshot_data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read FeeSchedule $schedule
 */
class OrderItemFeeSnapshot extends Model
{
    protected $table = 'order_item_fee_snapshots';

    protected $fillable = [
        'order_item_id',
        'seller_order_id',
        'order_id',
        'fee_schedule_id',
        'schedule_version',
        'selling_price',
        'qty',
        'product_gst_rate',
        'commission_amount',
        'closing_fee_amount',
        'shipping_fee_amount',
        'collection_fee_amount',
        'gst_on_fees_amount',
        'tcs_amount',
        'tds_amount',
        'total_deductions',
        'net_payout',
        'claimable_credits',
        'snapshot_data',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_item_id' => 'integer',
            'fee_schedule_id' => 'integer',
            'schedule_version' => 'integer',
            'selling_price' => 'integer',
            'qty' => 'integer',
            'product_gst_rate' => 'float',
            'commission_amount' => 'integer',
            'closing_fee_amount' => 'integer',
            'shipping_fee_amount' => 'integer',
            'collection_fee_amount' => 'integer',
            'gst_on_fees_amount' => 'integer',
            'tcs_amount' => 'integer',
            'tds_amount' => 'integer',
            'total_deductions' => 'integer',
            'net_payout' => 'integer',
            'claimable_credits' => 'integer',
            'snapshot_data' => 'array',
        ];
    }

    /**
     * @return BelongsTo<FeeSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(FeeSchedule::class, 'fee_schedule_id');
    }

    public function lineTotalMoney(): Money
    {
        return Money::fromPaise($this->selling_price * $this->qty);
    }

    public function commissionMoney(): Money
    {
        return Money::fromPaise($this->commission_amount);
    }

    public function closingFeeMoney(): Money
    {
        return Money::fromPaise($this->closing_fee_amount);
    }

    public function shippingFeeMoney(): Money
    {
        return Money::fromPaise($this->shipping_fee_amount);
    }

    public function collectionFeeMoney(): Money
    {
        return Money::fromPaise($this->collection_fee_amount);
    }

    public function gstOnFeesMoney(): Money
    {
        return Money::fromPaise($this->gst_on_fees_amount);
    }

    public function tcsMoney(): Money
    {
        return Money::fromPaise($this->tcs_amount);
    }

    public function tdsMoney(): Money
    {
        return Money::fromPaise($this->tds_amount);
    }

    public function totalDeductionsMoney(): Money
    {
        return Money::fromPaise($this->total_deductions);
    }

    public function netPayoutMoney(): Money
    {
        return Money::fromPaise($this->net_payout);
    }

    public function claimableCreditsMoney(): Money
    {
        return Money::fromPaise($this->claimable_credits);
    }
}
