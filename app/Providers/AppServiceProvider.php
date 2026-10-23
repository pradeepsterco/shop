<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateAdminSession;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureAdminPasswordChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsApprovedSeller;
use App\Http\Middleware\EnsureUserPasswordChanged;
use App\Models\Admin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Illuminate\Auth\Events\Login;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Services\CartService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::before(function ($user, string $ability): ?bool {
            if ($user instanceof Admin && $user->isSuperAdmin()) {
                return true;
            }

            return null;
        });

        Livewire::addPersistentMiddleware([
            AuthenticateSession::class,
            AuthenticateAdminSession::class,
            EnsureAdminIsActive::class,
            EnsureAdminPasswordChanged::class,
            EnsureUserIsActive::class,
            EnsureUserPasswordChanged::class,
            EnsureUserIsApprovedSeller::class,
        ]);

        // Merge the guest cart into the customer's cart on login.
        // The instanceof check skips admin logins (separate Admin model/guard).
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                app(\App\Services\CartService::class)->mergeGuestIntoUser($event->user);
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
