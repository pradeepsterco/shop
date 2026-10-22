<?php

use App\Enums\BrandStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use Livewire\Livewire;

test('seller can view their products list and filter by status', function () {
    $user1 = User::factory()->create();
    $seller1 = SellerProfile::factory()->create([
        'user_id' => $user1->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Seller One Store',
    ]);

    $user2 = User::factory()->create();
    $seller2 = SellerProfile::factory()->create([
        'user_id' => $user2->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Seller Two Store',
    ]);

    $cat = Category::create(['name' => 'Fashion']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Shirt', 'hsn_code' => '6105', 'gst_rate' => 5]);
    $brand = Brand::create(['name' => 'BrandOne', 'status' => BrandStatus::Approved]);

    // Product created by seller 1
    $prodSeller1 = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Seller One Linen Shirt',
        'description' => 'Premium linen casual shirt',
        'created_by' => $seller1->id,
        'qc_status' => ProductQcStatus::QcPending,
    ]);

    // Product created by seller 2
    $prodSeller2 = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Seller Two Silk Shirt',
        'description' => 'Premium silk shirt',
        'created_by' => $seller2->id,
        'qc_status' => ProductQcStatus::QcPending,
    ]);

    // Seller 1 visits products page
    $response = $this->actingAs($user1)->get(route('seller.products.index'));
    $response->assertOk()
        ->assertSee('Seller One Linen Shirt')
        ->assertDontSee('Seller Two Silk Shirt');

    // Livewire tests for seller 1
    Livewire::actingAs($user1)
        ->test('pages::seller.products-index')
        ->assertSee('Seller One Linen Shirt')
        ->assertDontSee('Seller Two Silk Shirt')
        ->set('status_filter', 'approved')
        ->assertDontSee('Seller One Linen Shirt');
});

test('seller sees rejection feedback on rejected products', function () {
    $user = User::factory()->create();
    $seller = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'status' => SellerStatus::Approved,
    ]);

    $cat = Category::create(['name' => 'Home']);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Bedsheets', 'hsn_code' => '6302', 'gst_rate' => 12]);
    $brand = Brand::create(['name' => 'HomeComfort', 'status' => BrandStatus::Approved]);

    $rejectedProd = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Cotton Double Bedsheet',
        'description' => 'Soft bedsheet set',
        'created_by' => $seller->id,
        'qc_status' => ProductQcStatus::Rejected,
        'qc_notes' => 'Images must show full fabric pattern and resolution is too low.',
    ]);

    Livewire::actingAs($user)
        ->test('pages::seller.products-index')
        ->assertSee('Cotton Double Bedsheet')
        ->assertSee('Images must show full fabric pattern and resolution is too low.')
        ->call('openDetailModal', $rejectedProd->id)
        ->assertSee('Rejection Feedback from Marketplace QC Team');
});
