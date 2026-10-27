<?php

namespace App\Coupons\Contracts;

interface CustomerEligibilityInterface
{
    public function isFirstOrder(string $customerReference): bool;
}
