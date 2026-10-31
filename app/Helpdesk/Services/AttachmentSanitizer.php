<?php

namespace App\Helpdesk\Services;

use DomainException;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copies an attachment from one ticket to its linked ticket without anything that could identify the sender:
 * images are re-encoded (dropping EXIF such as location and device) and every file gets a neutral name.
 */
class AttachmentSanitizer
{
    /**
     * @param  array{path: string, name: string, mime: string, size: int}  $file
     * @return array{path: string, name: string, mime: string, size: int}
     */
    public function copy(array $file, string $targetTicketId, int $position): array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($file['path'])) {
            throw new DomainException('An attachment is missing and cannot be forwarded.');
        }

        $extension = match ($file['mime']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => throw new DomainException('Only images and PDFs can be forwarded.'),
        };

        $contents = (string) $disk->get($file['path']);
        if ($extension !== 'pdf') {
            $contents = $this->reencode($contents, $extension);
        }

        $path = "support_attachments/{$targetTicketId}/".Str::random(40).'.'.$extension;
        $disk->put($path, $contents);

        return [
            'path' => $path,
            'name' => 'attachment-'.$position.'.'.$extension,
            'mime' => $file['mime'],
            'size' => strlen($contents),
        ];
    }

    protected function reencode(string $contents, string $extension): string
    {
        $image = @imagecreatefromstring($contents);
        if (! $image instanceof GdImage) {
            throw new DomainException('An image attachment could not be read, so it was not forwarded.');
        }

        ob_start();
        match ($extension) {
            'png' => imagepng($image),
            'webp' => imagewebp($image, null, 90),
            default => imagejpeg($image, null, 90),
        };
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
