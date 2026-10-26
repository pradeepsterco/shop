<?php

namespace App\Fees\Contracts;

use App\Fees\Models\FeeSchedule;
use DateTimeInterface;

interface FeeScheduleServiceInterface
{
    public function getActiveSchedule(): ?FeeSchedule;

    public function getScheduleForDate(DateTimeInterface $date): ?FeeSchedule;

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDraft(array $data): FeeSchedule;

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(FeeSchedule $schedule, array $data): FeeSchedule;

    public function cloneSchedule(FeeSchedule $schedule): FeeSchedule;

    public function activateSchedule(FeeSchedule $schedule): FeeSchedule;

    /**
     * Validate that price or weight slabs have zero overlaps and zero gaps.
     *
     * @param  array<int, array{min: int, max: int|null}>  $slabs
     * @return array<int, string> List of error messages, empty if valid
     */
    public function validateSlabsContinuity(array $slabs): array;
}
