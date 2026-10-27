<?php

namespace App\Content\Models;

use App\Content\Enums\SectionVisibility;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $page_id
 * @property string $type
 * @property int $schema_version
 * @property int $position
 * @property SectionVisibility $visibility
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_hidden
 * @property array<string, mixed> $settings
 * @property ContentPage $page
 * @property Collection<int, ContentSectionItem> $items
 */
class ContentSection extends Model
{
    protected $table = 'content_sections';

    protected $fillable = [
        'page_id',
        'type',
        'schema_version',
        'position',
        'visibility',
        'starts_at',
        'ends_at',
        'is_hidden',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'position' => 'integer',
            'visibility' => SectionVisibility::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_hidden' => 'boolean',
            'settings' => 'array',
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
     * @return HasMany<ContentSectionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ContentSectionItem::class, 'section_id')->orderBy('position');
    }
}
