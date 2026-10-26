<?php

namespace App\Livewire\Admin;

use App\Catalog\Contracts\VerticalLookupInterface;
use App\Fees\Contracts\FeeScheduleServiceInterface;
use App\Fees\Models\FeeClosingSlab;
use App\Fees\Models\FeeCollectionRule;
use App\Fees\Models\FeeCommissionSlab;
use App\Fees\Models\FeeSchedule;
use App\Fees\Models\FeeShippingSlab;
use App\Services\AdminActivityLogger;
use App\Shared\Enums\FeeScheduleStatus;
use App\Shared\Enums\ShippingZone;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Edit Draft Fee Schedule')] class extends Component {
    public ?int $scheduleId = null;

    public string $name = 'Standard Fee Schedule';

    public string $gst_rate = '18.00';

    public string $tcs_rate = '0.50';

    public string $tds_standard_rate = '0.10';

    public string $tds_no_pan_rate = '5.00';

    public int $default_weight_grams = 500;

    /** @var array<int, array{id?: int, vertical_id: int|null, min_price_inr: string, max_price_inr: string, rate_percent: string}> */
    public array $commissionSlabs = [];

    /** @var array<int, array{id?: int, min_price_inr: string, max_price_inr: string, fee_inr: string}> */
    public array $closingSlabs = [];

    /** @var array<int, array{id?: int, zone: string, min_weight: int, max_weight: int|null, fee_inr: string, is_default: bool}> */
    public array $shippingSlabs = [];

    /** @var array<int, array{id?: int, payment_method: string, rate_percent: string, fee_inr: string}> */
    public array $collectionRules = [];

    public array $validationErrors = [];

    public function mount(int|FeeSchedule|null $feeSchedule = null): void
    {
        $this->authorize('fees.manage');

        $scheduleId = $feeSchedule instanceof FeeSchedule ? $feeSchedule->id : ($feeSchedule ? (int) $feeSchedule : null);

        if ($scheduleId) {
            $sched = FeeSchedule::with(['commissionSlabs', 'closingSlabs', 'shippingSlabs', 'collectionRules'])
                ->findOrFail($scheduleId);

            if ($sched->status !== FeeScheduleStatus::Draft) {
                session()->flash('error', 'Only draft fee schedules can be edited directly.');
                $this->redirect(route('admin.fees.show', $sched), navigate: true);

                return;
            }

            $this->scheduleId = $sched->id;
            $this->name = $sched->name;
            $this->gst_rate = (string) $sched->gst_rate;
            $this->tcs_rate = (string) $sched->tcs_rate;
            $this->tds_standard_rate = (string) $sched->tds_standard_rate;
            $this->tds_no_pan_rate = (string) $sched->tds_no_pan_rate;
            $this->default_weight_grams = $sched->default_weight_grams;

            foreach ($sched->commissionSlabs->sortBy(['vertical_id', 'min_price']) as $c) {
                $this->commissionSlabs[] = [
                    'id' => $c->id,
                    'vertical_id' => $c->vertical_id,
                    'min_price_inr' => (string) ($c->min_price / 100),
                    'max_price_inr' => $c->max_price !== null ? (string) ($c->max_price / 100) : '',
                    'rate_percent' => (string) ($c->rate_bps / 100),
                ];
            }

            foreach ($sched->closingSlabs->sortBy('min_price') as $close) {
                $this->closingSlabs[] = [
                    'id' => $close->id,
                    'min_price_inr' => (string) ($close->min_price / 100),
                    'max_price_inr' => $close->max_price !== null ? (string) ($close->max_price / 100) : '',
                    'fee_inr' => (string) ($close->fee_amount / 100),
                ];
            }

            foreach ($sched->shippingSlabs->sortBy(['zone', 'min_weight_grams']) as $ship) {
                $this->shippingSlabs[] = [
                    'id' => $ship->id,
                    'zone' => $ship->zone->value,
                    'min_weight' => $ship->min_weight_grams,
                    'max_weight' => $ship->max_weight_grams,
                    'fee_inr' => (string) ($ship->fee_amount / 100),
                    'is_default' => (bool) $ship->is_default,
                ];
            }

            foreach ($sched->collectionRules as $r) {
                $this->collectionRules[] = [
                    'id' => $r->id,
                    'payment_method' => $r->payment_method,
                    'rate_percent' => (string) ($r->rate_bps / 100),
                    'fee_inr' => (string) ($r->fee_amount / 100),
                ];
            }
        } else {
            // New Draft defaults
            $this->commissionSlabs = [
                ['vertical_id' => null, 'min_price_inr' => '0', 'max_price_inr' => '500', 'rate_percent' => '12.00'],
                ['vertical_id' => null, 'min_price_inr' => '500.01', 'max_price_inr' => '1000', 'rate_percent' => '10.00'],
                ['vertical_id' => null, 'min_price_inr' => '1000.01', 'max_price_inr' => '', 'rate_percent' => '8.00'],
            ];

            $this->closingSlabs = [
                ['min_price_inr' => '0', 'max_price_inr' => '250', 'fee_inr' => '10'],
                ['min_price_inr' => '250.01', 'max_price_inr' => '500', 'fee_inr' => '15'],
                ['min_price_inr' => '500.01', 'max_price_inr' => '1000', 'fee_inr' => '20'],
                ['min_price_inr' => '1000.01', 'max_price_inr' => '', 'fee_inr' => '30'],
            ];

            $this->shippingSlabs = [
                ['zone' => 'local', 'min_weight' => 0, 'max_weight' => 500, 'fee_inr' => '30', 'is_default' => true],
                ['zone' => 'local', 'min_weight' => 501, 'max_weight' => null, 'fee_inr' => '60', 'is_default' => false],
                ['zone' => 'regional', 'min_weight' => 0, 'max_weight' => 500, 'fee_inr' => '40', 'is_default' => true],
                ['zone' => 'regional', 'min_weight' => 501, 'max_weight' => null, 'fee_inr' => '80', 'is_default' => false],
                ['zone' => 'national', 'min_weight' => 0, 'max_weight' => 500, 'fee_inr' => '50', 'is_default' => true],
                ['zone' => 'national', 'min_weight' => 501, 'max_weight' => null, 'fee_inr' => '100', 'is_default' => false],
            ];

            $this->collectionRules = [
                ['payment_method' => 'cod', 'rate_percent' => '0', 'fee_inr' => '0'],
            ];
        }
    }

    public function addCommissionSlab(): void
    {
        $this->commissionSlabs[] = [
            'vertical_id' => null,
            'min_price_inr' => '0',
            'max_price_inr' => '',
            'rate_percent' => '10.00',
        ];
    }

    public function removeCommissionSlab(int $index): void
    {
        unset($this->commissionSlabs[$index]);
        $this->commissionSlabs = array_values($this->commissionSlabs);
    }

    public function addClosingSlab(): void
    {
        $this->closingSlabs[] = [
            'min_price_inr' => '0',
            'max_price_inr' => '',
            'fee_inr' => '20',
        ];
    }

    public function removeClosingSlab(int $index): void
    {
        unset($this->closingSlabs[$index]);
        $this->closingSlabs = array_values($this->closingSlabs);
    }

    public function addShippingSlab(): void
    {
        $this->shippingSlabs[] = [
            'zone' => 'national',
            'min_weight' => 0,
            'max_weight' => null,
            'fee_inr' => '50',
            'is_default' => false,
        ];
    }

    public function removeShippingSlab(int $index): void
    {
        unset($this->shippingSlabs[$index]);
        $this->shippingSlabs = array_values($this->shippingSlabs);
    }

    public function save(): void
    {
        $this->authorize('fees.manage');

        $service = app(FeeScheduleServiceInterface::class);

        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'gst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'tcs_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'tds_standard_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'tds_no_pan_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'default_weight_grams' => ['required', 'integer', 'min:1'],
        ]);

        $this->validationErrors = [];

        // Validate Closing Slabs Continuity
        $closingPaiseSlabs = [];
        foreach ($this->closingSlabs as $c) {
            $minPaise = (int) round(((float) $c['min_price_inr']) * 100);
            $maxPaise = $c['max_price_inr'] !== '' ? (int) round(((float) $c['max_price_inr']) * 100) : null;
            $closingPaiseSlabs[] = ['min' => $minPaise, 'max' => $maxPaise];
        }
        $closingErrors = $service->validateSlabsContinuity($closingPaiseSlabs);
        foreach ($closingErrors as $err) {
            $this->validationErrors[] = "Closing Slabs: {$err}";
        }

        // Validate Shipping Slabs Continuity by Zone
        $shippingByZone = [];
        foreach ($this->shippingSlabs as $s) {
            $shippingByZone[$s['zone']][] = [
                'min' => (int) $s['min_weight'],
                'max' => $s['max_weight'] !== null && $s['max_weight'] !== '' ? (int) $s['max_weight'] : null,
            ];
        }
        foreach ($shippingByZone as $z => $slabs) {
            $shipErrors = $service->validateSlabsContinuity($slabs);
            foreach ($shipErrors as $err) {
                $this->validationErrors[] = "Shipping Slabs ({$z}): {$err}";
            }
        }

        if (! empty($this->validationErrors)) {
            return;
        }

        $isNew = ! $this->scheduleId;
        $savedSchedule = null;

        // Persist
        DB::transaction(function () use ($service, $isNew, &$savedSchedule) {
            $scheduleData = [
                'name' => $this->name,
                'gst_rate' => (float) $this->gst_rate,
                'tcs_rate' => (float) $this->tcs_rate,
                'tds_standard_rate' => (float) $this->tds_standard_rate,
                'tds_no_pan_rate' => (float) $this->tds_no_pan_rate,
                'default_weight_grams' => $this->default_weight_grams,
                'created_by_id' => auth('admin')->id(),
                'updated_by_id' => auth('admin')->id(),
            ];

            if ($this->scheduleId) {
                $schedule = FeeSchedule::findOrFail($this->scheduleId);
                $schedule = $service->updateDraft($schedule, $scheduleData);
                // Wipe existing slabs to rebuild
                $schedule->commissionSlabs()->delete();
                $schedule->closingSlabs()->delete();
                $schedule->shippingSlabs()->delete();
                $schedule->collectionRules()->delete();
            } else {
                $schedule = $service->createDraft($scheduleData);
                $this->scheduleId = $schedule->id;
            }

            $savedSchedule = $schedule;

            // Insert Commission Slabs
            foreach ($this->commissionSlabs as $c) {
                $minPaise = (int) round(((float) $c['min_price_inr']) * 100);
                $maxPaise = $c['max_price_inr'] !== '' ? (int) round(((float) $c['max_price_inr']) * 100) : null;
                $rateBps = (int) round(((float) $c['rate_percent']) * 100);

                FeeCommissionSlab::create([
                    'fee_schedule_id' => $schedule->id,
                    'vertical_id' => $c['vertical_id'] ? (int) $c['vertical_id'] : null,
                    'min_price' => $minPaise,
                    'max_price' => $maxPaise,
                    'rate_bps' => $rateBps,
                ]);
            }

            // Insert Closing Slabs
            foreach ($this->closingSlabs as $close) {
                $minPaise = (int) round(((float) $close['min_price_inr']) * 100);
                $maxPaise = $close['max_price_inr'] !== '' ? (int) round(((float) $close['max_price_inr']) * 100) : null;
                $feePaise = (int) round(((float) $close['fee_inr']) * 100);

                FeeClosingSlab::create([
                    'fee_schedule_id' => $schedule->id,
                    'min_price' => $minPaise,
                    'max_price' => $maxPaise,
                    'fee_amount' => $feePaise,
                ]);
            }

            // Insert Shipping Slabs
            foreach ($this->shippingSlabs as $ship) {
                $feePaise = (int) round(((float) $ship['fee_inr']) * 100);

                FeeShippingSlab::create([
                    'fee_schedule_id' => $schedule->id,
                    'min_weight_grams' => (int) $ship['min_weight'],
                    'max_weight_grams' => $ship['max_weight'] !== null && $ship['max_weight'] !== '' ? (int) $ship['max_weight'] : null,
                    'zone' => ShippingZone::from($ship['zone']),
                    'fee_amount' => $feePaise,
                    'is_default' => (bool) $ship['is_default'],
                ]);
            }

            // Insert Collection Rules
            foreach ($this->collectionRules as $r) {
                $rateBps = (int) round(((float) $r['rate_percent']) * 100);
                $feePaise = (int) round(((float) $r['fee_inr']) * 100);

                FeeCollectionRule::create([
                    'fee_schedule_id' => $schedule->id,
                    'payment_method' => $r['payment_method'],
                    'rate_bps' => $rateBps,
                    'fee_amount' => $feePaise,
                ]);
            }
        });

        if ($savedSchedule) {
            AdminActivityLogger::log(
                $isNew ? 'fee_schedule.created' : 'fee_schedule.updated',
                $savedSchedule,
                ['name' => $savedSchedule->name, 'version' => $savedSchedule->version]
            );
        }

        session()->flash('success', "Draft fee schedule '{$this->name}' saved successfully.");
        $this->redirect(route('admin.fees.show', $this->scheduleId), navigate: true);
    }
}; ?>

