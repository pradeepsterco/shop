<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AdminActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AdminActivityLogger
{
    /**
     * Log an admin action.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function log(string $action, ?Model $subject = null, array $properties = [], ?Admin $actor = null): AdminActivityLog
    {
        $actorId = $actor?->id;
        if (! $actorId && Auth::guard('admin')->check()) {
            /** @var Admin $loggedAdmin */
            $loggedAdmin = Auth::guard('admin')->user();
            $actorId = $loggedAdmin->id;
        }

        return AdminActivityLog::create([
            'admin_id' => $actorId,
            'action' => $action,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'properties' => ! empty($properties) ? $properties : null,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }
}
