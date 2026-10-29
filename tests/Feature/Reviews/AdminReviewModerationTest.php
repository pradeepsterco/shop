<?php

use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Vertical;
use App\Reviews\Enums\ReviewStatus;
use App\Reviews\Models\ProductReview;
use App\Reviews\Models\ReviewSummary;
use App\Reviews\Services\ProductReviewService;
use App\Reviews\Services\ReviewSummaryService;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Artisan::call('permissions:sync');

    $category = Category::create([
        'name' => 'Gadgets',
        'slug' => 'gadgets-'.uniqid(),
        'is_active' => true,
    ]);

    $vertical = Vertical::create([
        'category_id' => $category->id,
        'name' => 'Tech',
        'slug' => 'tech-'.uniqid(),
        'hsn_code' => '8518',
        'gst_rate' => 18.0,
        'is_active' => true,
    ]);

    $brand = Brand::create([
        'name' => 'Brand Tech',
        'slug' => 'brand-tech-'.uniqid(),
    ]);

    $this->product = Product::create([
        'vertical_id' => $vertical->id,
        'brand_id' => $brand->id,
        'title' => 'Mechanical Wireless Keyboard',
        'slug' => 'mechanical-wireless-keyboard-'.uniqid(),
        'description' => 'A great mechanical keyboard',
    ]);
});

test('guest cannot access admin reviews page and is redirected to login', function () {
    $this->get(route('admin.reviews.index'))
        ->assertRedirect(route('admin.login'));
});

test('admin without reviews.view permission receives forbidden response', function () {
    $staff = Admin::factory()->create(['is_active' => true]);
    $role = Role::findOrCreate('No Review Permissions', 'admin');
    $staff->syncRoles([$role]);

    $this->actingAs($staff, 'admin')
        ->get(route('admin.reviews.index'))
        ->assertForbidden();
});

test('inactive admin receives redirect or forbidden and cannot access reviews', function () {
    $admin = Admin::factory()->create(['is_active' => false]);
    $role = Role::findOrCreate('Super Admin', 'admin');
    $admin->syncRoles([$role]);

    // EnsureAdminIsActive middleware blocks and redirects inactive admin sessions
    $this->actingAs($admin, 'admin')
        ->get(route('admin.reviews.index'))
        ->assertRedirect(route('admin.login'))
        ->assertSessionHasErrors('email');

    // Livewire component direct mount check
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.reviews-index')
        ->assertForbidden();
});

test('admin with reviews.view permission can view the reviews moderation page', function () {
    $staff = Admin::factory()->create(['is_active' => true]);
    $role = Role::findOrCreate('Review Viewer', 'admin');
    $role->syncPermissions([AdminPermissions::REVIEWS_VIEW]);
    $staff->syncRoles([$role]);

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $this->product->id,
        'author_name' => 'Alice Walker',
        'rating' => 5,
        'title' => 'Fantastic Typing Experience',
        'comment' => 'Smooth switches and solid aluminum chassis.',
        'is_verified_purchase' => true,
        'status' => ReviewStatus::Approved,
    ]);

    $this->actingAs($staff, 'admin')
        ->get(route('admin.reviews.index'))
        ->assertOk()
        ->assertSee('Product Reviews')
        ->assertSee('Alice Walker')
        ->assertSee('Fantastic Typing Experience');
});

test('staff with only reviews.view cannot perform moderation actions', function () {
    $staff = Admin::factory()->create(['is_active' => true]);
    $role = Role::findOrCreate('Review Viewer Only', 'admin');
    $role->syncPermissions([AdminPermissions::REVIEWS_VIEW]);
    $staff->syncRoles([$role]);

    $review = ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $this->product->id,
        'author_name' => 'Bob Builder',
        'rating' => 4,
        'title' => 'Pretty Good',
        'comment' => 'Good value for money.',
        'is_verified_purchase' => true,
        'status' => ReviewStatus::Approved,
    ]);

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.reviews-index')
        ->call('hideReview', $review->id)
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.reviews-index')
        ->call('rejectReview', $review->id)
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.reviews-index')
        ->call('restoreReview', $review->id)
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.reviews-index')
        ->call('confirmDelete', $review->id)
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')
        ->test('pages::admin.reviews-index')
        ->set('deletingReviewId', $review->id)
        ->call('deleteConfirmed')
        ->assertForbidden();
});

