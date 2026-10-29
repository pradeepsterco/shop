<?php

namespace App\Reviews\Policies;

use App\Models\Admin;
use App\Models\User;
use App\Reviews\Models\ProductReview;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class ProductReviewPolicy
{
    /**
     * Canonical permission name required for admin review moderation.
     */
    public const PERMISSION_MODERATE = 'reviews.manage';

    /**
     * Canonical permission name required for viewing reviews in admin.
     */
    public const PERMISSION_VIEW = 'reviews.view';

    /**
     * Determine if customer can edit their own review.
     */
    public function update(User $user, ProductReview $review): bool
    {
        return $review->user_id !== null && (int) $review->user_id === (int) $user->id;
    }

    /**
     * Determine if admin can view reviews.
     */
    public function viewAny(mixed $admin): bool
    {
        if (! ($admin instanceof Admin) || ! $admin->is_active) {
            return false;
        }

        if ($admin->isSuperAdmin()) {
            return true;
        }

        try {
            return $admin->hasPermissionTo(self::PERMISSION_VIEW, 'admin');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /**
     * Determine if admin can moderate reviews.
     */
    public function moderate(mixed $admin): bool
    {
        if (! ($admin instanceof Admin) || ! $admin->is_active) {
            return false;
        }

        if ($admin->isSuperAdmin()) {
            return true;
        }

        try {
            return $admin->hasPermissionTo(self::PERMISSION_MODERATE, 'admin');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
