<?php

namespace App\Orders\Contracts;

use App\Orders\Models\Order;

interface PaymentGatewayInterface
{
    public function process(Order $order): bool;
}