test('super admin can hide an approved review, recalculating rating aggregates and logging activity', function () {
    $admin = Admin::factory()->create(['is_active' => true]);
    $role = Role::findOrCreate('Super Admin', 'admin');
    $admin->syncRoles([$role]);

    $review1 = ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $this->product->id,
        'author_name' => 'Charlie Day',
        'rating' => 5,
        'comment' => 'Five stars all day.',
        'is_verified_purchase' => true,
        'status' => ReviewStatus::Approved,
    ]);

    $review2 = ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $this->product->id,
        'author_name' => 'Dennis Reynolds',
        'rating' => 1,
        'comment' => 'Golden god does not approve.',
        'is_verified_purchase' => true,
        'status' => ReviewStatus::Approved,
    ]);

    app(ProductReviewService::class)->getSummary($this->product->id);
    app(ReviewSummaryService::class)->recalculate('product', $this->product->id);

    $summaryBefore = ReviewSummary::where('reviewable_id', $this->product->id)->first();
    expect($summaryBefore->rating_count)->toBe(2)
        ->and($summaryBefore->rating_avg)->toBe(3.0);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.reviews-index')
        ->call('hideReview', $review2->id)
        ->assertHasNoErrors();

    $review2->refresh();
    expect($review2->status)->toBe(ReviewStatus::Hidden);

    $summaryAfter = ReviewSummary::where('reviewable_id', $this->product->id)->first();
    expect($summaryAfter->rating_count)->toBe(1)
        ->and($summaryAfter->rating_avg)->toBe(5.0)
        ->and($summaryAfter->star_1)->toBe(0)
        ->and($summaryAfter->star_5)->toBe(1);

    $log = AdminActivityLog::where('action', 'review.hidden')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->admin_id)->toBe($admin->id)
        ->and($log->subject_id)->toBe($review2->id)
        ->and($log->properties['previous_status'])->toBe('approved')
        ->and($log->properties['new_status'])->toBe('hidden');
});

test('super admin can restore a hidden review to approved status', function () {
    $admin = Admin::factory()->create(['is_active' => true]);
    $role = Role::findOrCreate('Super Admin', 'admin');
    $admin->syncRoles([$role]);

    $review = ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $this->product->id,
        'author_name' => 'Frank Reynolds',
        'rating' => 4,
        'comment' => 'Trash man approved.',
        'is_verified_purchase' => true,
        'status' => ReviewStatus::Hidden,
    ]);

    app(ReviewSummaryService::class)->recalculate('product', $this->product->id);
    $summaryBefore = ReviewSummary::where('reviewable_id', $this->product->id)->first();
    expect($summaryBefore->rating_count)->toBe(0);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.reviews-index')
        ->call('restoreReview', $review->id)
        ->assertHasNoErrors();

    $review->refresh();
    expect($review->status)->toBe(ReviewStatus::Approved);

    $summaryAfter = ReviewSummary::where('reviewable_id', $this->product->id)->first();
    expect($summaryAfter->rating_count)->toBe(1)
        ->and($summaryAfter->rating_avg)->toBe(4.0);

    $log = AdminActivityLog::where('action', 'review.restored')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->properties['new_status'])->toBe('approved');
});

test('super admin can reject a review and restore it later', function () {
    $admin = Admin::factory()->create(['is_active' => true]);
    $role = Role::findOrCreate('Super Admin', 'admin');
    $admin->syncRoles([$role]);

    $review = ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $this->product->id,
        'author_name' => 'Dee Reynolds',
        'rating' => 2,
        'comment' => 'Suspicious spam review.',
        'is_verified_purchase' => true,
        'status' => ReviewStatus::Approved,
    ]);

    app(ReviewSummaryService::class)->recalculate('product', $this->product->id);

    // 1. Reject
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.reviews-index')
        ->call('rejectReview', $review->id)
        ->assertHasNoErrors();

    $review->refresh();
    expect($review->status)->toBe(ReviewStatus::Rejected);

    $summaryRejected = ReviewSummary::where('reviewable_id', $this->product->id)->first();
    expect($summaryRejected->rating_count)->toBe(0);

    $rejectLog = AdminActivityLog::where('action', 'review.rejected')->latest('id')->first();
    expect($rejectLog)->not->toBeNull()
        ->and($rejectLog->properties['new_status'])->toBe('rejected');

    // 2. Restore
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.reviews-index')
        ->call('restoreReview', $review->id)
        ->assertHasNoErrors();

    $review->refresh();
    expect($review->status)->toBe(ReviewStatus::Approved);

    $summaryRestored = ReviewSummary::where('reviewable_id', $this->product->id)->first();
    expect($summaryRestored->rating_count)->toBe(1);
});

