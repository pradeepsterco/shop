<?php

use App\Branding\Contracts\BrandingLookupInterface;
use App\Branding\DTOs\BrandingData;
use App\Branding\Services\BrandingService;
use App\Mail\CustomerWelcomeMail;
use App\Mail\StaffInvitationMail;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('initial branding is seeded from APP_NAME so names are never blank', function () {
    /** @var BrandingLookupInterface $lookup */
    $lookup = app(BrandingLookupInterface::class);

    expect($lookup->marketplaceName())->not->toBeEmpty()
        ->and($lookup->sellerAreaName())->toContain('Seller Center')
        ->and($lookup->adminAreaName())->toContain('Admin Panel');
});

test('each area resolves its own logo, falls back to default logo, then to text', function () {
    Storage::fake('public');

    /** @var BrandingService $service */
    $service = app(BrandingService::class);

    // Initial state: no logos uploaded -> returns null (triggers text fallback)
    expect($service->logoUrl('storefront'))->toBeNull()
        ->and($service->logoUrl('seller'))->toBeNull()
        ->and($service->logoUrl('admin'))->toBeNull()
        ->and($service->logoUrl('customer'))->toBeNull();

    // Upload default logo only
    $defaultFile = UploadedFile::fake()->image('default.png', 200, 50);
    $service->uploadLogo('default_logo_light', $defaultFile);

    // Now all areas fall back to default logo
    $defaultUrl = $service->logoUrl('default');
    expect($defaultUrl)->not->toBeNull()
        ->and($service->logoUrl('storefront'))->toBe($defaultUrl)
        ->and($service->logoUrl('seller'))->toBe($defaultUrl)
        ->and($service->logoUrl('admin'))->toBe($defaultUrl)
        ->and($service->logoUrl('customer'))->toBe($defaultUrl);

    // Upload an area-specific logo (e.g. seller)
    $sellerFile = UploadedFile::fake()->image('seller.png', 180, 45);
    $service->uploadLogo('seller_logo_light', $sellerFile);

    // Seller gets its own logo, storefront still gets default
    expect($service->logoUrl('seller'))->not->toBe($defaultUrl)
        ->and($service->logoUrl('storefront'))->toBe($defaultUrl);
});

test('dark logo falls back to light logo when dark is missing', function () {
    Storage::fake('public');

    /** @var BrandingService $service */
    $service = app(BrandingService::class);

    $lightFile = UploadedFile::fake()->image('admin-light.png', 200, 60);
    $service->uploadLogo('admin_logo_light', $lightFile);

    $lightUrl = $service->logoUrl('admin', dark: false);
    $darkFallbackUrl = $service->logoUrl('admin', dark: true);

    expect($darkFallbackUrl)->toBe($lightUrl);

    // Now upload dark logo
    $darkFile = UploadedFile::fake()->image('admin-dark.png', 200, 60);
    $service->uploadLogo('admin_logo_dark', $darkFile);

    expect($service->logoUrl('admin', dark: true))->not->toBe($lightUrl);
});

test('svg and oversized files are rejected', function () {
    Storage::fake('public');

    /** @var BrandingService $service */
    $service = app(BrandingService::class);

    // 1. SVG rejected
    $svgFile = UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml');

    expect(fn () => $service->uploadLogo('default_logo_light', $svgFile))
        ->toThrow(ValidationException::class);

    // 2. Oversized file (> 2 MB) rejected
    $oversizedFile = UploadedFile::fake()->create('logo.png', 2500, 'image/png');

    expect(fn () => $service->uploadLogo('default_logo_light', $oversizedFile))
        ->toThrow(ValidationException::class);
});

test('favicon must be square and at least 32x32', function () {
    Storage::fake('public');

    /** @var BrandingService $service */
    $service = app(BrandingService::class);

    // Non-square rejected
    $rectangularFile = UploadedFile::fake()->image('fav.png', 64, 32);
    expect(fn () => $service->uploadLogo('favicon', $rectangularFile))
        ->toThrow(ValidationException::class);

    // Square 64x64 accepted
    $squareFile = UploadedFile::fake()->image('fav.png', 64, 64);
    $stored = $service->uploadLogo('favicon', $squareFile);
    expect($stored)->not->toBeEmpty();
});