<div class="space-y-6">
    @php
        $verticals = app(App\Catalog\Contracts\VerticalLookupInterface::class)->getAllActiveVerticals();
    @endphp

    <!-- Breadcrumb & Top Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-zinc-500 mb-1">
                <a href="{{ route('admin.fees.index') }}" wire:navigate class="hover:underline">&larr; Back to Fee Schedules</a>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                {{ $scheduleId ? 'Edit Draft Fee Schedule' : 'Create Draft Fee Schedule' }}
            </h1>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Configure commission slabs, closing fees, shipping weight slabs, and statutory tax rates.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <a href="{{ $scheduleId ? route('admin.fees.show', $scheduleId) : route('admin.fees.index') }}"
               wire:navigate
               class="rounded-lg border border-zinc-200 bg-white px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 transition">
                Cancel
            </a>
            <button type="button"
                    wire:click="save"
                    class="rounded-lg bg-zinc-900 px-4 py-2 text-xs font-bold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                Save Draft Schedule
            </button>
        </div>
    </div>

    <!-- Continuity & Form Validation Errors -->
    @if (! empty($this->validationErrors))
        <div class="rounded-2xl border border-red-200 bg-red-50 p-5 dark:border-red-900/60 dark:bg-red-950/40 space-y-2">
            <h3 class="text-sm font-bold text-red-900 dark:text-red-200">Please resolve the following slab continuity errors:</h3>
            <ul class="list-disc list-inside text-xs text-red-700 dark:text-red-300 space-y-1">
                @foreach ($this->validationErrors as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- General Settings Form -->
    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-5">
        <h3 class="text-sm font-bold uppercase tracking-wider text-zinc-400 border-b border-zinc-100 pb-3 dark:border-zinc-800">
            Schedule Overview & Statutory Tax Rates
        </h3>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
            <div class="sm:col-span-2">
                <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Schedule Name *</label>
                <input type="text" wire:model="name" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                @error('name') <p class="text-[11px] text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Default Weight (Grams) *</label>
                <input type="number" wire:model="default_weight_grams" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                @error('default_weight_grams') <p class="text-[11px] text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">GST on Fees (%) *</label>
                <input type="number" step="0.01" wire:model="gst_rate" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                @error('gst_rate') <p class="text-[11px] text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">GST TCS Rate (%) *</label>
                <input type="number" step="0.01" wire:model="tcs_rate" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                @error('tcs_rate') <p class="text-[11px] text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">TDS Standard / No-PAN (%) *</label>
                <div class="flex items-center gap-2">
                    <input type="number" step="0.01" wire:model="tds_standard_rate" placeholder="0.10" class="w-1/2 rounded-lg border border-zinc-300 px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                    <input type="number" step="0.01" wire:model="tds_no_pan_rate" placeholder="5.00" class="w-1/2 rounded-lg border border-zinc-300 px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                </div>
            </div>
        </div>
    </div>

    <!-- Section 1: Commission Slabs -->
    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
        <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
            <div>
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white">1. Commission Slabs</h3>
                <p class="text-xs text-zinc-500">Configure percentage marketplace fees per vertical or fallback.</p>
            </div>
            <button type="button" wire:click="addCommissionSlab" class="rounded-lg bg-zinc-100 px-3 py-1.5 text-xs font-bold text-zinc-800 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 transition">
                + Add Slab
            </button>
        </div>

        <div class="space-y-3">
            @foreach ($this->commissionSlabs as $idx => $slab)
                <div class="flex flex-col sm:flex-row items-center gap-3 rounded-xl border border-zinc-100 bg-zinc-50/50 p-3 dark:border-zinc-800 dark:bg-zinc-800/40">
                    <div class="w-full sm:w-1/3">
                        <select wire:model="commissionSlabs.{{ $idx }}.vertical_id" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                            <option value="">Default (All Other Verticals)</option>
                            @foreach ($verticals as $v)
                                <option value="{{ $v['id'] }}">{{ $v['name'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-center gap-2 w-full sm:w-1/3">
                        <input type="number" step="0.01" wire:model="commissionSlabs.{{ $idx }}.min_price_inr" placeholder="Min ₹" class="w-1/2 rounded-lg border border-zinc-300 px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                        <span class="text-zinc-400">&rarr;</span>
                        <input type="number" step="0.01" wire:model="commissionSlabs.{{ $idx }}.max_price_inr" placeholder="Max ₹ (or empty)" class="w-1/2 rounded-lg border border-zinc-300 px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                    </div>

                    <div class="flex items-center gap-3 w-full sm:w-1/3 justify-between">
                        <div class="flex items-center gap-1">
                            <input type="number" step="0.01" wire:model="commissionSlabs.{{ $idx }}.rate_percent" placeholder="Rate %" class="w-20 rounded-lg border border-zinc-300 px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                            <span class="text-xs text-zinc-500 font-bold">%</span>
                        </div>

                        <button type="button" wire:click="removeCommissionSlab({{ $idx }})" class="text-xs text-red-600 hover:text-red-800 font-bold">
                            Remove
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <!-- Section 2: Closing Slabs -->
    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
        <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
            <div>
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white">2. Fixed Closing Fee Slabs</h3>
                <p class="text-xs text-zinc-500">Charged per unit based on unit selling price.</p>
            </div>
            <button type="button" wire:click="addClosingSlab" class="rounded-lg bg-zinc-100 px-3 py-1.5 text-xs font-bold text-zinc-800 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 transition">
                + Add Slab
            </button>
        </div>

        <div class="space-y-3">
            @foreach ($this->closingSlabs as $idx => $slab)
                <div class="flex flex-col sm:flex-row items-center gap-3 rounded-xl border border-zinc-100 bg-zinc-50/50 p-3 dark:border-zinc-800 dark:bg-zinc-800/40">
                    <div class="flex items-center gap-2 w-full sm:w-1/2">
                        <input type="number" step="0.01" wire:model="closingSlabs.{{ $idx }}.min_price_inr" placeholder="Min ₹" class="w-1/2 rounded-lg border border-zinc-300 px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                        <span class="text-zinc-400">&rarr;</span>
                        <input type="number" step="0.01" wire:model="closingSlabs.{{ $idx }}.max_price_inr" placeholder="Max ₹ (or empty)" class="w-1/2 rounded-lg border border-zinc-300 px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                    </div>

                    <div class="flex items-center gap-3 w-full sm:w-1/2 justify-between">
                        <div class="flex items-center gap-1">
                            <span class="text-xs text-zinc-500">₹</span>
                            <input type="number" step="0.01" wire:model="closingSlabs.{{ $idx }}.fee_inr" placeholder="Fee ₹" class="w-24 rounded-lg border border-zinc-300 px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                            <span class="text-[11px] text-zinc-400">per unit</span>
                        </div>

                        <button type="button" wire:click="removeClosingSlab({{ $idx }})" class="text-xs text-red-600 hover:text-red-800 font-bold">
                            Remove
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <!-- Section 3: Shipping Slabs -->
    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
        <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
            <div>
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white">3. Shipping Fee Slabs</h3>
                <p class="text-xs text-zinc-500">Charged on total package weight and delivery zone.</p>
            </div>
            <button type="button" wire:click="addShippingSlab" class="rounded-lg bg-zinc-100 px-3 py-1.5 text-xs font-bold text-zinc-800 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 transition">
                + Add Slab
            </button>
        </div>

        <div class="space-y-3">
            @foreach ($this->shippingSlabs as $idx => $slab)
                <div class="flex flex-col sm:flex-row items-center gap-3 rounded-xl border border-zinc-100 bg-zinc-50/50 p-3 dark:border-zinc-800 dark:bg-zinc-800/40">
                    <div class="w-full sm:w-1/4">
                        <select wire:model="shippingSlabs.{{ $idx }}.zone" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                            <option value="local">Local</option>
                            <option value="regional">Regional</option>
                            <option value="national">National</option>
                        </select>
                    </div>

                    <div class="flex items-center gap-2 w-full sm:w-1/3">
                        <input type="number" wire:model="shippingSlabs.{{ $idx }}.min_weight" placeholder="Min (g)" class="w-1/2 rounded-lg border border-zinc-300 px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                        <span class="text-zinc-400">&rarr;</span>
                        <input type="number" wire:model="shippingSlabs.{{ $idx }}.max_weight" placeholder="Max (g) or empty" class="w-1/2 rounded-lg border border-zinc-300 px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                    </div>

                    <div class="flex items-center gap-3 w-full sm:w-1/3 justify-between">
                        <div class="flex items-center gap-1">
                            <span class="text-xs text-zinc-500">₹</span>
                            <input type="number" step="0.01" wire:model="shippingSlabs.{{ $idx }}.fee_inr" placeholder="Fee ₹" class="w-20 rounded-lg border border-zinc-300 px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
                        </div>

                        <label class="flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                            <input type="checkbox" wire:model="shippingSlabs.{{ $idx }}.is_default" class="rounded border-zinc-300 text-zinc-900 dark:border-zinc-700" />
                            <span>Default</span>
                        </label>

                        <button type="button" wire:click="removeShippingSlab({{ $idx }})" class="text-xs text-red-600 hover:text-red-800 font-bold">
                            Remove
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>