test('super admin can permanently delete a review with modal confirmation', function () {
    $admin = Admin::factory()->create(['is_active' => true]);
    $role = Role::findOrCreate('Super Admin', 'admin');
    $admin->syncRoles([$role]);

    $review = ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $this->product->id,
        'author_name' => 'Mac McDonald',
        'rating' => 3,
        'comment' => 'Cultivating mass.',
        'is_verified_purchase' => true,
        'status' => ReviewStatus::Approved,
    ]);

    app(ReviewSummaryService::class)->recalculate('product', $this->product->id);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.reviews-index')
        ->call('confirmDelete', $review->id)
        ->assertSet('showDeleteModal', true)
        ->assertSet('deletingReviewId', $review->id)
        ->call('deleteConfirmed')
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingReviewId', null);

    expect(ProductReview::find($review->id))->toBeNull();

    $summaryAfter = ReviewSummary::where('reviewable_id', $this->product->id)->first();
    expect($summaryAfter->rating_count)->toBe(0)
        ->and($summaryAfter->rating_avg)->toBe(0.0);

    $deleteLog = AdminActivityLog::where('action', 'review.deleted')->latest('id')->first();
    expect($deleteLog)->not->toBeNull()
        ->and($deleteLog->properties['product_id'])->toBe($this->product->id);
});

test('filtering reviews by status, rating, and verified purchase', function () {
    $admin = Admin::factory()->create(['is_active' => true]);
    $role = Role::findOrCreate('Super Admin', 'admin');
    $admin->syncRoles([$role]);

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $this->product->id,
        'author_name' => 'FiveStarApproved',
        'rating' => 5,
        'comment' => 'Approved 5 star review',
        'is_verified_purchase' => true,
        'status' => ReviewStatus::Approved,
    ]);

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $this->product->id,
        'author_name' => 'OneStarHidden',
        'rating' => 1,
        'comment' => 'Hidden 1 star review',
        'is_verified_purchase' => false,
        'status' => ReviewStatus::Hidden,
    ]);

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $this->product->id,
        'author_name' => 'TwoStarRejected',
        'rating' => 2,
        'comment' => 'Rejected 2 star review',
        'is_verified_purchase' => true,
        'status' => ReviewStatus::Rejected,
    ]);

    // Filter by status = hidden
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.reviews-index')
        ->set('status', 'hidden')
        ->assertSee('OneStarHidden')
        ->assertDontSee('FiveStarApproved')
        ->assertDontSee('TwoStarRejected');

    // Filter by rating = 5
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.reviews-index')
        ->set('rating', '5')
        ->assertSee('FiveStarApproved')
        ->assertDontSee('OneStarHidden')
        ->assertDontSee('TwoStarRejected');

    // Filter by verified = no
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.reviews-index')
        ->set('verified', 'no')
        ->assertSee('OneStarHidden')
        ->assertDontSee('FiveStarApproved');
});

test('searching reviews by product title, author name, or comment', function () {
    $admin = Admin::factory()->create(['is_active' => true]);
    $role = Role::findOrCreate('Super Admin', 'admin');
    $admin->syncRoles([$role]);

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $this->product->id,
        'author_name' => 'SpecialAuthorKey',
        'rating' => 5,
        'comment' => 'Standard comment here',
        'is_verified_purchase' => true,
        'status' => ReviewStatus::Approved,
    ]);

    ProductReview::create([
        'reviewable_type' => 'product',
        'reviewable_id' => $this->product->id,
        'author_name' => 'OrdinaryAuthor',
        'rating' => 4,
        'comment' => 'Contains uniquephrase123 embedded in text',
        'is_verified_purchase' => true,
        'status' => ReviewStatus::Approved,
    ]);

    // Search by author
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.reviews-index')
        ->set('search', 'SpecialAuthorKey')
        ->assertSee('SpecialAuthorKey')
        ->assertDontSee('OrdinaryAuthor');

    // Search by comment
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.reviews-index')
        ->set('search', 'uniquephrase123')
        ->assertSee('OrdinaryAuthor')
        ->assertDontSee('SpecialAuthorKey');

    // Search by product title
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.reviews-index')
        ->set('search', 'Wireless Keyboard')
        ->assertSee('SpecialAuthorKey')
        ->assertSee('OrdinaryAuthor');
});
