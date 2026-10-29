<?php

namespace App\Reviews\Services;

use App\Reviews\Enums\ReviewStatus;
use App\Reviews\Models\ProductReview;
use App\Reviews\Models\ReviewSummary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReviewSummaryService
{
    /**
     * Atomically recalculate and persist the summary aggregates for approved reviews only.
     * Uses pessimistic row locking to prevent lost updates or stale aggregates under concurrent submissions.
     */
    public function recalculate(string $reviewableType, int $reviewableId): ReviewSummary
    {
        $summaryId = $this->ensureSummaryRowExists($reviewableType, $reviewableId);

        return DB::transaction(function () use ($reviewableType, $reviewableId, $summaryId) {
            /** @var ReviewSummary $lockedSummary */
            $lockedSummary = ReviewSummary::query()
                ->where('id', $summaryId)
                ->lockForUpdate()
                ->firstOrFail();

            $stats = ProductReview::query()
                ->where('reviewable_type', $reviewableType)
                ->where('reviewable_id', $reviewableId)
                ->where('status', ReviewStatus::Approved->value)
                ->selectRaw('
                    COUNT(*) as total_count,
                    COALESCE(AVG(rating), 0) as avg_rating,
                    SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as count_1,
                    SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as count_2,
                    SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as count_3,
                    SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as count_4,
                    SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as count_5
                ')
                ->first();

            $totalCount = (int) ($stats->total_count ?? 0);
            $avgRating = $totalCount > 0 ? (float) round((float) ($stats->avg_rating ?? 0), 2) : 0.00;

            $lockedSummary->update([
                'rating_avg' => $avgRating,
                'rating_count' => $totalCount,
                'star_1' => (int) ($stats->count_1 ?? 0),
                'star_2' => (int) ($stats->count_2 ?? 0),
                'star_3' => (int) ($stats->count_3 ?? 0),
                'star_4' => (int) ($stats->count_4 ?? 0),
                'star_5' => (int) ($stats->count_5 ?? 0),
                'updated_at' => now(),
            ]);

            return $lockedSummary;
        });
    }

    /**
     * Ensure a summary record exists so row-level locking can serialize aggregate recalculation.
     */
    protected function ensureSummaryRowExists(string $reviewableType, int $reviewableId): int
    {
        $existingId = ReviewSummary::query()
            ->where('reviewable_type', $reviewableType)
            ->where('reviewable_id', $reviewableId)
            ->value('id');

        if ($existingId !== null) {
            return (int) $existingId;
        }

        try {
            $summary = ReviewSummary::create([
                'reviewable_type' => $reviewableType,
                'reviewable_id' => $reviewableId,
                'rating_avg' => 0.00,
                'rating_count' => 0,
                'star_1' => 0,
                'star_2' => 0,
                'star_3' => 0,
                'star_4' => 0,
                'star_5' => 0,
            ]);

            return (int) $summary->id;
        } catch (UniqueConstraintViolationException) {
            return (int) ReviewSummary::query()
                ->where('reviewable_type', $reviewableType)
                ->where('reviewable_id', $reviewableId)
                ->value('id');
        }
    }

    /**
     * Retrieve current summary for a reviewable entity.
     */
    public function getSummary(string $reviewableType, int $reviewableId): ?ReviewSummary
    {
        return ReviewSummary::query()
            ->where('reviewable_type', $reviewableType)
            ->where('reviewable_id', $reviewableId)
            ->first();
    }

    /**
     * Retrieve summaries for multiple products in a single batched query to prevent N+1 queries.
     *
     * @param  array<int>  $productIds
     * @return Collection<int, ReviewSummary>
     */
    public function getSummariesForProducts(array $productIds): Collection
    {
        if (empty($productIds)) {
            return collect();
        }

        return ReviewSummary::query()
            ->where('reviewable_type', 'product')
            ->whereIn('reviewable_id', array_unique($productIds))
            ->get()
            ->keyBy('reviewable_id');
    }
}
