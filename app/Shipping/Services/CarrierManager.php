<?php

namespace App\Shipping\Services;

use App\Shipping\Contracts\CarrierManagerInterface;
use App\Shipping\Contracts\ShippingCarrierInterface;
use App\Shipping\Drivers\EkartCarrierDriver;
use App\Shipping\Drivers\FakeCarrierDriver;
use App\Shipping\Drivers\ManualCarrierDriver;
use App\Shipping\Drivers\ShiprocketCarrierDriver;
use DomainException;
use Illuminate\Contracts\Foundation\Application;

class CarrierManager implements CarrierManagerInterface
{
    /** @var array<string, ShippingCarrierInterface> */
    protected array $drivers = [];

    protected ?string $activeDriver = null;

    public function __construct(
        protected Application $app,
    ) {}

    public function driver(?string $name = null): ShippingCarrierInterface
    {
        $driverName = $name ?: $this->getActiveDriverName();

        if (isset($this->drivers[$driverName])) {
            return $this->drivers[$driverName];
        }

        $instance = match ($driverName) {
            'ekart' => new EkartCarrierDriver(config('shipping.ekart', [])),
            'shiprocket' => new ShiprocketCarrierDriver(config('shipping.shiprocket', [])),
            'manual' => new ManualCarrierDriver,
            'fake' => new FakeCarrierDriver,
            default => throw new DomainException("Unsupported shipping carrier driver: [{$driverName}]"),
        };

        $this->drivers[$driverName] = $instance;

        return $instance;
    }

    public function getActiveDriverName(): string
    {
        return $this->activeDriver ?: (string) config('shipping.default', 'ekart');
    }

    public function setActiveDriver(string $name): void
    {
        $this->activeDriver = $name;
    }

    public function setDriverInstance(string $name, ShippingCarrierInterface $instance): void
    {
        $this->drivers[$name] = $instance;
    }
}
