<?php

namespace App\Fees\Models;

use App\Shared\Enums\FeeScheduleStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property int $version
 * @property FeeScheduleStatus $status
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property float $gst_rate
 * @property float $tcs_rate
 * @property float $tds_standard_rate
 * @property float $tds_no_pan_rate
 * @property int $default_weight_grams
 * @property int|null $created_by_id
 * @property int|null $updated_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, FeeCommissionSlab> $commissionSlabs
 * @property-read Collection<int, FeeClosingSlab> $closingSlabs
 * @property-read Collection<int, FeeShippingSlab> $shippingSlabs
 * @property-read Collection<int, FeeCollectionRule> $collectionRules
 */
class FeeSchedule extends Model
{
    protected $table = 'fee_schedules';

    protected $fillable = [
        'name',
        'version',
        'status',
        'effective_from',
        'effective_to',
        'gst_rate',
        'tcs_rate',
        'tds_standard_rate',
        'tds_no_pan_rate',
        'default_weight_grams',
        'created_by_id',
        'updated_by_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => FeeScheduleStatus::class,
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'gst_rate' => 'float',
            'tcs_rate' => 'float',
            'tds_standard_rate' => 'float',
            'tds_no_pan_rate' => 'float',
            'default_weight_grams' => 'integer',
        ];
    }

    /**
     * @return HasMany<FeeCommissionSlab, $this>
     */
    public function commissionSlabs(): HasMany
    {
        return $this->hasMany(FeeCommissionSlab::class);
    }

    /**
     * @return HasMany<FeeClosingSlab, $this>
     */
    public function closingSlabs(): HasMany
    {
        return $this->hasMany(FeeClosingSlab::class);
    }

    /**
     * @return HasMany<FeeShippingSlab, $this>
     */
    public function shippingSlabs(): HasMany
    {
        return $this->hasMany(FeeShippingSlab::class);
    }

    /**
     * @return HasMany<FeeCollectionRule, $this>
     */
    public function collectionRules(): HasMany
    {
        return $this->hasMany(FeeCollectionRule::class);
    }

    public function isDraft(): bool
    {
        return $this->status === FeeScheduleStatus::Draft;
    }

    public function isActive(): bool
    {
        return $this->status === FeeScheduleStatus::Active;
    }

    public function isArchived(): bool
    {
        return $this->status === FeeScheduleStatus::Archived;
    }
}
