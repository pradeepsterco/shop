<?php

namespace App\Reviews\Contracts;

use App\Reviews\DTOs\EligibilityResult;

interface ReviewEligibilityInterface
{
    /**
     * Check if a customer is eligible to review or edit a review for a product.
     */
    public function check(int $userId, int $productId): EligibilityResult;
}
