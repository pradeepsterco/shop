<?php

namespace App\Reviews\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $reviewable_type
 * @property int $reviewable_id
 * @property float $rating_avg
 * @property int $rating_count
 * @property int $star_1
 * @property int $star_2
 * @property int $star_3
 * @property int $star_4
 * @property int $star_5
 * @property Carbon|null $updated_at
 */
class ReviewSummary extends Model
{
    public $timestamps = false;

    protected $table = 'review_summaries';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating_avg' => 'float',
            'rating_count' => 'integer',
            'star_1' => 'integer',
            'star_2' => 'integer',
            'star_3' => 'integer',
            'star_4' => 'integer',
            'star_5' => 'integer',
            'reviewable_id' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get percentage of a given star (1 to 5) relative to approved reviews count.
     */
    public function getStarPercentage(int $star): int
    {
        if ($this->rating_count <= 0) {
            return 0;
        }

        $count = match ($star) {
            1 => $this->star_1,
            2 => $this->star_2,
            3 => $this->star_3,
            4 => $this->star_4,
            5 => $this->star_5,
            default => 0,
        };

        return (int) round(($count / $this->rating_count) * 100);
    }
}
