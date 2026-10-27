<?php

namespace App\Coupons;

use App\Coupons\Contracts\CouponEvaluatorInterface;
use App\Coupons\Contracts\CouponRedemptionInterface;
use App\Coupons\Contracts\CustomerEligibilityInterface;
use App\Coupons\Services\CouponEvaluator;
use App\Coupons\Services\CouponRedemptionService;
use Illuminate\Support\ServiceProvider;

class CouponsServiceProvider extends ServiceProvider
{
    /**
     * All of the container bindings that should be registered.
     *
     * @var array<string, string>
     */
    public array $bindings = [
        CouponRedemptionInterface::class => CouponRedemptionService::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CouponEvaluatorInterface::class, function ($app) {
            $eligibility = $app->bound(CustomerEligibilityInterface::class)
                ? $app->make(CustomerEligibilityInterface::class)
                : null;

            return new CouponEvaluator(
                customerEligibility: $eligibility,
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Module bootstrap
    }
}
