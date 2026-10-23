<?php

namespace App\Http\Middleware;

use BadMethodCallException;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dedicated session authentication middleware for the admin guard.
 *
 * Laravel's built-in AuthenticateSession cannot be used on admin routes because
 * upon detecting a password hash mismatch, the built-in middleware flushes the
 * whole session ($request->session()->flush()), which would log out the customer
 * guard too. This middleware safely invalidates only the admin session keys
 * while leaving customer and other guard sessions intact.
 */
class AuthenticateAdminSession
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! Auth::guard('admin')->check()) {
            return $next($request);
        }

        $admin = Auth::guard('admin')->user();
        if (! $admin || ! $admin->getAuthPassword()) {
            return $next($request);
        }

        if ($request->session()->get('password_hash_admin_id') !== $admin->getAuthIdentifier()) {
            $request->session()->put('password_hash_admin_id', $admin->getAuthIdentifier());
            $request->session()->put('password_hash_admin', $this->hashPassword($admin->getAuthPassword()));
        }

        $sessionPasswordHash = (string) $request->session()->get('password_hash_admin');

        if (! $this->validatePasswordHash($admin->getAuthPassword(), $sessionPasswordHash)) {
            Auth::guard('admin')->logout();
            $request->session()->forget(['password_hash_admin', 'password_hash_admin_id']);

            throw new AuthenticationException(
                'Unauthenticated.', ['admin'], route('admin.login')
            );
        }

        return tap($next($request), function () use ($request, $admin) {
            if (Auth::guard('admin')->check()) {
                $request->session()->put('password_hash_admin', $this->hashPassword($admin->getAuthPassword()));
            }
        });
    }

    /**
     * Validate the admin password hash against stored value.
     */
    protected function validatePasswordHash(string $passwordHash, string $storedValue): bool
    {
        try {
            return hash_equals(Auth::guard('admin')->hashPasswordForCookie($passwordHash), $storedValue)
                || hash_equals($passwordHash, $storedValue);
        } catch (BadMethodCallException) {
            return hash_equals($passwordHash, $storedValue);
        }
    }

    /**
     * Hash the password for cookie/session storage using the admin guard.
     */
    protected function hashPassword(string $passwordHash): string
    {
        try {
            return Auth::guard('admin')->hashPasswordForCookie($passwordHash);
        } catch (BadMethodCallException) {
            return $passwordHash;
        }
    }
}
