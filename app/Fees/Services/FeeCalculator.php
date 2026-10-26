<?php

namespace App\Fees\Services;

use App\Fees\Contracts\FeeCalculatorInterface;
use App\Fees\Contracts\FeeScheduleServiceInterface;
use App\Fees\DTOs\FeeBreakdownDto;
use App\Fees\DTOs\FeeQuoteInput;
use App\Fees\Models\FeeSchedule;
use App\Shared\Enums\ShippingZone;
use App\Shared\ValueObjects\Money;
use DateTimeInterface;
use DomainException;

/**
 * Pure Fee Calculator service.
 *
 * All financial line items are strictly calculated and stored in integer paise.
 * Rounding Rule: Each line item is rounded half-up to the nearest paisa (PHP_ROUND_HALF_UP).
 *
 * Tax Formulae:
 * - Product GST Taxable Value = Line Total / (1 + (Product GST Rate / 100))
 * - Commission = Line Total * (Commission Rate Bps / 10,000)
 * - Fixed Closing Fee = Slab Fixed Fee * Qty (charged per unit)
 * - Shipping Fee = Allocated from package weight & zone (or single-item slab)
 * - Collection Fee = (Line Total * Collection Rate Bps / 10,000) + (Fixed Fee * Qty)
 * - GST on Marketplace Fees = (Commission + Closing + Shipping + Collection) * (GST Rate on Fees / 100)
 * - GST TCS = Taxable Value * (TCS Rate / 100)
 * - Income Tax TDS (Sec 194-O) = Line Total * (TDS Rate / 100) [0.1% with PAN, 5.0% without PAN]
 * - Total Deductions = Commission + Closing + Shipping + Collection + GST on Fees + TCS + TDS
 * - Net Payout = Line Total - Total Deductions
 * - Claimable Credits = GST on Fees + TCS + TDS
 */
class FeeCalculator implements FeeCalculatorInterface
{
    public function __construct(
        private readonly FeeScheduleServiceInterface $scheduleService,
    ) {}

    /**
     * Calculate standalone fee quotation for a single item/listing.
     */
    public function quote(FeeQuoteInput $input): FeeBreakdownDto
    {
        $date = $input->effectiveDate ?? now();
        $schedule = $this->scheduleService->getScheduleForDate($date);

        if (! $schedule) {
            throw new DomainException('No fee schedule found for the specified date.');
        }

        $zone = $this->determineZone(
            $input->sellerPickupState,
            $input->sellerPickupPincode,
            $input->deliveryState,
            $input->deliveryPincode
        );

        $qty = max(1, $input->qty);
        $sellingPricePaise = $input->pricePaise;
        $lineTotalPaise = $sellingPricePaise * $qty;

        $weightGrams = ($input->weightGrams ?? $schedule->default_weight_grams) * $qty;
        $shippingFeePaise = $this->resolveShippingFee($schedule, $zone, $weightGrams);

        return $this->computeBreakdown(
            schedule: $schedule,
            zone: $zone,
            sellingPricePaise: $sellingPricePaise,
            qty: $qty,
            lineTotalPaise: $lineTotalPaise,
            productGstRate: $input->productGstRate,
            verticalId: $input->verticalId,
            shippingFeePaise: $shippingFeePaise,
            paymentMethod: $input->paymentMethod,
            sellerHasPan: $input->sellerHasPan,
        );
    }

