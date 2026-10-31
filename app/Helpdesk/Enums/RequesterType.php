<?php

namespace App\Helpdesk\Enums;

enum RequesterType: string
{
    case Customer = 'customer';
    case Seller = 'seller';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
