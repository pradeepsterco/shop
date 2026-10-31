<?php

namespace App\Orders;

use App\Coupons\Contracts\CustomerEligibilityInterface;
use App\Helpdesk\Contracts\CustomerOrderLookupInterface;
use App\Helpdesk\Contracts\SellerOrderLookupInterface;
use App\Orders\Adapters\HelpdeskCustomerOrderLookupAdapter;
use App\Orders\Adapters\HelpdeskSellerOrderLookupAdapter;
use App\Orders\Adapters\OrderCustomerEligibilityAdapter;
use App\Orders\Adapters\OrderDeliveredReviewEligibilityAdapter;
use App\Orders\Contracts\CustomerRefundServiceInterface;
use App\Orders\Contracts\OrderItemsFeeLookupInterface;
use App\Orders\Contracts\OrderRestockInterface;
use App\Orders\Contracts\ReplacementOrderServiceInterface;
use App\Orders\Contracts\ReturnableOrderLookupInterface;
use App\Orders\Contracts\SellerOrderFulfillmentInterface;
use App\Orders\Contracts\SellerOrderReturnWindowLookupInterface;
use App\Orders\Contracts\SellerOrderShippingDetailsLookupInterface;
use App\Orders\Contracts\SellerOrderStatusLookupInterface;
use App\Orders\Services\CustomerRefundService;
use App\Orders\Services\OrderItemsFeeLookupService;
use App\Orders\Services\OrderService;
use App\Orders\Services\ReplacementOrderService;
use App\Orders\Services\ReturnableOrderLookupService;
use App\Orders\Services\SellerOrderFulfillmentService;
use App\Orders\Services\SellerOrderReturnWindowLookupService;
use App\Orders\Services\SellerOrderShippingDetailsLookupService;
use App\Orders\Services\SellerOrderStatusLookupService;
use App\Reviews\Contracts\ReviewEligibilityInterface;
use Illuminate\Support\ServiceProvider;

class OrdersServiceProvider extends ServiceProvider
{
    /**
     * All of the container bindings that should be registered.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        OrderItemsFeeLookupInterface::class => OrderItemsFeeLookupService::class,
        SellerOrderStatusLookupInterface::class => SellerOrderStatusLookupService::class,
        SellerOrderReturnWindowLookupInterface::class => SellerOrderReturnWindowLookupService::class,
        CustomerRefundServiceInterface::class => CustomerRefundService::class,
        ReturnableOrderLookupInterface::class => ReturnableOrderLookupService::class,
        ReplacementOrderServiceInterface::class => ReplacementOrderService::class,
        SellerOrderFulfillmentInterface::class => SellerOrderFulfillmentService::class,
        OrderRestockInterface::class => OrderService::class,
        SellerOrderShippingDetailsLookupInterface::class => SellerOrderShippingDetailsLookupService::class,
        CustomerEligibilityInterface::class => OrderCustomerEligibilityAdapter::class,
        ReviewEligibilityInterface::class => OrderDeliveredReviewEligibilityAdapter::class,
        CustomerOrderLookupInterface::class => HelpdeskCustomerOrderLookupAdapter::class,
        SellerOrderLookupInterface::class => HelpdeskSellerOrderLookupAdapter::class,
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
