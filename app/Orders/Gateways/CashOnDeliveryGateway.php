<?php

namespace App\Orders\Gateways;

use App\Orders\Contracts\PaymentGatewayInterface;
use App\Orders\Models\Order;

class CashOnDeliveryGateway implements PaymentGatewayInterface
{
    public function process(Order $order): bool
    {
        return true;
    }
}
