<?php

namespace App\Content\Services;

use App\Content\Models\ContentMedia;
use App\Content\Models\ContentPageVersion;
use App\Content\Models\ContentSectionItem;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaUploadService
{
    /**
     * Upload an image file, generate WebP variant, extract dimensions, and save to database.
     *
     * @throws ValidationException
     */
    public function upload(UploadedFile $file, string $altText, string $disk = 'public'): ContentMedia
    {
        $this->validateFile($file, $altText);

        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $baseName = 'media-'.Str::random(16);
        $fileName = $baseName.'.'.$extension;
        $folder = 'content-media';
        $storedPath = $file->storeAs($folder, $fileName, $disk);

        if (! $storedPath) {
            throw ValidationException::withMessages(['file' => 'Failed to store uploaded image.']);
        }

        // Measure dimensions
        $fullPath = Storage::disk($disk)->path($storedPath);
        $imageInfo = @getimagesize($fullPath);
        $width = $imageInfo ? (int) $imageInfo[0] : 0;
        $height = $imageInfo ? (int) $imageInfo[1] : 0;
        $mimeType = $file->getMimeType() ?: ($imageInfo ? $imageInfo['mime'] : 'image/'.$extension);

        // Generate WebP variant if source is PNG or JPG
        $variants = [];
        if (in_array($extension, ['jpg', 'png'], true) && function_exists('imagewebp')) {
            $webpPath = $folder.'/'.$baseName.'.webp';
            $fullWebpPath = Storage::disk($disk)->path($webpPath);
            if ($this->convertToWebp($fullPath, $fullWebpPath, $extension)) {
                $variants['webp'] = $webpPath;
            }
        }

        return ContentMedia::create([
            'filename' => $file->getClientOriginalName(),
            'disk' => $disk,
            'path' => $storedPath,
            'variants' => ! empty($variants) ? $variants : null,
            'mime_type' => $mimeType,
            'size_bytes' => (int) $file->getSize(),
            'width' => $width,
            'height' => $height,
            'alt_text' => trim($altText),
        ]);
    }

    /**
     * Check if a media file is referenced by active section items OR in any published page version snapshot.
     *
     * @return array<int, string> List of page titles/locations where media is currently used
     */
    public function findMediaUsages(ContentMedia $media): array
    {
        $usages = [];
        $mediaId = $media->id;

        // 1. Check live content_section_items
        $liveItems = ContentSectionItem::query()
            ->with(['section.page'])
            ->where('desktop_media_id', $mediaId)
            ->orWhere('mobile_media_id', $mediaId)
            ->get();

        foreach ($liveItems as $item) {
            $page = $item->section->page;
            $pageName = "{$page->title} (draft section)";
            if (! in_array($pageName, $usages, true)) {
                $usages[] = $pageName;
            }
        }

        // 2. Check immutable published version snapshots of all pages
        $versions = ContentPageVersion::query()->with('page')->get();
        foreach ($versions as $version) {
            $snapshot = $version->snapshot;
            if ($this->snapshotContainsMediaId($snapshot, $mediaId)) {
                $pageTitle = $version->page->title;
                $usageName = "{$pageTitle} (published version #{$version->version_number})";
                if (! in_array($usageName, $usages, true)) {
                    $usages[] = $usageName;
                }
            }
        }

        return $usages;
    }

    /**
     * Delete media record and files if not in use.
     *
     * @throws DomainException
     */
    public function delete(ContentMedia $media): void
    {
        $usages = $this->findMediaUsages($media);
        if (! empty($usages)) {
            $usageList = implode(', ', $usages);
            throw new DomainException("Cannot delete media '{$media->filename}' because it is in use by: {$usageList}");
        }

        // Remove disk files
        if (Storage::disk($media->disk)->exists($media->path)) {
            Storage::disk($media->disk)->delete($media->path);
        }

        if (! empty($media->variants['webp']) && Storage::disk($media->disk)->exists($media->variants['webp'])) {
            Storage::disk($media->disk)->delete($media->variants['webp']);
        }

        $media->delete();
    }

    /**
     * Validate upload rules: PNG/JPG/WebP only, no SVG, max 5 MB, required alt text.
     */
    protected function validateFile(UploadedFile $file, string $altText): void
    {
        if (blank($altText)) {
            throw ValidationException::withMessages(['alt_text' => 'Alt text is required for accessibility.']);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());

        // Explicitly block SVG
        if ($ext === 'svg' || str_contains($mime, 'svg') || str_contains($mime, 'xml')) {
            throw ValidationException::withMessages(['file' => 'SVG files are not allowed for security reasons. Please upload PNG, JPG, or WebP.']);
        }

        // Only allow PNG, JPG, WebP
        if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            throw ValidationException::withMessages(['file' => 'Only PNG, JPG, and WebP images are supported.']);
        }

        // Max 5 MB (5 * 1024 * 1024 = 5242880 bytes)
        if ($file->getSize() > 5242880) {
            throw ValidationException::withMessages(['file' => 'The image size cannot exceed 5 MB.']);
        }
    }

    /**
     * Convert an image to WebP using native GD functions.
     */
    protected function convertToWebp(string $sourcePath, string $targetPath, string $extension): bool
    {
        try {
            $image = match ($extension) {
                'jpg', 'jpeg' => @imagecreatefromjpeg($sourcePath),
                'png' => @imagecreatefrompng($sourcePath),
                default => null,
            };

            if (! $image) {
                return false;
            }

            // Preserve PNG transparency
            if ($extension === 'png') {
                imagepalettetotruecolor($image);
                imagealphablending($image, true);
                imagesavealpha($image, true);
            }

            $success = @imagewebp($image, $targetPath, 85);
            imagedestroy($image);

            return $success;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Recursively scan JSON snapshot array for occurrences of media id in desktop_media_id / mobile_media_id.
     */
    protected function snapshotContainsMediaId(mixed $snapshot, int $mediaId): bool
    {
        if (! is_array($snapshot)) {
            return false;
        }

        foreach ($snapshot as $key => $value) {
            if (in_array($key, ['desktop_media_id', 'mobile_media_id'], true) && (int) $value === $mediaId) {
                return true;
            }

            if (is_array($value) && $this->snapshotContainsMediaId($value, $mediaId)) {
                return true;
            }
        }

        return false;
    }
}
