<?php

namespace App\Orders\Models;

use App\Orders\Enums\RefundStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Money owed back to a customer for specific order item units. Payout details are encrypted at rest,
 * hidden from serialization and must never be logged.
 *
 * @property string $id
 * @property string $order_id
 * @property string $seller_order_id
 * @property int $user_id
 * @property string $source_type
 * @property string $source_id
 * @property int $amount_paise
 * @property RefundStatus $status
 * @property string|null $payout_method
 * @property string|null $upi_id
 * @property string|null $bank_account_holder
 * @property string|null $bank_account_number
 * @property string|null $bank_ifsc
 * @property string|null $utr
 * @property string|null $failure_reason
 * @property Carbon|null $paid_at
 * @property Carbon|null $failed_at
 * @property int|null $processed_by_admin_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, CustomerRefundItem> $items
 * @property-read Order $order
 */
class CustomerRefund extends Model
{
    use HasUlids;

    public const METHOD_UPI = 'upi';

    public const METHOD_BANK = 'bank';

    protected $table = 'customer_refunds';

    protected $fillable = [
        'order_id',
        'seller_order_id',
        'user_id',
        'source_type',
        'source_id',
        'amount_paise',
        'status',
        'payout_method',
        'upi_id',
        'bank_account_holder',
        'bank_account_number',
        'bank_ifsc',
        'utr',
        'failure_reason',
        'paid_at',
        'failed_at',
        'processed_by_admin_id',
    ];

    /**
     * Never serialized (arrays, JSON, logs of models).
     *
     * @var list<string>
     */
    protected $hidden = [
        'upi_id',
        'bank_account_holder',
        'bank_account_number',
        'bank_ifsc',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'amount_paise' => 'integer',
            'status' => RefundStatus::class,
            'upi_id' => 'encrypted',
            'bank_account_holder' => 'encrypted',
            'bank_account_number' => 'encrypted',
            'bank_ifsc' => 'encrypted',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'processed_by_admin_id' => 'integer',
        ];
    }

    public function hasPayoutDetails(): bool
    {
        return match ($this->payout_method) {
            self::METHOD_UPI => filled($this->upi_id),
            self::METHOD_BANK => filled($this->bank_account_number) && filled($this->bank_ifsc) && filled($this->bank_account_holder),
            default => false,
        };
    }

    /**
     * Where the money goes. Masked unless $full (admins with refunds.manage).
     */
    public function payoutDestination(bool $full = false): string
    {
        if ($this->payout_method === self::METHOD_UPI && filled($this->upi_id)) {
            if ($full) {
                return "UPI: {$this->upi_id}";
            }

            [$name, $handle] = array_pad(explode('@', (string) $this->upi_id, 2), 2, '');

            return 'UPI: '.Str::substr($name, 0, 2).str_repeat('*', max(2, Str::length($name) - 2)).'@'.$handle;
        }

        if ($this->payout_method === self::METHOD_BANK && filled($this->bank_account_number)) {
            $account = (string) $this->bank_account_number;

            return $full
                ? "Bank: {$this->bank_account_holder}, A/C {$account}, IFSC {$this->bank_ifsc}"
                : 'Bank: A/C ****'.substr($account, -4).', IFSC '.substr((string) $this->bank_ifsc, 0, 4).'*******';
        }

        return 'Not provided';
    }

    /**
     * @return HasMany<CustomerRefundItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CustomerRefundItem::class, 'customer_refund_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
