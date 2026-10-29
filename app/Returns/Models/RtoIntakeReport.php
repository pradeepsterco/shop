<?php

namespace App\Returns\Models;

use App\Returns\Enums\RtoAdminDecision;
use App\Returns\Enums\RtoConditionStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $seller_order_id
 * @property string $shipment_id
 * @property int $seller_profile_id
 * @property RtoConditionStatus $condition_status
 * @property string|null $notes
 * @property array<string>|null $photos
 * @property Carbon $reported_at
 * @property RtoAdminDecision $admin_decision
 * @property Carbon|null $admin_decided_at
 * @property int|null $admin_id
 * @property string|null $admin_notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RtoIntakeReport extends Model
{
    use HasUlids;

    protected $table = 'rto_intake_reports';

    protected $fillable = [
        'id',
        'seller_order_id',
        'shipment_id',
        'seller_profile_id',
        'condition_status',
        'notes',
        'photos',
        'reported_at',
        'admin_decision',
        'admin_decided_at',
        'admin_id',
        'admin_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'condition_status' => RtoConditionStatus::class,
            'admin_decision' => RtoAdminDecision::class,
            'photos' => 'array',
            'reported_at' => 'datetime',
            'admin_decided_at' => 'datetime',
            'admin_id' => 'integer',
            'seller_profile_id' => 'integer',
        ];
    }
}
