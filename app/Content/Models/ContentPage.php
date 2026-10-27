<?php

namespace App\Content\Models;

use App\Content\Enums\PageStatus;
use App\Content\Enums\PageType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property PageType $type
 * @property PageStatus $status
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property int|null $published_version_id
 * @property Carbon|null $scheduled_publish_at
 * @property ContentPageVersion|null $publishedVersion
 * @property Collection<int, ContentPageVersion> $versions
 * @property Collection<int, ContentSection> $sections
 */
class ContentPage extends Model
{
    protected $table = 'content_pages';

    protected $fillable = [
        'slug',
        'title',
        'type',
        'status',
        'seo_title',
        'seo_description',
        'published_version_id',
        'scheduled_publish_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => PageType::class,
            'status' => PageStatus::class,
            'scheduled_publish_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ContentPageVersion, $this>
     */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(ContentPageVersion::class, 'published_version_id');
    }

    /**
     * @return HasMany<ContentPageVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ContentPageVersion::class, 'page_id')->orderByDesc('version_number');
    }

    /**
     * @return HasMany<ContentSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(ContentSection::class, 'page_id')->orderBy('position');
    }

    public function isHome(): bool
    {
        return $this->type === PageType::Home;
    }

    public function isPublished(): bool
    {
        return $this->status === PageStatus::Published;
    }
}
