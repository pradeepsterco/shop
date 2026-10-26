<?php

namespace App\Models;

use App\Enums\SellerStatus;
use App\Exceptions\InvalidSellerStatusTransitionException;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\SellerOrder;
use App\Services\ProductBuyBoxService;
use Carbon\CarbonImmutable;
use Database\Factories\SellerProfileFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $user_id
 * @property string $store_name
 * @property string $store_slug
 * @property string $business_type
 * @property string|null $description
 * @property string|null $logo_path
 * @property string|null $banner_path
 * @property string $support_email
 * @property string $support_phone
 * @property string $pickup_address_line1
 * @property string|null $pickup_address_line2
 * @property string $pickup_city
 * @property string $pickup_state
 * @property string $pickup_postal_code
 * @property string $pickup_country
 * @property string $pan
 * @property string|null $gstin
 * @property string $bank_account_holder
 * @property string $bank_account_number
 * @property string $ifsc
 * @property SellerStatus $status
 * @property int $current_step
 * @property string|null $rejection_reason
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $reviewed_by_admin_id
 * @property CarbonImmutable|null $bank_updated_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read Admin|null $reviewer
 * @property-read Collection<int, SellerDocument> $documents
 */
class SellerProfile extends Model
{
    /** @use HasFactory<SellerProfileFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'store_name',
        'store_slug',
        'business_type',
        'description',
        'logo_path',
        'banner_path',
        'support_email',
        'support_phone',
        'pickup_address_line1',
        'pickup_address_line2',
        'pickup_city',
        'pickup_state',
        'pickup_postal_code',
        'pickup_country',
        'pan',
        'gstin',
        'bank_account_holder',
        'bank_account_number',
        'ifsc',
        'status',
        'current_step',
        'rejection_reason',
        'submitted_at',
        'approved_at',
        'reviewed_at',
        'reviewed_by_admin_id',
        'bank_updated_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pan' => 'encrypted',
            'gstin' => 'encrypted',
            'bank_account_number' => 'encrypted',
            'status' => SellerStatus::class,
            'current_step' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'bank_updated_at' => 'datetime',
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
     * @return BelongsTo<Admin, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }

    /**
     * @return HasMany<SellerDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(SellerDocument::class);
    }

    /**
     * Generate a unique store slug from the given name.
     */
    public static function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        if (blank($base)) {
            $base = 'store';
        }

        $slug = $base;
        $counter = 1;

        while (static::where('store_slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    /**
     * Submit application for administrative review.
     */
    public function submit(): void
    {
        if (! in_array($this->status, [SellerStatus::Draft, SellerStatus::Rejected], true)) {
            throw new InvalidSellerStatusTransitionException("Cannot submit application from status [{$this->status->value}].");
        }

        $this->status = SellerStatus::Pending;
        $this->submitted_at = now();
        $this->save();
    }

    /**
     * Approve seller application.
     */
    public function approve(Admin $admin): void
    {
        if ($this->status !== SellerStatus::Pending) {
            throw new InvalidSellerStatusTransitionException("Cannot approve application from status [{$this->status->value}].");
        }

        $this->status = SellerStatus::Approved;
        $this->approved_at = now();
        $this->reviewed_at = now();
        $this->reviewed_by_admin_id = $admin->id;
        $this->rejection_reason = null;
        $this->save();
    }

    /**
     * Reject seller application with a required reason.
     */
    public function reject(Admin $admin, string $reason): void
    {
        if ($this->status !== SellerStatus::Pending) {
            throw new InvalidSellerStatusTransitionException("Cannot reject application from status [{$this->status->value}].");
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidSellerStatusTransitionException('Rejection reason cannot be empty.');
        }

        $this->status = SellerStatus::Rejected;
        $this->rejection_reason = $reason;
        $this->reviewed_at = now();
        $this->reviewed_by_admin_id = $admin->id;
        $this->save();
    }

    /**
     * Suspend an approved seller.
     */
    public function suspend(Admin $admin): void
    {
        if ($this->status !== SellerStatus::Approved) {
            throw new InvalidSellerStatusTransitionException("Cannot suspend seller from status [{$this->status->value}].");
        }

        $this->status = SellerStatus::Suspended;
        $this->reviewed_at = now();
        $this->reviewed_by_admin_id = $admin->id;
        $this->save();
    }

    /**
     * Reactivate a suspended seller.
     */
    public function reactivate(Admin $admin): void
    {
        if ($this->status !== SellerStatus::Suspended) {
            throw new InvalidSellerStatusTransitionException("Cannot reactivate seller from status [{$this->status->value}].");
        }

        $this->status = SellerStatus::Approved;
        $this->reviewed_at = now();
        $this->reviewed_by_admin_id = $admin->id;
        $this->save();
    }

    /**
     * Get the public URL for the store logo.
     */
    public function logoUrl(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return Storage::disk('public')->url($this->logo_path);
    }

    /**
     * Get the public URL for the store banner.
     */
    public function bannerUrl(): ?string
    {
        if (! $this->banner_path) {
            return null;
        }

        return Storage::disk('public')->url($this->banner_path);
    }

    /**
     * Delete all uploaded files associated with this seller profile.
     */
    public function deleteFiles(): void
    {
        if ($this->logo_path && Storage::disk('public')->exists($this->logo_path)) {
            Storage::disk('public')->delete($this->logo_path);
        }

        if ($this->banner_path && Storage::disk('public')->exists($this->banner_path)) {
            Storage::disk('public')->delete($this->banner_path);
        }

        foreach ($this->documents as $document) {
            $document->delete();
        }
    }

    /**
     * @return HasMany<Brand, $this>
     */
    public function brandsRequested(): HasMany
    {
        return $this->hasMany(Brand::class, 'requested_by');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function productsCreated(): HasMany
    {
        return $this->hasMany(Product::class, 'created_by');
    }

    /**
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /**
     * @return HasMany<SellerOrder, $this>
     */
    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class);
    }

    /**
     * Check if seller has open orders preventing account deletion.
     */
    public function hasOpenOrders(): bool
    {
        return $this->sellerOrders()
            ->whereNotIn('status', [SellerOrderStatus::Delivered->value, SellerOrderStatus::Cancelled->value])
            ->exists();
    }

    /**
     * Check if the seller profile has been anonymized upon account deletion.
     */
    public function isAnonymized(): bool
    {
        return $this->store_name === 'Deleted seller'
            || $this->user->name === 'Deleted user';
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::deleting(function (SellerProfile $profile): void {
            if ($profile->hasOpenOrders()) {
                throw new \DomainException('Cannot delete seller account with active or undelivered orders.');
            }

            $profile->deleteFiles();
        });

        static::saved(function (SellerProfile $seller) {
            if ($seller->wasChanged('status')) {
                $productIds = DB::table('listings')
                    ->join('product_variants', 'listings.product_variant_id', '=', 'product_variants.id')
                    ->where('listings.seller_profile_id', $seller->id)
                    ->pluck('product_variants.product_id')
                    ->unique();

                $service = app(ProductBuyBoxService::class);
                foreach ($productIds as $pId) {
                    $service->refresh($pId);
                }
            }
        });
    }
}
