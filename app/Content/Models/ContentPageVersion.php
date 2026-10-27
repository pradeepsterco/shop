<?php

namespace App\Content\Models;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $page_id
 * @property int $version_number
 * @property array<string, mixed> $snapshot
 * @property int|null $published_by
 * @property ContentPage $page
 * @property Admin|null $publisher
 */
class ContentPageVersion extends Model
{
    protected $table = 'content_page_versions';

    protected $fillable = [
        'page_id',
        'version_number',
        'snapshot',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ContentPage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(ContentPage::class, 'page_id');
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'published_by');
    }
}
