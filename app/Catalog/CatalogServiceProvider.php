<?php

namespace App\Catalog;

use App\Catalog\Contracts\CatalogReadInterface;
use App\Catalog\Contracts\VerticalLookupInterface;
use App\Catalog\Services\CatalogReadService;
use App\Catalog\Services\VerticalLookupService;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        VerticalLookupInterface::class => VerticalLookupService::class,
        CatalogReadInterface::class => CatalogReadService::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