test('old logo file is deleted on replace only after DB update succeeds', function () {
    Storage::fake('public');

    /** @var BrandingService $service */
    $service = app(BrandingService::class);

    $file1 = UploadedFile::fake()->image('first.png', 100, 40);
    $path1 = $service->uploadLogo('storefront_logo_light', $file1);
    Storage::disk('public')->assertExists($path1);

    $file2 = UploadedFile::fake()->image('second.png', 100, 40);
    $path2 = $service->uploadLogo('storefront_logo_light', $file2);

    Storage::disk('public')->assertExists($path2);
    Storage::disk('public')->assertMissing($path1);
});

test('reset to default deletes uploaded file and nulls setting', function () {
    Storage::fake('public');

    /** @var BrandingService $service */
    $service = app(BrandingService::class);

    $file = UploadedFile::fake()->image('custom.png', 100, 40);
    $path = $service->uploadLogo('customer_logo_light', $file);
    Storage::disk('public')->assertExists($path);

    $service->resetLogo('customer_logo_light');

    Storage::disk('public')->assertMissing($path);
    expect($service->getSettings()->logos['customer_logo_light'])->toBeNull();
});

test('cache is refreshed after update, upload and reset', function () {
    Storage::fake('public');

    /** @var BrandingService $service */
    $service = app(BrandingService::class);

    // Warm cache
    $initialData = $service->getSettings();
    expect(Cache::has(BrandingService::CACHE_KEY))->toBeTrue();

    // 1. Update text settings -> cache cleared and re-warmed
    $service->updateGeneralSettings(['marketplace_name' => 'ToyVerse']);
    expect(Cache::has(BrandingService::CACHE_KEY))->toBeFalse();
    expect($service->marketplaceName())->toBe('ToyVerse');
    expect(Cache::has(BrandingService::CACHE_KEY))->toBeTrue();

    // 2. Upload logo -> cache cleared
    $file = UploadedFile::fake()->image('logo.png', 100, 40);
    $service->uploadLogo('default_logo_light', $file);
    expect(Cache::has(BrandingService::CACHE_KEY))->toBeFalse();
    expect($service->logoUrl('default'))->not->toBeNull();
    expect(Cache::has(BrandingService::CACHE_KEY))->toBeTrue();

    // 3. Reset logo -> cache cleared
    $service->resetLogo('default_logo_light');
    expect(Cache::has(BrandingService::CACHE_KEY))->toBeFalse();
    expect($service->logoUrl('default'))->toBeNull();
    expect(Cache::has(BrandingService::CACHE_KEY))->toBeTrue();
});

test('admin activity log records branding mutations', function () {
    Storage::fake('public');

    $admin = Admin::factory()->create();
    $role = Role::findOrCreate('Super Admin', 'admin');
    $admin->assignRole($role);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.settings.branding')
        ->set('marketplace_name', 'Acme Toys')
        ->set('short_name', 'Acme')
        ->call('saveGeneral')
        ->assertHasNoErrors();

    expect(AdminActivityLog::where('action', 'branding.general_updated')->exists())->toBeTrue();
});

test('emails use light email logo URL with absolute URL and alt as marketplace name', function () {
    Storage::fake('public');

    /** @var BrandingService $service */
    $service = app(BrandingService::class);

    $file = UploadedFile::fake()->image('email-logo.png', 200, 50);
    $service->uploadLogo('email_logo_light', $file);

    $url = $service->logoUrl('email', dark: false, absolute: true);
    expect($url)->toStartWith('http');

    $user = User::factory()->create();
    $mail = new CustomerWelcomeMail($user, 'sample-token-123');
    $rendered = $mail->render();

    expect($rendered)->toContain('alt="'.brand_name().'"')
        ->and($rendered)->toContain($url);
});

test('page title contains no HTML tags across storefront, customer, seller, and admin areas', function () {
    // 1. Storefront
    $sfResponse = $this->get(route('products.index'));
    $sfResponse->assertOk();
    preg_match('/<title>(.*?)<\/title>/is', $sfResponse->getContent(), $sfTitle);
    expect($sfTitle[1] ?? '')->not->toContain('<')->not->toContain('>');

    // 2. Customer Dashboard
    $user = User::factory()->create();
    $custResponse = $this->actingAs($user)->get(route('dashboard'));
    $custResponse->assertOk();
    preg_match('/<title>(.*?)<\/title>/is', $custResponse->getContent(), $custTitle);
    expect($custTitle[1] ?? '')->not->toContain('<')->not->toContain('>');

    // 3. Seller Area
    $sellerUser = User::factory()->create();
    SellerProfile::factory()->approved()->create(['user_id' => $sellerUser->id]);
    $sellerResponse = $this->actingAs($sellerUser)->get(route('seller.dashboard'));
    $sellerResponse->assertOk();
    preg_match('/<title>(.*?)<\/title>/is', $sellerResponse->getContent(), $sellerTitle);
    expect($sellerTitle[1] ?? '')->not->toContain('<')->not->toContain('>');

    // 4. Admin Area
    $admin = Admin::factory()->create();
    $role = Role::findOrCreate('Super Admin', 'admin');
    $admin->assignRole($role);
    $adminResponse = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'));
    $adminResponse->assertOk();
    preg_match('/<title>(.*?)<\/title>/is', $adminResponse->getContent(), $adminTitle);
    expect($adminTitle[1] ?? '')->not->toContain('<')->not->toContain('>');
});

