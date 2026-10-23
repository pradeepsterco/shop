<?php

namespace App\Models;

use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property bool $must_change_password
 * @property bool $is_active
 * @property Carbon|null $invitation_accepted_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'invitation_accepted_at'])]
#[Hidden(['password', 'remember_token'])]
class Admin extends Authenticatable
{
    /** @use HasFactory<AdminFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The guard name for Spatie permissions.
     */
    protected string $guard_name = 'admin';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'invitation_accepted_at' => 'datetime',
        ];
    }

    /**
     * Check if the admin is an active Super Admin.
     */
    public function isSuperAdmin(): bool
    {
        return $this->is_active && $this->hasRole('Super Admin');
    }

    /**
     * Check if this admin has authority to assign the given role to staff (privilege escalation check).
     * Non-super-admins can only assign roles whose permissions are a subset of or equal to their own.
     */
    public function canAssignRole(Role $role): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($role->name === 'Super Admin') {
            return false;
        }

        $rolePermissions = $role->permissions->pluck('name')->all();
        $myPermissions = $this->getAllPermissions()->pluck('name')->all();

        return empty(array_diff($rolePermissions, $myPermissions));
    }

    /**
     * Check if this admin has authority to edit, activate/deactivate, resend invites to,
     * or change roles of the target staff member.
     * Non-super-admins can only manage staff whose effective permissions are a subset of
     * (or equal to) their own, and never staff holding the Super Admin role.
     */
    public function canManageStaff(Admin $target): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        // Never allow non-super-admins to manage anyone holding the Super Admin role
        if ($target->hasRole('Super Admin')) {
            return false;
        }

        $targetPermissions = $target->getAllPermissions()->pluck('name')->all();
        $myPermissions = $this->getAllPermissions()->pluck('name')->all();

        return empty(array_diff($targetPermissions, $myPermissions));
    }

    /**
     * Check if this admin has authority to edit or delete the given role.
     * Non-super-admins can only edit or delete roles whose permissions are a subset of
     * (or equal to) their own, and never the Super Admin role.
     */
    public function canManageRole(Role $role): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($role->name === 'Super Admin') {
            return false;
        }

        $rolePermissions = $role->permissions->pluck('name')->all();
        $myPermissions = $this->getAllPermissions()->pluck('name')->all();

        return empty(array_diff($rolePermissions, $myPermissions));
    }

    /**
     * Check if this admin has authority to assign the given permission to a role.
     */
    public function canAssignPermission(string|Permission $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $permName = $permission instanceof Permission ? $permission->name : $permission;

        return $this->hasPermissionTo($permName, 'admin');
    }

    /**
     * Get the admin's initials.
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
