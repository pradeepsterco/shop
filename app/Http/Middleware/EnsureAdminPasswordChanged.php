<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPasswordChanged
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('admin')->check() && Auth::guard('admin')->user()?->must_change_password) {
            if ($request->routeIs('admin.password.force-change') || $request->routeIs('admin.logout')) {
                return $next($request);
            }

            if ($this->isForceChangeLivewireRequest($request, 'pages::admin.auth.force-change-password')) {
                return $next($request);
            }

            return redirect()->route('admin.password.force-change');
        }

        return $next($request);
    }

    /**
     * Determine if the request is a Livewire update targeting the force-change component.
     */
    protected function isForceChangeLivewireRequest(Request $request, string $componentName): bool
    {
        if (! $request->is('livewire/*') && ! $request->is('livewire-*/*')) {
            return false;
        }

        $components = $request->input('components');
        if (! is_array($components)) {
            return false;
        }

        foreach ($components as $component) {
            $snapshot = $component['snapshot'] ?? null;
            if (is_string($snapshot)) {
                $data = json_decode($snapshot, true);
                if (($data['memo']['name'] ?? '') === $componentName) {
                    return true;
                }
            }
        }

        return false;
    }
}
