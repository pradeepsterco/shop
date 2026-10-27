<?php

namespace App\Branding\Services;

use App\Branding\Contracts\BrandingLookupInterface;
use App\Branding\DTOs\BrandingData;
use App\Branding\Models\BrandingSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BrandingService implements BrandingLookupInterface
{
    public const CACHE_KEY = 'branding.settings';

    protected ?BrandingData $memoized = null;

    /**
     * Get the cached branding settings DTO.
     */
    public function getSettings(): BrandingData
    {
        if ($this->memoized !== null) {
            return $this->memoized;
        }

        $cached = Cache::get(self::CACHE_KEY);

        /** @var array{marketplace_name: string, short_name: string, seller_area_name: string, admin_area_name: string, support_email: ?string, logos: array<string, ?string>, favicon_path: ?string}|null $payload */
        $payload = is_array($cached) ? $cached : null;

        if ($payload === null) {
            $payload = $this->buildPayload();
            Cache::forever(self::CACHE_KEY, $payload);
        }

        $this->memoized = new BrandingData(
            marketplaceName: $payload['marketplace_name'],
            shortName: $payload['short_name'],
            sellerAreaName: $payload['seller_area_name'],
            adminAreaName: $payload['admin_area_name'],
            supportEmail: $payload['support_email'],
            logos: $payload['logos'],
            faviconPath: $payload['favicon_path'],
        );

        return $this->memoized;
    }

    /**
     * Clear the branding settings cache key.
     */
    public function clearCache(): void
    {
        $this->memoized = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Build the raw primitive array payload to be safely cached without class serialization issues.
     *
     * @return array{marketplace_name: string, short_name: string, seller_area_name: string, admin_area_name: string, support_email: ?string, logos: array<string, ?string>, favicon_path: ?string}
     */
    protected function buildPayload(): array
    {
        /** @var BrandingSetting|null $setting */
        $setting = BrandingSetting::first();

        $fallbackName = config('app.name');
        if (blank($fallbackName) || $fallbackName === 'Laravel') {
            $fallbackName = 'Khilauna';
        }

        if (! $setting) {
            return [
                'marketplace_name' => $fallbackName,
                'short_name' => $fallbackName,
                'seller_area_name' => "{$fallbackName} Seller Center",
                'admin_area_name' => "{$fallbackName} Admin Panel",
                'support_email' => null,
                'logos' => [],
                'favicon_path' => null,
            ];
        }

        $logos = [
            'default_logo_light' => $setting->default_logo_light,
            'default_logo_dark' => $setting->default_logo_dark,
            'storefront_logo_light' => $setting->storefront_logo_light,
            'storefront_logo_dark' => $setting->storefront_logo_dark,
            'customer_logo_light' => $setting->customer_logo_light,
            'customer_logo_dark' => $setting->customer_logo_dark,
            'seller_logo_light' => $setting->seller_logo_light,
            'seller_logo_dark' => $setting->seller_logo_dark,
            'admin_logo_light' => $setting->admin_logo_light,
            'admin_logo_dark' => $setting->admin_logo_dark,
            'email_logo_light' => $setting->email_logo_light,
            'email_logo_dark' => $setting->email_logo_dark,
        ];

        return [
            'marketplace_name' => $setting->marketplace_name ?: $fallbackName,
            'short_name' => $setting->short_name ?: ($setting->marketplace_name ?: $fallbackName),
            'seller_area_name' => $setting->seller_area_name ?: (($setting->marketplace_name ?: $fallbackName).' Seller Center'),
            'admin_area_name' => $setting->admin_area_name ?: (($setting->marketplace_name ?: $fallbackName).' Admin Panel'),
            'support_email' => $setting->support_email,
            'logos' => $logos,
            'favicon_path' => $setting->favicon,
        ];
    }

    public function name(?string $area = null): string
    {
        return $this->getSettings()->name($area);
    }

    public function marketplaceName(): string
    {
        return $this->getSettings()->marketplaceName;
    }

    public function sellerAreaName(): string
    {
        return $this->getSettings()->sellerAreaName;
    }

    public function adminAreaName(): string
    {
        return $this->getSettings()->adminAreaName;
    }

    public function supportEmail(): ?string
    {
        return $this->getSettings()->supportEmail;
    }

    public function logoUrl(?string $area = null, bool $dark = false, bool $absolute = false): ?string
    {
        return $this->getSettings()->logoUrl($area, $dark, $absolute);
    }

    public function faviconUrl(): string
    {
        return $this->getSettings()->faviconUrl();
    }

    /**
     * Update text settings.
     *
     * @param  array{marketplace_name?: string, short_name?: ?string, seller_area_name?: ?string, admin_area_name?: ?string, support_email?: ?string}  $data
     */
    public function updateGeneralSettings(array $data): BrandingSetting
    {
        $setting = BrandingSetting::firstOrCreate([], [
            'marketplace_name' => config('app.name', 'Khilauna'),
        ]);

        $setting->update(array_filter([
            'marketplace_name' => $data['marketplace_name'] ?? $setting->marketplace_name,
            'short_name' => array_key_exists('short_name', $data) ? $data['short_name'] : $setting->short_name,
            'seller_area_name' => array_key_exists('seller_area_name', $data) ? $data['seller_area_name'] : $setting->seller_area_name,
            'admin_area_name' => array_key_exists('admin_area_name', $data) ? $data['admin_area_name'] : $setting->admin_area_name,
            'support_email' => array_key_exists('support_email', $data) ? $data['support_email'] : $setting->support_email,
        ], fn ($val) => true));

        $this->clearCache();

        return $setting->fresh();
    }

    /**
     * Upload and replace a logo or favicon.
     * Deletes the old file only after the new file is stored and the DB update succeeds.
     *
     * @throws ValidationException
     */
    public function uploadLogo(string $slot, UploadedFile $file): string
    {
        $validSlots = [
            'default_logo_light',
            'default_logo_dark',
            'storefront_logo_light',
            'storefront_logo_dark',
            'customer_logo_light',
            'customer_logo_dark',
            'seller_logo_light',
            'seller_logo_dark',
            'admin_logo_light',
            'admin_logo_dark',
            'email_logo_light',
            'email_logo_dark',
            'favicon',
        ];

        if (! in_array($slot, $validSlots, true)) {
            throw ValidationException::withMessages(['slot' => "Invalid branding logo slot: {$slot}"]);
        }

        $this->validateUploadedFile($slot, $file);

        $setting = BrandingSetting::firstOrCreate([], [
            'marketplace_name' => config('app.name', 'Khilauna'),
        ]);

        $oldPath = $setting->{$slot};

        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $uniqueFilename = $slot.'-'.Str::random(12).'.'.$extension;
        $storedPath = $file->storeAs('branding', $uniqueFilename, 'public');
        if (! $storedPath) {
            throw ValidationException::withMessages([$slot => 'Failed to store uploaded logo file.']);
        }

        try {
            DB::transaction(function () use ($setting, $slot, $storedPath) {
                $setting->update([$slot => $storedPath]);
            });
        } catch (\Throwable $e) {
            // DB update failed, delete the newly stored file
            Storage::disk('public')->delete($storedPath);
            throw $e;
        }

        // Delete old file only after successful DB update
        if ($oldPath && $oldPath !== $storedPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $this->clearCache();

        return $storedPath;
    }

    /**
     * Reset a logo or favicon to default (deletes uploaded file and clears DB column).
     */
    public function resetLogo(string $slot): void
    {
        $setting = BrandingSetting::first();
        if (! $setting || empty($setting->{$slot})) {
            return;
        }

        $oldPath = (string) $setting->{$slot};

        $setting->update([$slot => null]);

        if (Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $this->clearCache();
    }

    /**
     * Validate upload constraints:
     * - PNG, JPG, WebP only. SVG rejected.
     * - Max 2 MB.
     * - Favicon: square, min 32x32.
     * - Logos: min height 32px.
     *
     * @throws ValidationException
     */
    protected function validateUploadedFile(string $slot, UploadedFile $file): void
    {
        // 1. MIME and extension check (no SVG!)
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = $file->getMimeType();

        $allowedExtensions = ['png', 'jpg', 'jpeg', 'webp'];
        $allowedMimes = ['image/png', 'image/jpeg', 'image/webp'];

        if (! in_array($extension, $allowedExtensions, true) || ! in_array($mime, $allowedMimes, true) || str_contains($mime, 'svg')) {
            throw ValidationException::withMessages([
                $slot => 'The logo must be a PNG, JPG, or WebP image. SVG is not allowed.',
            ]);
        }

        // 2. File size max 2 MB (2048 KB)
        $maxBytes = 2 * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages([
                $slot => 'The file size must not exceed 2 MB.',
            ]);
        }

        // 3. Image dimension validation
        $imageSize = @getimagesize($file->getRealPath());
        if (! $imageSize) {
            throw ValidationException::withMessages([
                $slot => 'Invalid image file.',
            ]);
        }

        [$width, $height] = $imageSize;

        if ($slot === 'favicon') {
            if ($width < 32 || $height < 32) {
                throw ValidationException::withMessages([
                    $slot => 'The favicon must be at least 32×32 pixels.',
                ]);
            }

            if ($width !== $height) {
                throw ValidationException::withMessages([
                    $slot => 'The favicon must be square (width must equal height).',
                ]);
            }
        } else {
            if ($height < 32) {
                throw ValidationException::withMessages([
                    $slot => 'The logo must have a minimum height of 32 pixels.',
                ]);
            }
        }
    }
}
