<?php

namespace App\Checkout\Models;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string $source
 * @property array<int, array<string, mixed>> $lines
 * @property string $status
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class CheckoutIntent extends Model
{
    use HasUlids;

    protected $table = 'checkout_intents';

    protected $fillable = [
        'id',
        'user_id',
        'source',
        'lines',
        'status',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && ! $this->isExpired();
    }

    /**
     * Get redirect destination URL when intent is expired.
     */
    public function getExpiredRedirectUrl(): string
    {
        if ($this->source === 'cart') {
            return route('cart');
        }

        // Buy now fallback to product page if line exists
        $lines = $this->lines;
        $firstLine = isset($lines[0]) ? $lines[0] : null;
        if ($firstLine && ! empty($firstLine['listing_id'])) {
            $listingId = (int) $firstLine['listing_id'];
            $listing = Listing::with(['variant.product'])->find($listingId);
            if ($listing instanceof Listing) {
                return route('product.show', [
                    'slug' => $listing->variant->product->slug,
                    'upid' => $listing->variant->upid,
                ]);
            }
        }

        return route('catalog.index');
    }
}
