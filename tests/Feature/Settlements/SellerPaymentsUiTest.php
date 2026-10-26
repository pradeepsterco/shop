<?php

use App\Models\SellerProfile;
use App\Models\User;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Models\SellerOrderSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('approved seller can view payments index with balances and settlements', function () {
    $user = User::factory()->create(['email' => 'seller-ui@example.com']);
    $seller = SellerProfile::factory()->approved()->create([
        'user_id' => $user->id,
        'store_name' => 'UI Test Store',
        'pan' => 'ABCDE1234F',
    ]);

    SellerOrderSettlement::create([
        'seller_order_id' => 'SO-UI-1',
        'order_id' => 'ORD-UI-1',
        'seller_profile_id' => $seller->id,
        'gross_amount' => 100000,
        'total_deductions' => 20000,
        'net_payout' => 80000,
        'claimable_credits' => 3000,
        'status' => SettlementStatus::OnHold,
        'return_window_days' => 7,
    ]);

    Livewire::actingAs($user, 'web')
        ->test('pages::seller.payments-index')
        ->assertOk()
        ->assertSee('Payments')
        ->assertSee('On Hold (Escrow)')
        ->assertSee('SO-UI-1');
});

test('approved seller can view monthly tax statement', function () {
    $user = User::factory()->create(['email' => 'seller-stmt@example.com']);
    $seller = SellerProfile::factory()->approved()->create([
        'user_id' => $user->id,
        'store_name' => 'Statement Test Store',
    ]);

    Livewire::actingAs($user, 'web')
        ->test('pages::seller.payments-statement')
        ->assertOk()
        ->assertSee('Monthly Financial Statement')
        ->assertSee('Statement Test Store');
});
