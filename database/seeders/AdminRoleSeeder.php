<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Support\AdminPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

class AdminRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Sync permissions canon first
        Artisan::call('permissions:sync');

        // 2. Explicitly assign existing admins the Super Admin role
        $superAdminRole = Role::where('name', 'Super Admin')->where('guard_name', 'admin')->first();
        if ($superAdminRole) {
            foreach (Admin::all() as $admin) {
                if (! $admin->hasRole('Super Admin')) {
                    $admin->assignRole($superAdminRole);
                }
            }
        }

        // 3. Seed sample operational roles (local and testing only)
        if (! app()->isLocal() && ! app()->environment('testing')) {
            return;
        }

        $sampleRoles = [
            'Catalog Manager' => [
                AdminPermissions::CATALOG_CATEGORIES,
                AdminPermissions::CATALOG_VERTICALS,
                AdminPermissions::CATALOG_ATTRIBUTES,
                AdminPermissions::CATALOG_BRANDS_MANAGE,
                AdminPermissions::CATALOG_BRANDS_REVIEW,
                AdminPermissions::CATALOG_PRODUCTS_VIEW,
            ],
            'QC Reviewer' => [
                AdminPermissions::CATALOG_PRODUCTS_VIEW,
                AdminPermissions::CATALOG_QC,
                AdminPermissions::CATALOG_BRANDS_REVIEW,
            ],
            'Seller Operations' => [
                AdminPermissions::SELLERS_VIEW,
                AdminPermissions::SELLERS_MANAGE,
                AdminPermissions::SELLERS_KYC,
            ],
            'Customer Support' => [
                AdminPermissions::CUSTOMERS_VIEW,
                AdminPermissions::CUSTOMERS_MANAGE,
            ],
        ];

        foreach ($sampleRoles as $roleName => $permissions) {
            /** @var Role $role */
            $role = Role::findOrCreate($roleName, 'admin');
            $role->syncPermissions($permissions);
        }
    }
}
