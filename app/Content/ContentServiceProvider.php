<?php

namespace App\Content;

use App\Content\Commands\PublishScheduledPagesCommand;
use App\Content\Sections\SectionTypeRegistry;
use App\Content\Services\LinkResolverService;
use App\Content\Services\MarkdownSanitizer;
use App\Content\Services\MediaUploadService;
use App\Content\Services\PagePublishService;
use App\Content\Services\PageResolver;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class ContentServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SectionTypeRegistry::class, function () {
            return new SectionTypeRegistry;
        });

        $this->app->singleton(MarkdownSanitizer::class, function () {
            return new MarkdownSanitizer;
        });

        $this->app->singleton(LinkResolverService::class);
        $this->app->singleton(MediaUploadService::class);
        $this->app->singleton(PagePublishService::class);
        $this->app->singleton(PageResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PublishScheduledPagesCommand::class,
            ]);
        }

        Blade::anonymousComponentPath(resource_path('views/components/content'), 'content');
        $this->loadViewsFrom(resource_path('views/components/content'), 'content');
    }
}
