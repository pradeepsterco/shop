<?php

namespace Database\Factories;

use App\Enums\SellerStatus;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SellerProfile>
 */
class SellerProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $storeName = fake()->company();

        return [
            'user_id' => User::factory(),
            'store_name' => $storeName,
            'store_slug' => Str::slug($storeName).'-'.fake()->unique()->numberBetween(100, 9999),
            'business_type' => 'individual',
            'description' => fake()->paragraph(),
            'logo_path' => null,
            'banner_path' => null,
            'support_email' => fake()->safeEmail(),
            'support_phone' => '9876543210',
            'pickup_address_line1' => fake()->streetAddress(),
            'pickup_address_line2' => null,
            'pickup_city' => 'Mumbai',
            'pickup_state' => 'Maharashtra',
            'pickup_postal_code' => '400001',
            'pickup_country' => 'India',
            'pan' => 'ABCDE1234F',
            'gstin' => '27ABCDE1234F1Z5',
            'bank_account_holder' => fake()->name(),
            'bank_account_number' => '123456789012',
            'ifsc' => 'HDFC0001234',
            'status' => SellerStatus::Draft,
            'current_step' => 1,
            'rejection_reason' => null,
            'submitted_at' => null,
            'approved_at' => null,
            'reviewed_at' => null,
            'reviewed_by_admin_id' => null,
            'bank_updated_at' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => SellerStatus::Draft,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => SellerStatus::Pending,
            'current_step' => 5,
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => SellerStatus::Approved,
            'current_step' => 5,
            'submitted_at' => now()->subDay(),
            'approved_at' => now(),
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(?string $reason = 'Documents illegible'): static
    {
        return $this->state(fn () => [
            'status' => SellerStatus::Rejected,
            'current_step' => 5,
            'submitted_at' => now()->subDays(2),
            'reviewed_at' => now()->subDay(),
            'rejection_reason' => $reason,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => SellerStatus::Suspended,
            'current_step' => 5,
            'submitted_at' => now()->subDays(10),
            'approved_at' => now()->subDays(5),
            'reviewed_at' => now(),
        ]);
    }
}