    /**
     * Calculate fees for a seller package containing one or more items.
     * Shipping fee is charged once per package based on package total weight,
     * then allocated to items by weight share (with remainder paise to the last item).
     *
     * @param  array<int, FeeQuoteInput>  $items
     * @return array<int, FeeBreakdownDto>
     */
    public function calculateForSellerPackage(
        array $items,
        string $pickupState,
        ?string $pickupPincode,
        ?string $deliveryState,
        ?string $deliveryPincode,
        string $paymentMethod,
        bool $sellerHasPan,
        DateTimeInterface $effectiveDate,
    ): array {
        if (empty($items)) {
            return [];
        }

        $schedule = $this->scheduleService->getScheduleForDate($effectiveDate);
        if (! $schedule) {
            throw new DomainException('No fee schedule found for the specified date.');
        }

        $zone = $this->determineZone($pickupState, $pickupPincode, $deliveryState, $deliveryPincode);

        // 1. Calculate package total weight and individual item weights
        $itemWeights = [];
        $packageTotalWeight = 0;

        foreach ($items as $index => $item) {
            $qty = max(1, $item->qty);
            $weightPerUnit = $item->weightGrams ?? $schedule->default_weight_grams;
            $itemTotalWeight = $weightPerUnit * $qty;

            $itemWeights[$index] = $itemTotalWeight;
            $packageTotalWeight += $itemTotalWeight;
        }

        // 2. Resolve package shipping fee once
        $packageShippingFee = $this->resolveShippingFee($schedule, $zone, $packageTotalWeight);

        // 3. Allocate shipping fee to items by weight share
        $allocatedShipping = [];
        $allocatedSum = 0;
        $totalItems = count($items);

        foreach ($items as $index => $item) {
            if ($index === $totalItems - 1) {
                // Last item gets any remainder paise to ensure exact sum match
                $itemShipping = $packageShippingFee - $allocatedSum;
            } else {
                if ($packageTotalWeight > 0) {
                    $share = $itemWeights[$index] / (float) $packageTotalWeight;
                    $itemShipping = (int) round($packageShippingFee * $share, 0, PHP_ROUND_HALF_UP);
                } else {
                    $itemShipping = (int) round($packageShippingFee / (float) $totalItems, 0, PHP_ROUND_HALF_UP);
                }
                $allocatedSum += $itemShipping;
            }

            $allocatedShipping[$index] = max(0, $itemShipping);
        }

        // 4. Compute full breakdown for each item
        $breakdowns = [];
        foreach ($items as $index => $item) {
            $qty = max(1, $item->qty);
            $sellingPricePaise = $item->pricePaise;
            $lineTotalPaise = $sellingPricePaise * $qty;

            $breakdowns[$index] = $this->computeBreakdown(
                schedule: $schedule,
                zone: $zone,
                sellingPricePaise: $sellingPricePaise,
                qty: $qty,
                lineTotalPaise: $lineTotalPaise,
                productGstRate: $item->productGstRate,
                verticalId: $item->verticalId,
                shippingFeePaise: $allocatedShipping[$index],
                paymentMethod: $paymentMethod,
                sellerHasPan: $sellerHasPan,
            );
        }

        return $breakdowns;
    }

    /**
     * Compute breakdown values for an item.
     */
    private function computeBreakdown(
        FeeSchedule $schedule,
        ShippingZone $zone,
        int $sellingPricePaise,
        int $qty,
        int $lineTotalPaise,
        float $productGstRate,
        ?int $verticalId,
        int $shippingFeePaise,
        string $paymentMethod,
        bool $sellerHasPan,
    ): FeeBreakdownDto {
        // Taxable value (GST-exclusive): lineTotal / (1 + (gstRate / 100))
        $gstDivisor = 1.0 + ($productGstRate / 100.0);
        $taxableValuePaise = (int) round($lineTotalPaise / $gstDivisor, 0, PHP_ROUND_HALF_UP);

        // Commission (on line total)
        $commissionRateBps = $this->resolveCommissionRateBps($schedule, $verticalId, $sellingPricePaise);
        $commissionPaise = (int) round(($lineTotalPaise * $commissionRateBps) / 10000.0, 0, PHP_ROUND_HALF_UP);

        // Closing fee (per unit)
        $closingFeePerUnitPaise = $this->resolveClosingFeePerUnit($schedule, $sellingPricePaise);
        $closingFeePaise = $closingFeePerUnitPaise * $qty;

        // Collection fee (on line total + per unit)
        $collectionFeePaise = $this->resolveCollectionFee($schedule, $paymentMethod, $lineTotalPaise, $qty);

        // Total Marketplace Fees
        $totalMarketplaceFeesPaise = $commissionPaise + $closingFeePaise + $shippingFeePaise + $collectionFeePaise;

        // GST on Fees (default 18%)
        $gstOnFeesPaise = (int) round(($totalMarketplaceFeesPaise * $schedule->gst_rate) / 100.0, 0, PHP_ROUND_HALF_UP);

        // TCS (0.5% on GST-exclusive taxable value)
        $tcsPaise = (int) round(($taxableValuePaise * $schedule->tcs_rate) / 100.0, 0, PHP_ROUND_HALF_UP);

        // TDS (0.1% on gross with PAN, 5.0% without PAN)
        $tdsRate = $sellerHasPan ? $schedule->tds_standard_rate : $schedule->tds_no_pan_rate;
        $tdsPaise = (int) round(($lineTotalPaise * $tdsRate) / 100.0, 0, PHP_ROUND_HALF_UP);

        // Totals
        $totalDeductionsPaise = $totalMarketplaceFeesPaise + $gstOnFeesPaise + $tcsPaise + $tdsPaise;
        $netPayoutPaise = $lineTotalPaise - $totalDeductionsPaise;
        $claimableCreditsPaise = $gstOnFeesPaise + $tcsPaise + $tdsPaise;

        return new FeeBreakdownDto(
            sellingPrice: Money::fromPaise($sellingPricePaise),
            qty: $qty,
            lineTotal: Money::fromPaise($lineTotalPaise),
            taxableValue: Money::fromPaise($taxableValuePaise),
            commission: Money::fromPaise($commissionPaise),
            closingFee: Money::fromPaise($closingFeePaise),
            shippingFee: Money::fromPaise($shippingFeePaise),
            collectionFee: Money::fromPaise($collectionFeePaise),
            totalMarketplaceFees: Money::fromPaise($totalMarketplaceFeesPaise),
            gstOnFees: Money::fromPaise($gstOnFeesPaise),
            tcs: Money::fromPaise($tcsPaise),
            tds: Money::fromPaise($tdsPaise),
            totalDeductions: Money::fromPaise($totalDeductionsPaise),
            netPayout: Money::fromPaise($netPayoutPaise),
            claimableCredits: Money::fromPaise($claimableCreditsPaise),
            zone: $zone,
            scheduleId: (int) $schedule->id,
            scheduleVersion: (int) $schedule->version,
            commissionRateBps: $commissionRateBps,
            gstRateOnFees: $schedule->gst_rate,
            tcsRate: $schedule->tcs_rate,
            tdsRate: $tdsRate,
        );
    }

