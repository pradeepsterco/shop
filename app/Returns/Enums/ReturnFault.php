<?php

namespace App\Returns\Enums;

/**
 * Who is responsible for a return; decides whether the seller's fees are given back (returns.commission_on_return).
 */
enum ReturnFault: string
{
    case SellerFault = 'seller_fault';
    case CustomerRemorse = 'customer_remorse';
    case CourierFault = 'courier_fault';

    public function label(): string
    {
        return match ($this) {
            self::SellerFault => 'Seller fault',
            self::CustomerRemorse => 'Customer remorse',
            self::CourierFault => 'Courier fault',
        };
    }

    /**
     * Are the seller's marketplace fees reversed along with the sale? (keep = seller still pays them)
     */
    public function reversesFees(): bool
    {
        return config("returns.commission_on_return.{$this->value}", 'reverse') === 'reverse';
    }
}
