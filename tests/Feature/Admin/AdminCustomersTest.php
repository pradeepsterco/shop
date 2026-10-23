<?php

use App\Models\Admin;
use App\Models\User;
use Livewire\Livewire;

test('admin can view customer list', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $customer = User::factory()->create(['name' => 'Alice Customer', 'email' => 'alice@example.com']);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.customers'));

    $response->assertOk()
        ->assertSee('Alice Customer')
        ->assertSee('alice@example.com');
});

test('admin can search customers', function () {
    $admin = Admin::factory()->superAdmin()->create();
    User::factory()->create(['name' => 'Alice Customer', 'email' => 'alice@example.com']);
    User::factory()->create(['name' => 'Bob Customer', 'email' => 'bob@example.com']);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.customers')
        ->set('search', 'Alice')
        ->assertSee('Alice Customer')
        ->assertDontSee('Bob Customer')
        ->set('search', 'bob@example.com')
        ->assertSee('Bob Customer')
        ->assertDontSee('Alice Customer');
});

test('admin can view customer details page', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $customer = User::factory()->create([
        'name' => 'Charlie Details',
        'email' => 'charlie@example.com',
        'is_active' => true,
    ]);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.customers.show', $customer));

    $response->assertOk()
        ->assertSee('Customer Details')
        ->assertSee('Charlie Details')
        ->assertSee('charlie@example.com')
        ->assertSee('Active Account');
});

test('admin can toggle customer active status from customers list', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $customer = User::factory()->create(['is_active' => true]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.customers')
        ->call('toggleActive', $customer->id);

    expect($customer->fresh()->is_active)->toBeFalse();

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.customers')
        ->call('toggleActive', $customer->id);

    expect($customer->fresh()->is_active)->toBeTrue();
});

test('admin can toggle customer active status from customer details page', function () {
    $admin = Admin::factory()->superAdmin()->create();
    $customer = User::factory()->create(['is_active' => true]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.customer-details', ['user' => $customer])
        ->call('toggleActive');

    expect($customer->fresh()->is_active)->toBeFalse();

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.customer-details', ['user' => $customer])
        ->call('toggleActive');

    expect($customer->fresh()->is_active)->toBeTrue();
});
