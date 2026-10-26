<?php

namespace App\Fees\Services;

use App\Fees\Contracts\FeeScheduleServiceInterface;
use App\Fees\Models\FeeClosingSlab;
use App\Fees\Models\FeeCollectionRule;
use App\Fees\Models\FeeCommissionSlab;
use App\Fees\Models\FeeSchedule;
use App\Fees\Models\FeeShippingSlab;
use App\Shared\Enums\FeeScheduleStatus;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FeeScheduleService implements FeeScheduleServiceInterface
{
    public function getActiveSchedule(): ?FeeSchedule
    {
        return FeeSchedule::with(['commissionSlabs', 'closingSlabs', 'shippingSlabs', 'collectionRules'])
            ->where('status', FeeScheduleStatus::Active)
            ->first();
    }

    public function getScheduleForDate(DateTimeInterface $date): ?FeeSchedule
    {
        $schedule = FeeSchedule::with(['commissionSlabs', 'closingSlabs', 'shippingSlabs', 'collectionRules'])
            ->whereIn('status', [FeeScheduleStatus::Active, FeeScheduleStatus::Archived])
            ->where('effective_from', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();

        if ($schedule) {
            return $schedule;
        }

        // Fallback: If no historical schedule covers this exact date, check if active schedule exists
        return $this->getActiveSchedule();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDraft(array $data): FeeSchedule
    {
        return DB::transaction(function () use ($data) {
            $name = $data['name'] ?? 'Fee Schedule';
            $maxVersion = (int) FeeSchedule::where('name', $name)->max('version');

            $schedule = FeeSchedule::create([
                'name' => $name,
                'version' => $maxVersion + 1,
                'status' => FeeScheduleStatus::Draft,
                'effective_from' => null,
                'effective_to' => null,
                'gst_rate' => $data['gst_rate'] ?? 18.00,
                'tcs_rate' => $data['tcs_rate'] ?? 0.50,
                'tds_standard_rate' => $data['tds_standard_rate'] ?? 0.10,
                'tds_no_pan_rate' => $data['tds_no_pan_rate'] ?? 5.00,
                'default_weight_grams' => $data['default_weight_grams'] ?? 500,
                'created_by_id' => $data['created_by_id'] ?? null,
                'updated_by_id' => $data['updated_by_id'] ?? null,
            ]);

            return $schedule;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(FeeSchedule $schedule, array $data): FeeSchedule
    {
        if ($schedule->status !== FeeScheduleStatus::Draft) {
            throw new InvalidArgumentException('Only draft fee schedules can be modified directly.');
        }

        $schedule->update([
            'name' => $data['name'] ?? $schedule->name,
            'gst_rate' => $data['gst_rate'] ?? $schedule->gst_rate,
            'tcs_rate' => $data['tcs_rate'] ?? $schedule->tcs_rate,
            'tds_standard_rate' => $data['tds_standard_rate'] ?? $schedule->tds_standard_rate,
            'tds_no_pan_rate' => $data['tds_no_pan_rate'] ?? $schedule->tds_no_pan_rate,
            'default_weight_grams' => $data['default_weight_grams'] ?? $schedule->default_weight_grams,
            'updated_by_id' => $data['updated_by_id'] ?? $schedule->updated_by_id,
        ]);

        return $schedule->fresh(['commissionSlabs', 'closingSlabs', 'shippingSlabs', 'collectionRules']);
    }

    public function cloneSchedule(FeeSchedule $schedule): FeeSchedule
    {
        return DB::transaction(function () use ($schedule) {
            $maxVersion = (int) FeeSchedule::where('name', $schedule->name)->max('version');

            /** @var FeeSchedule $clone */
            $clone = FeeSchedule::create([
                'name' => $schedule->name,
                'version' => $maxVersion + 1,
                'status' => FeeScheduleStatus::Draft,
                'effective_from' => null,
                'effective_to' => null,
                'gst_rate' => $schedule->gst_rate,
                'tcs_rate' => $schedule->tcs_rate,
                'tds_standard_rate' => $schedule->tds_standard_rate,
                'tds_no_pan_rate' => $schedule->tds_no_pan_rate,
                'default_weight_grams' => $schedule->default_weight_grams,
                'created_by_id' => auth('admin')->id(),
                'updated_by_id' => auth('admin')->id(),
            ]);

            // Clone Commission Slabs
            foreach ($schedule->commissionSlabs as $slab) {
                FeeCommissionSlab::create([
                    'fee_schedule_id' => $clone->id,
                    'vertical_id' => $slab->vertical_id,
                    'min_price' => $slab->min_price,
                    'max_price' => $slab->max_price,
                    'rate_bps' => $slab->rate_bps,
                ]);
            }

            // Clone Closing Slabs
            foreach ($schedule->closingSlabs as $slab) {
                FeeClosingSlab::create([
                    'fee_schedule_id' => $clone->id,
                    'min_price' => $slab->min_price,
                    'max_price' => $slab->max_price,
                    'fee_amount' => $slab->fee_amount,
                ]);
            }

            // Clone Shipping Slabs
            foreach ($schedule->shippingSlabs as $slab) {
                FeeShippingSlab::create([
                    'fee_schedule_id' => $clone->id,
                    'min_weight_grams' => $slab->min_weight_grams,
                    'max_weight_grams' => $slab->max_weight_grams,
                    'zone' => $slab->zone,
                    'fee_amount' => $slab->fee_amount,
                    'is_default' => $slab->is_default,
                ]);
            }

            // Clone Collection Rules
            foreach ($schedule->collectionRules as $rule) {
                FeeCollectionRule::create([
                    'fee_schedule_id' => $clone->id,
                    'payment_method' => $rule->payment_method,
                    'rate_bps' => $rule->rate_bps,
                    'fee_amount' => $rule->fee_amount,
                ]);
            }

            return $clone->fresh(['commissionSlabs', 'closingSlabs', 'shippingSlabs', 'collectionRules']);
        });
    }

    public function activateSchedule(FeeSchedule $schedule): FeeSchedule
    {
        return DB::transaction(function () use ($schedule) {
            $now = now();

            // Archive the currently active schedule and set effective_to at the exact same instant
            FeeSchedule::where('status', FeeScheduleStatus::Active)
                ->where('id', '!=', $schedule->id)
                ->update([
                    'status' => FeeScheduleStatus::Archived,
                    'effective_to' => $now,
                ]);

            // Activate target schedule
            $schedule->update([
                'status' => FeeScheduleStatus::Active,
                'effective_from' => $now,
                'effective_to' => null,
            ]);

            return $schedule->fresh(['commissionSlabs', 'closingSlabs', 'shippingSlabs', 'collectionRules']);
        });
    }

    /**
     * Validate that price or weight slabs have zero overlaps and zero gaps.
     * Slabs are checked sorted by min ascending.
     *
     * @param  array<int, array{min: int, max: int|null}>  $slabs
     * @return array<int, string>
     */
    public function validateSlabsContinuity(array $slabs): array
    {
        if (empty($slabs)) {
            return ['At least one slab is required.'];
        }

        // Sort by min ascending
        usort($slabs, fn ($a, $b) => $a['min'] <=> $b['min']);

        $errors = [];
        $previousMax = null;

        foreach ($slabs as $index => $slab) {
            $min = (int) $slab['min'];
            $max = $slab['max'] !== null ? (int) $slab['max'] : null;

            if ($max !== null && $max < $min) {
                $errors[] = 'Slab #'.($index + 1)." has maximum value ({$max}) less than minimum ({$min}).";
            }

            if ($previousMax !== null) {
                // If previous max was null (infinite), no subsequent slabs can exist
                if ($previousMax === -1) {
                    $errors[] = 'Slab #'.($index + 1).' cannot follow an unbounded (open-ended) slab.';
                } elseif ($min <= $previousMax) {
                    $errors[] = "Overlap detected between slab #{$index} (max {$previousMax}) and slab #".($index + 1)." (min {$min}).";
                } elseif ($min > ($previousMax + 1)) {
                    $gapStart = $previousMax + 1;
                    $gapEnd = $min - 1;
                    $errors[] = "Gap detected between {$gapStart} and {$gapEnd} between slab #{$index} and slab #".($index + 1).'.';
                }
            }

            $previousMax = $max === null ? -1 : $max;
        }

        return $errors;
    }
}
