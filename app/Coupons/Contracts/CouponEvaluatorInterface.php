<?php

namespace App\Coupons\Contracts;

use App\Coupons\DTOs\CartContext;
use App\Coupons\DTOs\CouponEvaluation;

interface CouponEvaluatorInterface
{
    public function evaluate(CartContext $cart, string $code): CouponEvaluation;
}
