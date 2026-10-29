<?php

namespace App\Reviews;

use App\Reviews\Models\ProductReview;
use App\Reviews\Policies\ProductReviewPolicy;
use App\Reviews\Services\ProductReviewService;
use App\Reviews\Services\ReviewSummaryService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ReviewsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/reviews.php', 'reviews');

        $this->app->singleton(ReviewSummaryService::class);
        $this->app->singleton(ProductReviewService::class);
    }

    public function boot(): void
    {
        Gate::policy(ProductReview::class, ProductReviewPolicy::class);
    }
}
