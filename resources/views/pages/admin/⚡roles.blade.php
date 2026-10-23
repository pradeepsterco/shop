<?php

use App\Models\Admin;
use App\Services\AdminActivityLogger;
use App\Support\AdminPermissions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

new #[Layout('layouts.admin')] #[Title('Roles & Permissions')] class extends Component {
    public string $search = '';

    // Modal state
    public bool $showRoleModal = false;
    public ?int $editingRoleId = null;
    public string $name = '';
    public array $selectedPermissions = [];

    public function mount(): void
    {
        $this->authorize('roles.manage');
    }

    public function openCreateModal(): void
    {
        $this->authorize('roles.manage');

        $this->reset(['editingRoleId', 'name', 'selectedPermissions']);
        $this->resetErrorBag();
        $this->showRoleModal = true;
    }

    public function openEditModal(int $roleId): void
    {
        $this->authorize('roles.manage');

        /** @var Admin $currentAdmin */
        $currentAdmin = Auth::guard('admin')->user();

        $role = Role::where('guard_name', 'admin')->findOrFail($roleId);

        // Super Admin role permissions are completely locked
        if ($role->name === 'Super Admin') {
            $this->addError('role', 'The Super Admin role is protected and its permissions cannot be modified.');

            return;
        }

        // Non-super-admins can only edit roles whose permissions are a subset of (or equal to) their own
        if (! $currentAdmin->canManageRole($role)) {
            $this->addError('role', 'You cannot edit a role that contains permissions outside your own authority.');

            return;
        }

        $this->editingRoleId = $role->id;
        $this->name = $role->name;
        $this->selectedPermissions = $role->permissions->pluck('name')->all();
        $this->resetErrorBag();
        $this->showRoleModal = true;
    }

    public function toggleModuleAll(string $module): void
    {
        $this->authorize('roles.manage');

        $grouped = AdminPermissions::grouped();
        $modulePerms = array_keys($grouped[$module] ?? []);

        // Filter by permissions the current user has authority to assign
        /** @var Admin $currentAdmin */
        $currentAdmin = Auth::guard('admin')->user();
        $allowedPerms = array_filter($modulePerms, fn ($p) => $currentAdmin->canAssignPermission($p));

        $allSelected = empty(array_diff($allowedPerms, $this->selectedPermissions));

        if ($allSelected) {
            $this->selectedPermissions = array_values(array_diff($this->selectedPermissions, $allowedPerms));
        } else {
            $this->selectedPermissions = array_values(array_unique(array_merge($this->selectedPermissions, $allowedPerms)));
        }
    }

    public function saveRole(): void
    {
        $this->authorize('roles.manage');

        /** @var Admin $currentAdmin */
        $currentAdmin = Auth::guard('admin')->user();

        $this->validate([
            'name' => [
                'required',
                'string',
                'min:2',
                'max:50',
                $this->editingRoleId
                    ? 'unique:roles,name,'.$this->editingRoleId.',id,guard_name,admin'
                    : 'unique:roles,name,NULL,id,guard_name,admin',
            ],
            'selectedPermissions' => ['array'],
        ]);

        // Prevent naming any role "Super Admin"
        if (trim(strtolower($this->name)) === 'super admin' && (! $this->editingRoleId || Role::find($this->editingRoleId)?->name !== 'Super Admin')) {
            $this->addError('name', 'Cannot create or rename a role to "Super Admin".');

            return;
        }

        // Privilege Escalation Prevention: Non-super-admins can only grant permissions they themselves hold
        if (! $currentAdmin->isSuperAdmin()) {
            foreach ($this->selectedPermissions as $perm) {
                if (! $currentAdmin->canAssignPermission($perm)) {
                    $this->addError('selectedPermissions', "Privilege Escalation Blocked: You do not have permission to grant '{$perm}'.");

                    return;
                }
            }
        }

        if ($this->editingRoleId) {
            $role = Role::where('guard_name', 'admin')->findOrFail($this->editingRoleId);

            if ($role->name === 'Super Admin') {
                $this->addError('role', 'Super Admin role is immutable.');

                return;
            }

            if (! $currentAdmin->canManageRole($role)) {
                $this->addError('role', 'You cannot modify a role that contains permissions outside your own authority.');

                return;
            }

            $oldPermissions = $role->permissions->pluck('name')->all();

            $role->update(['name' => $this->name]);
            $role->syncPermissions($this->selectedPermissions);

            AdminActivityLogger::log('role.updated', $role, [
                'name' => $this->name,
                'permissions_before' => $oldPermissions,
                'permissions_after' => $this->selectedPermissions,
            ]);

            session()->flash('status', "Role [{$role->name}] updated successfully.");
        } else {
            /** @var Role $role */
            $role = Role::create([
                'name' => $this->name,
                'guard_name' => 'admin',
            ]);

            $role->syncPermissions($this->selectedPermissions);

            AdminActivityLogger::log('role.created', $role, [
                'name' => $this->name,
                'permissions' => $this->selectedPermissions,
            ]);

            session()->flash('status', "Role [{$role->name}] created successfully.");
        }

        // Clear permission cache
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->showRoleModal = false;
        $this->reset(['editingRoleId', 'name', 'selectedPermissions']);
    }

    public function deleteRole(int $roleId): void
    {
        $this->authorize('roles.manage');

        /** @var Admin $currentAdmin */
        $currentAdmin = Auth::guard('admin')->user();

        $role = Role::where('guard_name', 'admin')->findOrFail($roleId);

        // Safeguard 1: Super Admin role cannot be deleted
        if ($role->name === 'Super Admin') {
            session()->flash('error', 'The Super Admin role is protected and cannot be deleted.');

            return;
        }

        // Safeguard 2: Non-super-admins can only delete roles whose permissions are a subset of (or equal to) their own
        if (! $currentAdmin->canManageRole($role)) {
            session()->flash('error', 'You cannot delete a role that contains permissions outside your own authority.');

            return;
        }

        // Safeguard 3: Delete blocked while assigned to any staff member
        $assignedCount = DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', Admin::class)
            ->count();

        if ($assignedCount > 0) {
            session()->flash('error', "Cannot delete role [{$role->name}] because it is currently assigned to {$assignedCount} staff member(s). Reassign them first.");

            return;
        }

        $roleName = $role->name;
        $role->delete();

        // Clear permission cache
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        AdminActivityLogger::log('role.deleted', null, [
            'role_name' => $roleName,
        ]);

        session()->flash('status', "Role [{$roleName}] was deleted successfully.");
    }

    public function getRolesProperty()
    {
        return Role::where('guard_name', 'admin')
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->withCount(['permissions', 'users'])
            ->orderByRaw("CASE WHEN name = 'Super Admin' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2 text-xs text-zinc-500">
                <a href="{{ route('admin.dashboard') }}" wire:navigate class="hover:underline">Dashboard</a>
                <span>›</span>
                <span class="font-semibold text-zinc-900 dark:text-white">Roles & Permissions</span>
            </div>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Admin Roles</h2>
            <p class="text-xs text-zinc-500">Configure roles and assign granular functional permissions to staff members.</p>
        </div>

        <div class="flex items-center gap-3">
            <button wire:click="openCreateModal"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-zinc-900 px-4 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                <span>Create New Role</span>
            </button>
        </div>
    </div>

    <!-- Status Alerts -->
    @if (session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-xs font-medium text-green-800 dark:border-green-900/50 dark:bg-green-950/40 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-xs font-medium text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
            {{ session('error') }}
        </div>
    @endif

    <!-- Roles Table -->
    <div class="rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
        <div class="flex items-center justify-between p-4 border-b border-zinc-200 dark:border-zinc-800">
            <div class="relative w-72">
                <input wire:model.live.debounce.300ms="search"
                       type="search"
                       placeholder="Search roles..."
                       class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs text-zinc-900 placeholder-zinc-400 focus:border-zinc-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
            </div>
            <div class="text-xs text-zinc-500">
                {{ count($this->roles) }} role(s) configured
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-zinc-50 text-zinc-500 uppercase font-semibold dark:bg-zinc-800/50">
                    <tr>
                        <th class="px-4 py-3">Role Name</th>
                        <th class="px-4 py-3">Assigned Staff</th>
                        <th class="px-4 py-3">Permissions</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($this->roles as $role)
                        <tr wire:key="role-{{ $role->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition">
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">
                                <div class="flex items-center gap-2">
                                    <span>{{ $role->name }}</span>
                                    @if ($role->name === 'Super Admin')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700 border border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-900/60">
                                            🔒 System Master
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">
                                <span class="inline-flex items-center rounded-md bg-zinc-100 px-2 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                    {{ $role->users_count }} member(s)
                                </span>
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">
                                @if ($role->name === 'Super Admin')
                                    <span class="text-xs text-emerald-600 dark:text-emerald-400 font-semibold">
                                        All System Permissions (Bypass)
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-md bg-zinc-100 px-2 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ $role->permissions_count }} permission(s)
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @if ($role->name !== 'Super Admin')
                                        <button wire:click="openEditModal({{ $role->id }})"
                                                type="button"
                                                class="rounded-md border border-zinc-300 px-2.5 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                                            Edit
                                        </button>
                                        <button wire:click="deleteRole({{ $role->id }})"
                                                wire:confirm="Are you sure you want to delete this role?"
                                                type="button"
                                                class="rounded-md border border-red-200 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50 dark:border-red-900/50 dark:text-red-400 dark:hover:bg-red-950/30">
                                            Delete
                                        </button>
                                    @else
                                        <span class="text-[11px] text-zinc-400 italic">Locked</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="p-8 text-center text-xs text-zinc-500">
                                No roles found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create / Edit Modal -->
    @if ($showRoleModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div wire:click="$set('showRoleModal', false)" class="fixed inset-0 bg-zinc-950/60 backdrop-blur-xs"></div>

            <div class="relative w-full max-w-3xl rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900 max-h-[90vh] overflow-y-auto space-y-6">
                <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-4">
                    <h3 class="text-base font-bold text-zinc-900 dark:text-white">
                        {{ $editingRoleId ? 'Edit Role' : 'Create New Role' }}
                    </h3>
                    <button wire:click="$set('showRoleModal', false)" type="button" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        ✕
                    </button>
                </div>

                <form wire:submit="saveRole" class="space-y-6">
                    <div>
                        <label for="name" class="block text-xs font-semibold text-zinc-900 dark:text-white">Role Name *</label>
                        <input wire:model="name" id="name" type="text" required placeholder="e.g. Catalog Specialist"
                               class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        @error('name')
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    @error('selectedPermissions')
                        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
                            {{ $message }}
                        </div>
                    @enderror

                    <!-- Permissions Grid Grouped by Module -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500">Assign Permissions</h4>
                            <span class="text-xs text-zinc-400">{{ count($selectedPermissions) }} permission(s) selected</span>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @foreach (\App\Support\AdminPermissions::grouped() as $moduleName => $permissions)
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-800 dark:bg-zinc-800/30 space-y-3">
                                    <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-700/60 pb-2">
                                        <h5 class="text-xs font-bold text-zinc-900 dark:text-white">{{ $moduleName }}</h5>
                                        <button wire:click="toggleModuleAll('{{ $moduleName }}')" type="button" class="text-[11px] font-semibold text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                                            Toggle All
                                        </button>
                                    </div>

                                    <div class="space-y-2">
                                        @foreach ($permissions as $permCode => $meta)
                                            @php
                                                /** @var Admin $user */
                                                $user = Auth::guard('admin')->user();
                                                $canGrant = $user->canAssignPermission($permCode);
                                            @endphp
                                            <label class="flex items-start gap-2 cursor-pointer {{ ! $canGrant ? 'opacity-50 cursor-not-allowed' : '' }}">
                                                <input type="checkbox"
                                                       wire:model="selectedPermissions"
                                                       value="{{ $permCode }}"
                                                       {{ ! $canGrant ? 'disabled' : '' }}
                                                       class="mt-0.5 size-3.5 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800" />
                                                <div class="text-xs">
                                                    <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $meta['label'] }}</span>
                                                    <p class="text-[11px] text-zinc-400 leading-tight">{{ $meta['description'] }}</p>
                                                    @if (! $canGrant)
                                                        <span class="text-[10px] text-amber-600 dark:text-amber-400 italic">Not in your permissions</span>
                                                    @endif
                                                </div>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-zinc-200 dark:border-zinc-800">
                        <button wire:click="$set('showRoleModal', false)" type="button" class="rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                            Cancel
                        </button>
                        <button type="submit" class="rounded-lg bg-zinc-900 px-5 py-2 text-xs font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100">
                            {{ $editingRoleId ? 'Save Changes' : 'Create Role' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

