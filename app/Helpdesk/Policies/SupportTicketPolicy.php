<?php

namespace App\Helpdesk\Policies;

use App\Helpdesk\Models\SupportTicket;
use App\Models\Admin;
use App\Models\User;
use App\Support\AdminPermissions;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class SupportTicketPolicy
{
    /**
     * Requesters see only their own tickets.
     */
    public function view(mixed $user, SupportTicket $ticket): bool
    {
        return $user instanceof User && $ticket->isOwnedBy((int) $user->id);
    }

    /**
     * Admin: see the inbox and tickets.
     */
    public function viewAny(mixed $admin): bool
    {
        return $this->adminCan($admin, AdminPermissions::SUPPORT_VIEW);
    }

    /**
     * Admin: reply, add notes, change status and priority, take an unassigned ticket.
     */
    public function reply(mixed $admin): bool
    {
        return $this->adminCan($admin, AdminPermissions::SUPPORT_REPLY);
    }

    /**
     * Admin: assign any ticket to any active staff member.
     */
    public function assign(mixed $admin): bool
    {
        return $this->adminCan($admin, AdminPermissions::SUPPORT_ASSIGN);
    }

    protected function adminCan(mixed $admin, string $permission): bool
    {
        if (! ($admin instanceof Admin) || ! $admin->is_active) {
            return false;
        }

        if ($admin->isSuperAdmin()) {
            return true;
        }

        try {
            return $admin->hasPermissionTo($permission, 'admin');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
