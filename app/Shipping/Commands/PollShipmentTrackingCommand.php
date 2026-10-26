<?php

namespace App\Shipping\Commands;

use App\Shipping\Contracts\ShipmentServiceInterface;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use Illuminate\Console\Command;
use Throwable;

class PollShipmentTrackingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shipping:poll-tracking {--limit=50 : Max shipments to poll}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll active shipments from carrier tracking API for latest status updates';

    public function handle(ShipmentServiceInterface $shipmentService): int
    {
        $limit = (int) $this->option('limit');

        $terminalStatuses = [
            ShipmentStatus::Delivered->value,
            ShipmentStatus::RtoDelivered->value,
            ShipmentStatus::Cancelled->value,
            ShipmentStatus::Lost->value,
        ];

        $shipments = Shipment::whereNotIn('status', $terminalStatuses)
            ->whereNotNull('awb')
            ->where(function ($query) {
                $query->whereNull('last_polled_at')
                    ->orWhere('last_polled_at', '<=', now()->subMinutes(30));
            })
            ->orderBy('last_polled_at', 'asc')
            ->limit($limit)
            ->get();

        if ($shipments->isEmpty()) {
            $this->info('No shipments requiring tracking poll at this time.');

            return self::SUCCESS;
        }

        $this->info("Polling tracking for {$shipments->count()} shipment(s)...");
        $success = 0;
        $failed = 0;

        foreach ($shipments as $shipment) {
            try {
                $shipmentService->syncTracking($shipment);
                $success++;
                $this->line("  ✓ Synced AWB: {$shipment->awb} (Status: {$shipment->status->value})");
            } catch (Throwable $e) {
                $failed++;
                $this->error("  ✗ Failed AWB: {$shipment->awb} - {$e->getMessage()}");
            }
        }

        $this->info("Tracking poll completed. Success: {$success}, Failed: {$failed}.");

        return self::SUCCESS;
    }
}
