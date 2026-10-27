<?php

namespace App\Content\Models;

use App\Content\Enums\LinkType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $section_id
 * @property int $position
 * @property int|null $desktop_media_id
 * @property int|null $mobile_media_id
 * @property LinkType|null $link_type
 * @property string|null $link_id
 * @property string|null $link_url
 * @property array<string, mixed> $settings
 * @property ContentSection $section
 * @property ContentMedia|null $desktopMedia
 * @property ContentMedia|null $mobileMedia
 */
class ContentSectionItem extends Model
{
    protected $table = 'content_section_items';

    protected $fillable = [
        'section_id',
        'position',
        'desktop_media_id',
        'mobile_media_id',
        'link_type',
        'link_id',
        'link_url',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'desktop_media_id' => 'integer',
            'mobile_media_id' => 'integer',
            'link_type' => LinkType::class,
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ContentSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(ContentSection::class, 'section_id');
    }

    /**
     * @return BelongsTo<ContentMedia, $this>
     */
    public function desktopMedia(): BelongsTo
    {
        return $this->belongsTo(ContentMedia::class, 'desktop_media_id');
    }

    /**
     * @return BelongsTo<ContentMedia, $this>
     */
    public function mobileMedia(): BelongsTo
    {
        return $this->belongsTo(ContentMedia::class, 'mobile_media_id');
    }
}
