<?php

namespace App\Shipping\Contracts;

interface CarrierManagerInterface
{
    /**
     * Get a carrier driver instance by name, or the default configured driver.
     */
    public function driver(?string $name = null): ShippingCarrierInterface;

    /**
     * Get name of current active default carrier driver.
     */
    public function getActiveDriverName(): string;
}
