<?php

namespace App\Shipping\Commands;

use App\Sellers\Contracts\SellerPickupAddressLookupInterface;
use App\Shipping\Contracts\CarrierManagerInterface;
use App\Shipping\Models\SellerShippingProfile;
use Illuminate\Console\Command;
use Throwable;

class RegisterSellerWarehousesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shipping:sync-warehouses {--seller= : Optional specific seller profile ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register or sync seller pickup addresses with active carrier';

    public function handle(
        CarrierManagerInterface $carrierManager,
        SellerPickupAddressLookupInterface $pickupAddressLookup,
    ): int {
        $driver = $carrierManager->driver();
        $provider = $driver->name();

        $sellerOption = $this->option('seller');
        if ($sellerOption) {
            $addr = $pickupAddressLookup->getSellerPickupAddress((int) $sellerOption);
            $addresses = $addr ? collect([$addr]) : collect();
        } else {
            $addresses = $pickupAddressLookup->getAllActiveSellerPickupAddresses();
        }

        if ($addresses->isEmpty()) {
            $this->info('No seller pickup addresses found to sync.');

            return self::SUCCESS;
        }

        $this->info("Syncing {$addresses->count()} seller warehouse(s) with [{$provider}]...");
        $success = 0;
        $failed = 0;

        foreach ($addresses as $address) {
            try {
                $res = $driver->registerPickupAddress($address);
                $alias = $res->alias ?: ('WH-'.$address->sellerProfileId);
                $pincode = (int) preg_replace('/\D/', '', $address->postalCode);

                $statusText = $res->rawResponse['status_text'] ?? ($res->success ? 'registered' : 'failed');
                $phoneVerified = (bool) ($res->rawResponse['phone_verified'] ?? ($res->success && $statusText === 'registered'));

                SellerShippingProfile::updateOrCreate(
                    [
                        'seller_profile_id' => $address->sellerProfileId,
                        'provider' => $provider,
                    ],
                    [
                        'warehouse_alias' => $alias,
                        'is_registered' => $res->success,
                        'registration_status' => $statusText,
                        'phone_verified' => $phoneVerified,
                        'last_registered_at' => now(),
                        'error_message' => $res->success ? null : $res->message,
                        'pincode' => $pincode,
                    ]
                );

                if ($res->success) {
                    $success++;
                    $this->line("  ✓ Registered: {$address->storeName} (Alias: {$alias})");
                } else {
                    $failed++;
                    $this->warn("  ! Failed: {$address->storeName} - {$res->message}");
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("  ✗ Error: {$address->storeName} - {$e->getMessage()}");
            }
        }

        $this->info("Warehouse sync finished. Success: {$success}, Failed: {$failed}.");

        return self::SUCCESS;
    }
}