    private function resolveCommissionRateBps(FeeSchedule $schedule, ?int $verticalId, int $pricePaise): int
    {
        // 1. Try vertical-specific slab
        if ($verticalId !== null) {
            $slab = $schedule->commissionSlabs
                ->where('vertical_id', $verticalId)
                ->first(function ($s) use ($pricePaise) {
                    return $pricePaise >= $s->min_price && ($s->max_price === null || $pricePaise <= $s->max_price);
                });

            if ($slab) {
                return (int) $slab->rate_bps;
            }
        }

        // 2. Try default/fallback slab (vertical_id is null)
        $fallback = $schedule->commissionSlabs
            ->whereNull('vertical_id')
            ->first(function ($s) use ($pricePaise) {
                return $pricePaise >= $s->min_price && ($s->max_price === null || $pricePaise <= $s->max_price);
            });

        return $fallback ? (int) $fallback->rate_bps : 1000; // 10.00% default
    }

    private function resolveClosingFeePerUnit(FeeSchedule $schedule, int $pricePaise): int
    {
        $slab = $schedule->closingSlabs->first(function ($s) use ($pricePaise) {
            return $pricePaise >= $s->min_price && ($s->max_price === null || $pricePaise <= $s->max_price);
        });

        return $slab ? (int) $slab->fee_amount : 0;
    }

    private function resolveShippingFee(FeeSchedule $schedule, ShippingZone $zone, int $weightGrams): int
    {
        $slab = $schedule->shippingSlabs
            ->where('zone', $zone)
            ->first(function ($s) use ($weightGrams) {
                return $weightGrams >= $s->min_weight_grams
                    && ($s->max_weight_grams === null || $weightGrams <= $s->max_weight_grams);
            });

        if ($slab) {
            return (int) $slab->fee_amount;
        }

        // Fallback to default slab for this zone
        $defaultSlab = $schedule->shippingSlabs
            ->where('zone', $zone)
            ->firstWhere('is_default', true);

        if ($defaultSlab) {
            return (int) $defaultSlab->fee_amount;
        }

        // Fallback to any default slab
        $anyDefault = $schedule->shippingSlabs->firstWhere('is_default', true);

        return $anyDefault ? (int) $anyDefault->fee_amount : 5000; // ₹50.00 fallback
    }

    private function resolveCollectionFee(FeeSchedule $schedule, string $paymentMethod, int $lineTotalPaise, int $qty): int
    {
        $rule = $schedule->collectionRules->firstWhere('payment_method', $paymentMethod);
        if (! $rule) {
            return 0;
        }

        $percentagePaise = (int) round(($lineTotalPaise * $rule->rate_bps) / 10000.0, 0, PHP_ROUND_HALF_UP);
        $fixedPaise = $rule->fee_amount * $qty;

        return $percentagePaise + $fixedPaise;
    }

    /**
     * Determine ShippingZone from seller pickup and customer delivery details.
     * Local: First 3 digits of pincode match.
     * Regional: Same state (case-insensitive string comparison).
     * National: Different state or delivery details missing.
     */
    public function determineZone(
        ?string $pickupState,
        ?string $pickupPincode,
        ?string $deliveryState,
        ?string $deliveryPincode,
    ): ShippingZone {
        if (empty($deliveryState) && empty($deliveryPincode)) {
            return ShippingZone::National;
        }

        // 1. Check local (first 3 digits of pincode)
        $cleanPickupPincode = preg_replace('/\D/', '', (string) $pickupPincode);
        $cleanDeliveryPincode = preg_replace('/\D/', '', (string) $deliveryPincode);

        if (strlen($cleanPickupPincode) >= 3 && strlen($cleanDeliveryPincode) >= 3) {
            if (substr($cleanPickupPincode, 0, 3) === substr($cleanDeliveryPincode, 0, 3)) {
                return ShippingZone::Local;
            }
        }

        // 2. Check regional (same state)
        if (! empty($pickupState) && ! empty($deliveryState)) {
            $normPickup = strtolower(trim($pickupState));
            $normDelivery = strtolower(trim($deliveryState));

            if ($normPickup === $normDelivery) {
                return ShippingZone::Regional;
            }
        }

        return ShippingZone::National;
    }
}
