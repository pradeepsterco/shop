<?php

use App\Http\Middleware\AuthenticateAdminSession;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsApprovedSeller;
use App\Http\Middleware\EnsureUserPasswordChanged;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->prefix((string) config('admin.path', 'admin'))
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            AuthenticateSession::class,
            EnsureUserIsActive::class,
            EnsureUserPasswordChanged::class,
            AuthenticateAdminSession::class,
        ]);
        // [SETUP HERE] Adminer Middleware group
        $middleware->group('adminer', [
            EncryptCookies::class,
            StartSession::class,
            Authenticate::class,
        ]);
        $middleware->alias([
            'ensure.seller' => EnsureUserIsApprovedSeller::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/shipping/*',
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->routeIs('admin.*') || str_contains((string) $request->header('referer'), '/admin') || $request->is('admin/*') || $request->is('admin')) {
                return route('admin.login');
            }

            return route('login');
        });

        $middleware->redirectUsersTo(function (Request $request) {
            if ($request->routeIs('admin.*')) {
                return route('admin.dashboard');
            }

            return route('dashboard');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
