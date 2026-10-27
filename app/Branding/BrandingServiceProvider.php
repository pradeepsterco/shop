<?php

namespace App\Branding;

use App\Branding\Contracts\BrandingLookupInterface;
use App\Branding\Services\BrandingService;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class BrandingServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        BrandingLookupInterface::class => BrandingService::class,
    ];

    public function register(): void
    {
        require_once __DIR__.'/helpers.php';

        $this->app->singleton(BrandingService::class, function () {
            return new BrandingService;
        });

        $this->app->alias(BrandingService::class, BrandingLookupInterface::class);
    }

    public function boot(): void
    {
        Blade::anonymousComponentPath(resource_path('views/components/brand'), 'brand');
    }
}
