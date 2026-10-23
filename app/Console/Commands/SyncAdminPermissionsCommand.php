<?php

namespace App\Console\Commands;

use App\Support\AdminPermissions;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SyncAdminPermissionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'permissions:sync {--prune : Remove permissions that are no longer defined in code}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync code-defined admin permissions into the database idempotently';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Syncing admin permissions...');

        $canonicalPermissions = AdminPermissions::all();
        $created = 0;
        $existing = 0;

        foreach ($canonicalPermissions as $permName) {
            $perm = Permission::where('name', $permName)->where('guard_name', 'admin')->first();
            if (! $perm) {
                Permission::create([
                    'name' => $permName,
                    'guard_name' => 'admin',
                ]);
                $created++;
            } else {
                $existing++;
            }
        }

        // Delete obsolete permissions only when --prune is passed
        $deleted = 0;
        if ($this->option('prune')) {
            $deleted = Permission::where('guard_name', 'admin')
                ->whereNotIn('name', $canonicalPermissions)
                ->delete();
        }

        // Ensure Super Admin role exists and has all admin permissions
        /** @var Role $superAdminRole */
        $superAdminRole = Role::findOrCreate('Super Admin', 'admin');
        $allAdminPermissions = Permission::where('guard_name', 'admin')->get();
        $superAdminRole->syncPermissions($allAdminPermissions);

        // Flush permission cache
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $pruneMsg = $this->option('prune') ? "{$deleted} pruned" : 'pruning skipped (use --prune to delete)';
        $this->info("Permissions synced: {$created} created, {$existing} unchanged, {$pruneMsg}.");
        $this->info("Super Admin role synchronized with all {$allAdminPermissions->count()} permissions.");

        return self::SUCCESS;
    }
}
