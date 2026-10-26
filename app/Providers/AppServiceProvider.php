<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateAdminSession;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureAdminPasswordChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsApprovedSeller;
use App\Http\Middleware\EnsureUserPasswordChanged;
use App\Models\Admin;
use App\Services\CartService;
use Carbon\CarbonImmutable;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        require_once __DIR__.'/../Support/helpers.php';

        $this->app->scoped(CartService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Blade::directive('datetime', function (string $expression): string {
            return "<?php echo \App\Support\DateFormatter::format({$expression}); ?>";
        });

        Blade::directive('date', function (string $expression): string {
            return "<?php echo \App\Support\DateFormatter::formatDate({$expression}); ?>";
        });

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
