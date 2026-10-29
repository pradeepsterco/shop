<?php

use App\Models\Admin;
use App\Models\User;
use App\Reviews\Models\ProductReview;
use App\Reviews\Policies\ProductReviewPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('policy allows author to update their own review and denies others', function () {
    $policy = new ProductReviewPolicy;

    $author = User::factory()->create();
    $otherUser = User::factory()->create();

    $review = new ProductReview([
        'user_id' => $author->id,
        'reviewable_type' => 'product',
        'reviewable_id' => 1,
    ]);

    expect($policy->update($author, $review))->toBeTrue();
    expect($policy->update($otherUser, $review))->toBeFalse();

    // Orphaned or anonymized user cannot be updated by normal user
    $orphanedReview = new ProductReview([
        'user_id' => null,
        'reviewable_type' => 'product',
        'reviewable_id' => 1,
    ]);
    expect($policy->update($author, $orphanedReview))->toBeFalse();
});

test('policy allows super admin and staff with reviews.manage permission to moderate without constant errors', function () {
    $policy = new ProductReviewPolicy;

    // Super admin via role
    Role::findOrCreate('Super Admin', 'admin');
    $superAdmin = Admin::factory()->create([
        'is_active' => true,
    ]);
    $superAdmin->assignRole('Super Admin');
    expect($policy->moderate($superAdmin))->toBeTrue();

    // Staff without permission
    $staffWithoutPerm = Admin::factory()->create([
        'is_active' => true,
    ]);
    expect($policy->moderate($staffWithoutPerm))->toBeFalse();

    // Staff with canonical moderation permission
    Permission::findOrCreate(ProductReviewPolicy::PERMISSION_MODERATE, 'admin');
    $staffWithPerm = Admin::factory()->create([
        'is_active' => true,
    ]);
    $staffWithPerm->givePermissionTo(ProductReviewPolicy::PERMISSION_MODERATE);

    expect($policy->moderate($staffWithPerm))->toBeTrue();
});
