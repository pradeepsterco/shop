<?php

namespace Database\Factories;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<Admin>
 */
class AdminFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the admin should be inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Assign the Super Admin role.
     */
    public function superAdmin(): static
    {
        return $this->afterCreating(function (Admin $admin) {
            /** @var Role $role */
            $role = Role::findOrCreate('Super Admin', 'admin');
            $admin->assignRole($role);
        });
    }

    /**
     * Assign a specific role.
     */
    public function withRole(string $roleName): static
    {
        return $this->afterCreating(function (Admin $admin) use ($roleName) {
            /** @var Role $role */
            $role = Role::findOrCreate($roleName, 'admin');
            $admin->assignRole($role);
        });
    }
}
