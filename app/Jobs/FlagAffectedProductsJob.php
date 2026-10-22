<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FlagAffectedProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $verticalId,
        public ?string $attributeCode = null,
        public ?string $optionValue = null
    ) {}

    /**
     * Admin option changes no longer flag existing variants or products.
     */
    public function handle(): void
    {
        // No-op: variants are decoupled from admin option lifecycles.
    }
}
