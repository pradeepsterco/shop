<?php

namespace App\Content\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $filename
 * @property string $disk
 * @property string $path
 * @property array<string, mixed>|null $variants
 * @property string $mime_type
 * @property int $size_bytes
 * @property int $width
 * @property int $height
 * @property string $alt_text
 * @property Collection<int, ContentSectionItem> $desktopSectionItems
 * @property Collection<int, ContentSectionItem> $mobileSectionItems
 */
class ContentMedia extends Model
{
    protected $table = 'content_media';

    protected $fillable = [
        'filename',
        'disk',
        'path',
        'variants',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'alt_text',
    ];

    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * @return HasMany<ContentSectionItem, $this>
     */
    public function desktopSectionItems(): HasMany
    {
        return $this->hasMany(ContentSectionItem::class, 'desktop_media_id');
    }

    /**
     * @return HasMany<ContentSectionItem, $this>
     */
    public function mobileSectionItems(): HasMany
    {
        return $this->hasMany(ContentSectionItem::class, 'mobile_media_id');
    }

    /**
     * Get the full URL to the main stored image.
     */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * Get the WebP variant URL if generated, otherwise fallback to main URL.
     */
    public function webpUrl(): ?string
    {
        if (! empty($this->variants['webp'])) {
            return Storage::disk($this->disk)->url($this->variants['webp']);
        }

        if ($this->mime_type === 'image/webp') {
            return $this->url();
        }

        return null;
    }

    /**
     * Human-readable file size format.
     */
    public function formattedSize(): string
    {
        $bytes = $this->size_bytes;
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).' MB';
        }

        return number_format($bytes / 1024, 0).' KB';
    }
}