test('no hardcoded brand name Khilauna remains in layouts or email views', function () {
    $scanDirectories = [
        resource_path('views/layouts'),
        resource_path('views/emails'),
    ];

    foreach ($scanDirectories as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $content = file_get_contents($file->getPathname());
                expect($content)
                    ->not->toContain('Khilauna')
                    ->not->toContain('khilauna.com');
            }
        }
    }
});

test('code outside App\Branding reads branding only via BrandingLookupInterface or helpers', function () {
    $appDir = app_path();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir));

    foreach ($files as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $pathname = $file->getPathname();
            // Skip the Branding module itself
            if (str_contains($pathname, 'app'.DIRECTORY_SEPARATOR.'Branding')) {
                continue;
            }

            $content = file_get_contents($pathname);
            expect($content)->not->toContain('BrandingSetting::');
        }
    }
});

test('branding service gracefully handles corrupted or non-array cache data without error', function () {
    $service = app(BrandingLookupInterface::class);

    // Put a non-array value (e.g. integer, string, or incomplete class mock) in cache
    Cache::forever(BrandingService::CACHE_KEY, 'corrupted_string_or_incomplete_class');

    $settings = $service->getSettings();

    expect($settings)->toBeInstanceOf(BrandingData::class)
        ->and($settings->marketplaceName)->toBe('Khilauna');

    // And verify the cache was self-healed to an array
    expect(Cache::get(BrandingService::CACHE_KEY))->toBeArray();
});

test('email templates use absolute logo URLs with APP_URL while web pages use root-relative paths', function () {
    Storage::fake('public');

    /** @var BrandingService $service */
    $service = app(BrandingService::class);

    $file = UploadedFile::fake()->image('logo.png', 120, 40);
    $service->uploadLogo('default_logo_light', $file);

    // 1. Web URLs are root-relative (no http/https prefix)
    $webUrl = brand_logo_url('storefront', dark: false, absolute: false);
    expect($webUrl)->toStartWith('/storage/')
        ->and($webUrl)->not->toMatch('/^https?:\/\//i');

    $sellerWebUrl = brand_logo_url('seller', dark: false, absolute: false);
    expect($sellerWebUrl)->toStartWith('/storage/')
        ->and($sellerWebUrl)->not->toMatch('/^https?:\/\//i');

    // 2. Email logo URLs are absolute (starts with http:// or https://)
    $emailUrl = brand_logo_url('email', dark: false, absolute: true);
    expect($emailUrl)->toMatch('/^https?:\/\//i')
        ->and($emailUrl)->toContain('/storage/');

    // 3. Rendered Blade component <x-brand.logo area="email" isEmail="true" /> produces absolute src
    $renderedEmail = Blade::render('<x-brand.logo area="email" :isEmail="true" />');
    expect($renderedEmail)->toMatch('/src=["\']https?:\/\/[^"\']+["\']/i');

    // 4. Rendered Blade component <x-brand.logo area="seller" /> produces root-relative src
    $renderedWeb = Blade::render('<x-brand.logo area="seller" />');
    expect($renderedWeb)->toMatch('/src=["\']\/storage\/[^"\']+["\']/i')
        ->and($renderedWeb)->not->toMatch('/src=["\']https?:\/\//i');
});

test('rendered emails use an absolute http(s) logo src', function () {
    Storage::fake('public');
    app(BrandingService::class)->uploadLogo('email_logo_light', UploadedFile::fake()->image('email-logo.png', 200, 50));

    $mails = [
        new CustomerWelcomeMail(User::factory()->create(), 'token-1'),
        new StaffInvitationMail(Admin::factory()->create(), 'token-2'),
    ];

    foreach ($mails as $mail) {
        preg_match_all('/<img[^>]+src="([^"]+)"/i', $mail->render(), $matches);

        expect($matches[1])->not->toBeEmpty();
        foreach ($matches[1] as $src) {
            expect($src)->toMatch('/^https?:\/\//i');
        }
    }
});
