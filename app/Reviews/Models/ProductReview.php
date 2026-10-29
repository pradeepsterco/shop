<?php

namespace App\Reviews\Models;

use App\Models\User;
use App\Reviews\Enums\ReviewStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $reviewable_type
 * @property int $reviewable_id
 * @property int|null $user_id
 * @property string $author_name
 * @property int $rating
 * @property string|null $title
 * @property string $comment
 * @property bool $is_verified_purchase
 * @property ReviewStatus $status
 * @property int|null $product_variant_id
 * @property string|null $order_item_id
 * @property int|null $seller_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class ProductReview extends Model
{
    protected $table = 'product_reviews';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_verified_purchase' => 'boolean',
            'status' => ReviewStatus::class,
            'reviewable_id' => 'integer',
            'product_variant_id' => 'integer',
            'seller_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isApproved(): bool
    {
        return $this->status === ReviewStatus::Approved;
    }
}
