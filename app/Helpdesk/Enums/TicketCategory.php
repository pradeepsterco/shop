<?php

namespace App\Helpdesk\Enums;

enum TicketCategory: string
{
    // Customer topics
    case OrderIssue = 'order_issue';
    case Delivery = 'delivery';
    case PaymentRefund = 'payment_refund';
    case ReturnReplacement = 'return_replacement';
    case ProductQuestion = 'product_question';
    case Account = 'account';

    // Seller topics
    case SellerOrders = 'seller_orders';
    case SellerReturns = 'seller_returns';
    case PayoutSettlement = 'payout_settlement';
    case FeesCommission = 'fees_commission';
    case ListingCatalog = 'listing_catalog';
    case KycAccount = 'kyc_account';

    // Both
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::OrderIssue => 'Order issue',
            self::Delivery => 'Delivery & tracking',
            self::PaymentRefund => 'Payment & refund',
            self::ReturnReplacement => 'Return & replacement',
            self::ProductQuestion => 'Product question',
            self::Account => 'Account & login',
            self::SellerOrders => 'Orders & shipping',
            self::SellerReturns => 'Returns, RTO & claims',
            self::PayoutSettlement => 'Payouts & settlements',
            self::FeesCommission => 'Fees & commission',
            self::ListingCatalog => 'Listings & catalog',
            self::KycAccount => 'KYC, bank & store account',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<self>
     */
    public static function forCustomers(): array
    {
        return [self::OrderIssue, self::Delivery, self::PaymentRefund, self::ReturnReplacement, self::ProductQuestion, self::Account, self::Other];
    }

    /**
     * @return list<self>
     */
    public static function forSellers(): array
    {
        return [self::SellerOrders, self::SellerReturns, self::PayoutSettlement, self::FeesCommission, self::ListingCatalog, self::KycAccount, self::Other];
    }

    /**
     * @return list<self>
     */
    public static function for(RequesterType $type): array
    {
        return $type === RequesterType::Seller ? self::forSellers() : self::forCustomers();
    }
}
