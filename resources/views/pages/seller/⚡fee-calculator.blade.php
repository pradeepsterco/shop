<?php

namespace App\Livewire\Seller;

use App\Catalog\Contracts\VerticalLookupInterface;
use App\Fees\Contracts\FeeCalculatorInterface;
use App\Fees\DTOs\FeeBreakdownDto;
use App\Fees\DTOs\FeeQuoteInput;
use App\Shared\Enums\ShippingZone;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.seller')] #[Title('Fee Calculator')] class extends Component {
    public string $price = '1000';

    public string $productGstRate = '12';

    public ?int $verticalId = null;

    public string $weightGrams = '500';

    public string $zone = 'national';

    public bool $hasPan = true;

    public function mount(): void
    {
        $profile = auth()->user()?->sellerProfile;
        if ($profile) {
            $this->hasPan = ! empty($profile->pan);
        }
    }

    #[Computed]
    public function quote(): ?FeeBreakdownDto
    {
        $calculator = app(FeeCalculatorInterface::class);
        $priceFloat = (float) $this->price;
        if ($priceFloat <= 0) {
            return null;
        }

        $pricePaise = (int) round($priceFloat * 100);
        $gstRate = (float) $this->productGstRate;
        $weight = max(1, (int) $this->weightGrams);

        $zoneEnum = ShippingZone::tryFrom($this->zone) ?? ShippingZone::National;

        $input = new FeeQuoteInput(
            pricePaise: $pricePaise,
            productGstRate: $gstRate,
            verticalId: $this->verticalId,
            weightGrams: $weight,
            sellerPickupState: $zoneEnum === ShippingZone::Regional || $zoneEnum === ShippingZone::Local ? 'Maharashtra' : 'Delhi',
            sellerPickupPincode: $zoneEnum === ShippingZone::Local ? '110001' : '110001',
            deliveryState: 'Maharashtra',
            deliveryPincode: $zoneEnum === ShippingZone::Local ? '110002' : ($zoneEnum === ShippingZone::Regional ? '400001' : '560001'),
            paymentMethod: 'cod',
            sellerHasPan: $this->hasPan,
            effectiveDate: now(),
            qty: 1,
        );

        try {
            return $calculator->quote($input);
        } catch (\Throwable $e) {
            return null;
        }
    }
}; ?>

