<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $phone
 * @property string $pincode
 * @property string $locality
 * @property string $address_line
 * @property string $city
 * @property string $state
 * @property string|null $landmark
 * @property string|null $alternate_phone
 * @property string $type
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class CustomerAddress extends Model
{
    protected $table = 'customer_addresses';

    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'pincode',
        'locality',
        'address_line',
        'city',
        'state',
        'landmark',
        'alternate_phone',
        'type',
        'is_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Format address for display.
     */
    public function formatted(): string
    {
        $parts = array_filter([
            $this->address_line,
            $this->locality,
            $this->landmark ? "Near {$this->landmark}" : null,
            "{$this->city}, {$this->state} - {$this->pincode}",
        ]);

        return implode(', ', $parts);
    }

    /**
     * Convert address to snapshot array for order storage.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'pincode' => $this->pincode,
            'locality' => $this->locality,
            'address_line' => $this->address_line,
            'city' => $this->city,
            'state' => $this->state,
            'landmark' => $this->landmark,
            'alternate_phone' => $this->alternate_phone,
            'type' => $this->type,
        ];
    }
}
