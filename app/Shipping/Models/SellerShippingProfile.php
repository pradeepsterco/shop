<?php

namespace App\Shipping\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $seller_profile_id
 * @property string $provider
 * @property string $warehouse_alias
 * @property bool $is_registered
 * @property bool $phone_verified
 * @property string $registration_status
 * @property Carbon|null $last_registered_at
 * @property string|null $error_message
 * @property int|null $pincode
 * @property string|null $address_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SellerShippingProfile extends Model
{
    protected $table = 'seller_shipping_profiles';

    protected $fillable = [
        'seller_profile_id',
        'provider',
        'warehouse_alias',
        'is_registered',
        'phone_verified',
        'registration_status',
        'last_registered_at',
        'error_message',
        'pincode',
        'address_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seller_profile_id' => 'integer',
            'is_registered' => 'boolean',
            'phone_verified' => 'boolean',
            'pincode' => 'integer',
            'last_registered_at' => 'datetime',
        ];
    }
}