<div class="space-y-6">
    @php
        $verticals = app(App\Catalog\Contracts\VerticalLookupInterface::class)->getAllActiveVerticals();
        $breakdown = $this->quote;
    @endphp

    <!-- Header -->
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Seller Fee & Payout Calculator</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            Estimate your exact marketplace deductions, net bank payout, and claimable tax credits for any selling price.
        </p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Input Form (Left 1 col) -->
        <div class="lg:col-span-1 rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                Product & Shipping Parameters
            </h3>

            <div>
                <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                    Selling Price (₹, GST-inclusive) *
                </label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-xs text-zinc-400">₹</span>
                    <input type="number" step="0.01" wire:model.live.debounce.300ms="price" class="w-full rounded-lg border border-zinc-300 pl-7 pr-3 py-2 text-xs font-bold text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Product Category / Vertical</label>
                <select wire:model.live="verticalId" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                    <option value="">Default (Standard Category)</option>
                    @foreach ($verticals as $v)
                        <option value="{{ $v['id'] }}">{{ $v['name'] }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Product GST Rate (%)</label>
                <select wire:model.live="productGstRate" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                    <option value="0">0% (Nil / Exempt)</option>
                    <option value="5">5% (Essential Goods)</option>
                    <option value="12">12% (Apparel / Toys / Standard)</option>
                    <option value="18">18% (General Merchandise / Electronics)</option>
                    <option value="28">28% (Luxury / High Tax)</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Package Weight (Grams)</label>
                <input type="number" wire:model.live.debounce.300ms="weightGrams" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Shipping Destination Zone</label>
                <select wire:model.live="zone" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                    <option value="local">Local (Same City / First 3 Pincode Digits)</option>
                    <option value="regional">Regional (Same State)</option>
                    <option value="national">National (Rest of India)</option>
                </select>
            </div>

            <div class="pt-2 border-t border-zinc-100 dark:border-zinc-800">
                <label class="flex items-center gap-2 text-xs text-zinc-700 dark:text-zinc-300">
                    <input type="checkbox" wire:model.live="hasPan" class="rounded border-zinc-300 text-zinc-900 dark:border-zinc-700" />
                    <span>Seller has valid PAN (TDS 0.1% vs 5.0%)</span>
                </label>
            </div>
        </div>

        <!-- Output Breakdown (Right 2 cols) -->
        <div class="lg:col-span-2 space-y-6">
            @if ($breakdown)
                <!-- Big Net Payout Summary Card -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50/70 p-6 shadow-xs dark:border-emerald-900/60 dark:bg-emerald-950/40">
                        <span class="text-xs font-bold uppercase tracking-wider text-emerald-800 dark:text-emerald-300">You'll Receive (Net Payout)</span>
                        <div class="mt-2 text-3xl font-extrabold text-emerald-900 dark:text-emerald-200">
                            {{ $breakdown->netPayout->format() }}
                        </div>
                        <p class="mt-1 text-xs text-emerald-700 dark:text-emerald-400">
                            Deposited directly into your registered bank account upon settlement.
                        </p>
                    </div>

                    <div class="rounded-2xl border border-blue-200 bg-blue-50/70 p-6 shadow-xs dark:border-blue-900/60 dark:bg-blue-950/40">
                        <span class="text-xs font-bold uppercase tracking-wider text-blue-800 dark:text-blue-300">Claimable Tax Credits</span>
                        <div class="mt-2 text-3xl font-extrabold text-blue-900 dark:text-blue-200">
                            {{ $breakdown->claimableCredits->format() }}
                        </div>
                        <p class="mt-1 text-xs text-blue-700 dark:text-blue-400">
                            GST input tax credit + TCS + TDS claimable in your monthly and annual tax filings.
                        </p>
                    </div>
                </div>

                <!-- Detailed Breakdown Table with Plain-Language Explanations -->
                <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                    <div class="border-b border-zinc-100 bg-zinc-50/50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-800/30">
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Fee Breakdown & Deductions</h3>
                        <p class="text-xs text-zinc-500">Itemized explanation of each deduction line.</p>
                    </div>

                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800 text-xs">
                        <!-- Line 1: Selling Price -->
                        <div class="flex items-center justify-between px-6 py-3.5 bg-zinc-50/30 dark:bg-zinc-800/20 font-bold">
                            <div>
                                <span class="text-zinc-900 dark:text-white">Customer Selling Price</span>
                                <p class="text-[11px] font-normal text-zinc-400">Gross price paid by customer (including product GST)</p>
                            </div>
                            <span class="text-sm font-mono text-zinc-900 dark:text-white">{{ $breakdown->sellingPrice->format() }}</span>
                        </div>

                        <!-- Line 2: Commission -->
                        <div class="flex items-center justify-between px-6 py-3">
                            <div>
                                <span class="font-semibold text-zinc-800 dark:text-zinc-200">Marketplace Commission ({{ number_format($breakdown->commissionRateBps / 100, 2) }}%)</span>
                                <p class="text-[11px] text-zinc-400">Platform fee based on product vertical and price slab</p>
                            </div>
                            <span class="font-mono text-red-600 dark:text-red-400 font-semibold">- {{ $breakdown->commission->format() }}</span>
                        </div>

                        <!-- Line 3: Closing Fee -->
                        <div class="flex items-center justify-between px-6 py-3">
                            <div>
                                <span class="font-semibold text-zinc-800 dark:text-zinc-200">Fixed Closing Fee</span>
                                <p class="text-[11px] text-zinc-400">Order processing and catalog listing fee per unit</p>
                            </div>
                            <span class="font-mono text-red-600 dark:text-red-400 font-semibold">- {{ $breakdown->closingFee->format() }}</span>
                        </div>

                        <!-- Line 4: Shipping Fee -->
                        <div class="flex items-center justify-between px-6 py-3">
                            <div>
                                <span class="font-semibold text-zinc-800 dark:text-zinc-200">Shipping & Logistics Fee ({{ $breakdown->zone->label() }})</span>
                                <p class="text-[11px] text-zinc-400">Delivery charge based on package weight and destination</p>
                            </div>
                            <span class="font-mono text-red-600 dark:text-red-400 font-semibold">- {{ $breakdown->shippingFee->format() }}</span>
                        </div>

                        <!-- Line 5: Collection Fee -->
                        <div class="flex items-center justify-between px-6 py-3">
                            <div>
                                <span class="font-semibold text-zinc-800 dark:text-zinc-200">Payment Collection Fee</span>
                                <p class="text-[11px] text-zinc-400">Payment gateway and cash on delivery handling</p>
                            </div>
                            <span class="font-mono text-red-600 dark:text-red-400 font-semibold">- {{ $breakdown->collectionFee->format() }}</span>
                        </div>

                        <!-- Line 6: GST on Fees -->
                        <div class="flex items-center justify-between px-6 py-3 bg-amber-50/20 dark:bg-amber-950/10">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold text-zinc-800 dark:text-zinc-200">GST on Marketplace Fees ({{ $breakdown->gstRateOnFees }}%)</span>
                                    <span class="rounded bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300 px-1.5 py-0.2 text-[10px] font-bold">ITC Claimable</span>
                                </div>
                                <p class="text-[11px] text-zinc-400">18% GST charged on total marketplace fees (claimable in GSTR-3B)</p>
                            </div>
                            <span class="font-mono text-red-600 dark:text-red-400 font-semibold">- {{ $breakdown->gstOnFees->format() }}</span>
                        </div>

                        <!-- Line 7: TCS -->
                        <div class="flex items-center justify-between px-6 py-3 bg-amber-50/20 dark:bg-amber-950/10">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold text-zinc-800 dark:text-zinc-200">GST TCS ({{ $breakdown->tcsRate }}%)</span>
                                    <span class="rounded bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300 px-1.5 py-0.2 text-[10px] font-bold">Ledger Credit</span>
                                </div>
                                <p class="text-[11px] text-zinc-400">0.5% GST TCS on taxable value (₹{{ number_format($breakdown->taxableValue->toRupees(), 2) }}), deposited to your GST cash ledger</p>
                            </div>
                            <span class="font-mono text-red-600 dark:text-red-400 font-semibold">- {{ $breakdown->tcs->format() }}</span>
                        </div>

                        <!-- Line 8: TDS -->
                        <div class="flex items-center justify-between px-6 py-3 bg-amber-50/20 dark:bg-amber-950/10">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold text-zinc-800 dark:text-zinc-200">Income Tax TDS (Sec 194-O) ({{ $breakdown->tdsRate }}%)</span>
                                    <span class="rounded bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300 px-1.5 py-0.2 text-[10px] font-bold">26AS / AIS Credit</span>
                                </div>
                                <p class="text-[11px] text-zinc-400">Deducted and deposited to government against your PAN (claimable in ITR)</p>
                            </div>
                            <span class="font-mono text-red-600 dark:text-red-400 font-semibold">- {{ $breakdown->tds->format() }}</span>
                        </div>

                        <!-- Total Deductions Row -->
                        <div class="flex items-center justify-between px-6 py-3.5 bg-zinc-50/50 dark:bg-zinc-800/30 font-bold border-t border-zinc-200 dark:border-zinc-700">
                            <span class="text-zinc-800 dark:text-zinc-200">Total Deductions</span>
                            <span class="font-mono text-red-600 dark:text-red-400 text-sm">- {{ $breakdown->totalDeductions->format() }}</span>
                        </div>

                        <!-- Final Net Payout Row -->
                        <div class="flex items-center justify-between px-6 py-4 bg-emerald-50/40 dark:bg-emerald-950/30 font-extrabold border-t-2 border-emerald-500">
                            <span class="text-sm text-emerald-900 dark:text-emerald-200">Estimated Net Bank Payout</span>
                            <span class="text-base font-mono text-emerald-700 dark:text-emerald-400">{{ $breakdown->netPayout->format() }}</span>
                        </div>
                    </div>
                </div>
            @else
                <div class="rounded-2xl border border-zinc-200 bg-white p-8 text-center text-zinc-400 dark:border-zinc-800 dark:bg-zinc-900">
                    Enter a valid price above to calculate marketplace fees.
                </div>
            @endif
        </div>
    </div>
</div>
