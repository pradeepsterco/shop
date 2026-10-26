<?php

namespace App\Models;

use App\Enums\ListingStatus;
use App\Enums\SellerStatus;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;
use App\Services\ProductBuyBoxService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $must_change_password
 * @property bool $is_active
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SellerProfile|null $sellerProfile
 */
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * @return HasOne<SellerProfile, $this>
     */
    public function sellerProfile(): HasOne
    {
        return $this->hasOne(SellerProfile::class);
    }

    /**
     * @return HasOne<Cart, $this>
     */
    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    /**
     * @return HasMany<CustomerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Check if user has active/open orders preventing account deletion.
     */
    public function hasOpenOrders(): bool
    {
        return $this->orders()
            ->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value])
            ->exists();
    }

    /**
     * Determine if the user is an approved seller.
     */
    public function isSeller(): bool
    {
        return $this->sellerProfile?->status === SellerStatus::Approved;
    }

    /**
     * Check if user account has been anonymized.
     */
    public function isAnonymized(): bool
    {
        return $this->name === 'Deleted user' || str_starts_with($this->email, 'deleted+');
    }

    /**
     * Check if user has any historical orders as customer or seller.
     */
    public function hasAnyOrders(): bool
    {
        return $this->orders()->exists()
            || ($this->sellerProfile !== null && $this->sellerProfile->sellerOrders()->exists());
    }

    /**
     * Anonymize the user account and associated resources when orders exist.
     */
    public function anonymize(): void
    {
        if ($this->hasOpenOrders() || ($this->sellerProfile && $this->sellerProfile->hasOpenOrders())) {
            throw new \DomainException('Cannot delete account while you have active or undelivered orders (as customer or seller).');
        }

        DB::transaction(function () {
            // Delete customer addresses
            $this->addresses()->delete();

            // Delete cart
            if ($this->cart) {
                $this->cart->items()->delete();
                $this->cart->delete();
            }

            // Delete checkout intents
            DB::table('checkout_intents')->where('user_id', $this->id)->delete();

            // Scrub or delete seller profile
            if ($this->sellerProfile) {
                if ($this->sellerProfile->sellerOrders()->exists()) {
                    $this->sellerProfile->deleteFiles();

                    // Deactivate listings so they no longer appear on storefront
                    $this->sellerProfile->listings()->update(['status' => ListingStatus::Inactive]);

                    $this->sellerProfile->update([
                        'store_name' => 'Deleted seller',
                        'support_email' => "deleted+seller{$this->sellerProfile->id}@invalid",
                        'support_phone' => '0000000000',
                        'pan' => 'XXXXX0000X',
                        'gstin' => null,
                        'bank_account_holder' => 'Deleted Seller',
                        'bank_account_number' => '000000000000',
                        'ifsc' => 'XXXX0000000',
                        'status' => SellerStatus::Suspended,
                        'description' => null,
                        'logo_path' => null,
                        'banner_path' => null,
                        'pickup_address_line1' => 'Scrubbed',
                        'pickup_address_line2' => null,
                        'pickup_city' => 'Scrubbed',
                        'pickup_state' => 'Scrubbed',
                        'pickup_postal_code' => '000000',
                    ]);

                    // Refresh storefront buy boxes
                    $productIds = DB::table('listings')
                        ->join('product_variants', 'listings.product_variant_id', '=', 'product_variants.id')
                        ->where('listings.seller_profile_id', $this->sellerProfile->id)
                        ->pluck('product_variants.product_id')
                        ->unique();

                    $service = app(ProductBuyBoxService::class);
                    foreach ($productIds as $pId) {
                        $service->refresh($pId);
                    }
                } else {
                    $this->sellerProfile->delete();
                }
            }

            // Scrub user personal details
            $this->forceFill([
                'name' => 'Deleted user',
                'email' => "deleted+{$this->id}@invalid",
                'phone' => null,
                'password' => Hash::make(Str::random(40)),
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'must_change_password' => false,
                'is_active' => false,
            ])->save();

            // Clear sessions
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $this->id)->delete();
            }
        });
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saved(function (User $user): void {
            if (! $user->wasRecentlyCreated && $user->wasChanged('password')) {
                DB::table('customer_welcome_tokens')
                    ->where('email', $user->email)
                    ->orWhere('email', $user->getOriginal('email'))
                    ->delete();
            }
        });

        static::deleting(function (User $user): void {
            if ($user->hasOpenOrders() || ($user->sellerProfile && $user->sellerProfile->hasOpenOrders())) {
                throw new \DomainException('Cannot delete customer account with active orders.');
            }

            if ($user->hasAnyOrders()) {
                throw new \DomainException('Cannot delete customer account with historical orders. Account must be anonymized.');
            }

            $user->sellerProfile?->delete();
        });
    }
}
