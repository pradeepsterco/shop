# Modular CMS & Page Builder Architecture Blueprint
> **Specification for AI Agents**: This document provides a complete, production-ready blueprint to implement an enterprise-grade CMS and dynamic Page Builder into any Laravel + Livewire (v3/v4) project. All components are strictly designed as **multi-file components** (PHP Component Class in `app/Livewire/...` separated from Blade View Template in `resources/views/livewire/...`).

---

## Table of Contents
1. [Architecture Overview & Design Principles](#1-architecture-overview--design-principles)
2. [Database Schema & Migrations](#2-database-schema--migrations)
3. [Domain Enums & Data Transfer Objects (DTOs)](#3-domain-enums--data-transfer-objects-dtos)
4. [Eloquent Models & Relationships](#4-eloquent-models--relationships)
5. [Contracts & Section Registry System](#5-contracts--section-registry-system)
6. [Core Service Layer](#6-core-service-layer)
   - [PagePublishService (Transactions, Snapshots, Rollback)](#pagepublishservice)
   - [PageResolver (Caching, Time Filtering & Dynamic Catalog Enrichment)](#pageresolver)
   - [MediaUploadService (WebP Conversion & Referential Integrity)](#mediauploadservice)
   - [LinkResolverService & CatalogReadInterface](#linkresolverservice--catalogreadinterface)
   - [MarkdownSanitizer](#markdownsanitizer)
7. [Multi-File Livewire Components (Admin)](#7-multi-file-livewire-components-admin)
   - [PagesIndex (Class + Blade Template)](#pagesindex)
   - [PageBuilder (Class + Blade Template)](#pagebuilder)
8. [Multi-File Livewire Components (Storefront) & Preview Shell](#8-multi-file-livewire-components-storefront--preview-shell)
   - [HomePage (Class + Blade Template)](#homepage)
   - [StaticPage (Class + Blade Template)](#staticpage)
   - [Device-Adaptive Live Preview Shell](#preview-shell)
9. [Storefront Blade Section Components](#9-storefront-blade-section-components)
10. [Console Automation & Scheduled Publishing](#10-console-automation--scheduled-publishing)
11. [Step-by-Step AI Agent Implementation Guide](#11-step-by-step-ai-agent-implementation-guide)

---

## 1. Architecture Overview & Design Principles

```
+-----------------------------------------------------------------------------------+
|                                 ADMIN INTERFACE                                   |
|   +------------------------------------+   +----------------------------------+   |
|   | PagesIndex (Livewire Multi-File)   |   | PageBuilder (Livewire Multi-File)|   |
|   | - Search, Filter, Create, Delete   |   | - Reorder, Settings, Modals      |   |
|   +------------------------------------+   +----------------------------------+   |
+------------------------------------------+----------------------------------------+
                                           |
                                           v
+-----------------------------------------------------------------------------------+
|                                 CORE SERVICES                                     |
|   +---------------------+   +---------------------+   +-----------------------+   |
|   | PagePublishService  |   | PageResolver        |   | MediaUploadService    |   |
|   | - Atomic Snapshots  |   | - Tagged Caching    |   | - WebP Auto-Convert   |   |
|   | - Single-Home Rule  |   | - Time Gating       |   | - Dependency Check    |   |
|   | - Safe Rollback     |   | - Catalog Enricher  |   | - Dimensions Reading  |   |
|   +---------------------+   +---------------------+   +-----------------------+   |
+------------------------------------------+----------------------------------------+
                                           |
                                           v
+-----------------------------------------------------------------------------------+
|                              DATA PERSISTENCE LAYER                               |
|   content_pages  <--->  content_page_versions (JSON Snapshots)                    |
|         |                                                                         |
|         +---> content_sections ---> content_section_items <---> content_media     |
+------------------------------------------+----------------------------------------+
                                           |
                                           v
+-----------------------------------------------------------------------------------+
|                               STOREFRONT PRESENTATION                             |
|   +------------------------------------+   +----------------------------------+   |
|   | HomePage (Livewire Multi-File)     |   | StaticPage (Livewire Multi-File) |   |
|   | - Resolves active Home slug        |   | - Resolves slug with 404 guard   |   |
|   +------------------------------------+   +----------------------------------+   |
|   Dynamic Section Dispatcher: <x-dynamic-component :component="'content.sections.' . $type" />
|   Built-in: Hero Carousel, Single Banner, Wide Banner, Image Grid, Category Nav,  |
|             Promo Cards, Brand Tiles, Countdown Timers, Rich Text (Markdown)      |
+-----------------------------------------------------------------------------------+
```

### Key Architectural Invariants
1. **Separation of Component Logic and Views**: Every Livewire component consists of an explicit PHP class extending `Livewire\Component` in `app/Livewire/...` with an explicit `render()` method pointing to its paired Blade view in `resources/views/livewire/...`. No single-file Volt components.
2. **Registry-Driven Extensibility**: Adding a new section type never requires modifying the page builder core or the database schema. Developers define a class implementing `SectionTypeDefinitionInterface` and register it with the `SectionTypeRegistry`.
3. **Immutable Version Snapshots**: Publishing a page compiles the entire page hierarchy (sections, items, settings, media paths, links) into a JSON snapshot within `content_page_versions`. The live website reads this snapshot, completely isolating storefront performance from admin editing drafts.
4. **Instant Non-Destructive Rollback**: Any historical version snapshot can be restored into draft records without losing version audit history.
5. **Single Published Home Constraint**: A marketplace or website can have multiple draft homepages, but publishing one automatically flips any existing published home page to draft within a database transaction.
6. **Smart Time Filtering & Dynamic Catalog Enrichment**: Cache static layout structures for 24 hours, but evaluate section `starts_at`/`ends_at` time gates dynamically per-request, and query live stock, pricing, and availability so customers never see stale inventory.
7. **Referential Integrity for Media**: Uploaded media cannot be deleted if referenced in active section items or published snapshots.

---

## 2. Database Schema & Migrations

Create migration file: `database/migrations/xxxx_xx_xx_create_content_module_tables.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Reusable Media Library
        Schema::create('content_media', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('disk', 50)->default('public');
            $table->string('path', 500);
            $table->json('variants')->nullable(); // e.g. {"webp": "content-media/uuid.webp"}
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('alt_text', 255);
            $table->timestamps();

            $table->index('mime_type');
            $table->index('created_at');
        });

        // 2. Content Pages
        Schema::create('content_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 150)->unique();
            $table->string('title', 255);
            $table->string('type', 30)->default('landing'); // 'home', 'landing', 'static'
            $table->string('status', 30)->default('draft'); // 'draft', 'scheduled', 'published'
            $table->string('seo_title', 255)->nullable();
            $table->text('seo_description')->nullable();
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->timestamp('scheduled_publish_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['status', 'scheduled_publish_at']);
        });

        // 3. Page Versions (Immutable JSON Snapshots)
        Schema::create('content_page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('content_pages')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('snapshot');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete(); // or 'admins'
            $table->timestamps();

            $table->unique(['page_id', 'version_number']);
        });

        // Add foreign key constraint for published_version_id
        Schema::table('content_pages', function (Blueprint $table) {
            $table->foreign('published_version_id')
                ->references('id')
                ->on('content_page_versions')
                ->nullOnDelete();
        });

        // 4. Content Sections (Working Draft Records)
        Schema::create('content_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('content_pages')->cascadeOnDelete();
            $table->string('type', 50);
            $table->unsignedInteger('schema_version')->default(1);
            $table->unsignedInteger('position')->default(0);
            $table->string('visibility', 20)->default('all'); // 'all', 'desktop', 'mobile'
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->json('settings');
            $table->timestamps();

            $table->index(['page_id', 'position']);
            $table->index(['page_id', 'is_hidden']);
        });

        // 5. Content Section Items
        Schema::create('content_section_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('content_sections')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('desktop_media_id')->nullable()->constrained('content_media')->restrictOnDelete();
            $table->foreignId('mobile_media_id')->nullable()->constrained('content_media')->restrictOnDelete();
            $table->string('link_type', 30)->nullable(); // 'category', 'brand', 'product', 'page', 'search', 'url'
            $table->string('link_id', 150)->nullable();
            $table->string('link_url', 1000)->nullable();
            $table->json('settings');
            $table->timestamps();

            $table->index(['section_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_section_items');
        Schema::dropIfExists('content_sections');

        Schema::table('content_pages', function (Blueprint $table) {
            $table->dropForeign(['published_version_id']);
        });

        Schema::dropIfExists('content_page_versions');
        Schema::dropIfExists('content_pages');
        Schema::dropIfExists('content_media');
    }
};
```

---

## 3. Domain Enums & Data Transfer Objects (DTOs)

### `app/Content/Enums/PageType.php`
```php
<?php

namespace App\Content\Enums;

enum PageType: string
{
    case Home = 'home';
    case Landing = 'landing';
    case Static = 'static';

    public function label(): string
    {
        return match ($this) {
            self::Home => 'Homepage',
            self::Landing => 'Marketing Campaign / Landing Page',
            self::Static => 'Standard / Static Page',
        };
    }
}
```

### `app/Content/Enums/PageStatus.php`
```php
<?php

namespace App\Content\Enums;

enum PageStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Published => 'Published',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
            self::Scheduled => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
            self::Published => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
        };
    }
}
```

### `app/Content/Enums/SectionVisibility.php`
```php
<?php

namespace App\Content\Enums;

enum SectionVisibility: string
{
    case All = 'all';
    case Desktop = 'desktop';
    case Mobile = 'mobile';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All Devices (Desktop & Mobile)',
            self::Desktop => 'Desktop Only (≥ 768px)',
            self::Mobile => 'Mobile Only (< 768px)',
        };
    }
}
```

### `app/Content/Enums/LinkType.php`
```php
<?php

namespace App\Content\Enums;

enum LinkType: string
{
    case Category = 'category';
    case Brand = 'brand';
    case Product = 'product';
    case Page = 'page';
    case Search = 'search';
    case Url = 'url';

    public function label(): string
    {
        return match ($this) {
            self::Category => 'Category Link',
            self::Brand => 'Brand Link',
            self::Product => 'Product Link',
            self::Page => 'Content Page Link',
            self::Search => 'Search Query Link',
            self::Url => 'External / Custom URL',
        };
    }
}
```

### DTOs in `app/Content/DTOs/`

#### `ResolvedPageData.php`
```php
<?php

namespace App\Content\DTOs;

readonly class ResolvedPageData
{
    /**
     * @param array<int, ResolvedSectionData> $sections
     */
    public function __construct(
        public int $id,
        public string $slug,
        public string $title,
        public string $type,
        public ?string $seoTitle,
        public ?string $seoDescription,
        public array $sections = [],
    ) {}
}
```

#### `ResolvedSectionData.php`
```php
<?php

namespace App\Content\DTOs;

readonly class ResolvedSectionData
{
    /**
     * @param array<string, mixed> $settings
     * @param array<int, ResolvedItemData> $items
     */
    public function __construct(
        public int $id,
        public string $type,
        public int $position,
        public string $visibility, // 'all', 'desktop', 'mobile'
        public array $settings,
        public array $items = [],
        public bool $isEager = false,
        public ?string $startsAt = null,
        public ?string $endsAt = null,
    ) {}
}
```

#### `ResolvedItemData.php`
```php
<?php

namespace App\Content\DTOs;

readonly class ResolvedItemData
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        public int $id,
        public ?ResolvedMediaData $desktopMedia,
        public ?ResolvedMediaData $mobileMedia,
        public ?ResolvedLinkData $link,
        public array $settings = [],
    ) {}
}
```

#### `ResolvedMediaData.php`
```php
<?php

namespace App\Content\DTOs;

readonly class ResolvedMediaData
{
    /**
     * @param array<string, mixed> $variants
     */
    public function __construct(
        public int $id,
        public string $url,
        public ?string $webpUrl,
        public int $width,
        public int $height,
        public string $alt,
        public array $variants = [],
    ) {}
}
```

#### `ResolvedLinkData.php`
```php
<?php

namespace App\Content\DTOs;

readonly class ResolvedLinkData
{
    public function __construct(
        public ?string $url,
        public bool $isValid,
        public bool $isExternal,
        public ?string $target = null,
        public ?string $rel = null,
        public ?string $error = null,
    ) {}
}
```

---

## 4. Eloquent Models & Relationships

### `app/Content/Models/ContentPage.php`
```php
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

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(ContentPageVersion::class, 'published_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContentPageVersion::class, 'page_id')->orderByDesc('version_number');
    }

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
```

### `app/Content/Models/ContentPageVersion.php`
```php
<?php

namespace App\Content\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $page_id
 * @property int $version_number
 * @property array $snapshot
 * @property int|null $published_by
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

    public function page(): BelongsTo
    {
        return $this->belongsTo(ContentPage::class, 'page_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by'); // or Admin::class
    }
}
```

### `app/Content/Models/ContentSection.php`
```php
<?php

namespace App\Content\Models;

use App\Content\Enums\SectionVisibility;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $page_id
 * @property string $type
 * @property int $schema_version
 * @property int $position
 * @property SectionVisibility $visibility
 * @property \Carbon\Carbon|null $starts_at
 * @property \Carbon\Carbon|null $ends_at
 * @property bool $is_hidden
 * @property array $settings
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
            'is_hidden' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(ContentPage::class, 'page_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContentSectionItem::class, 'section_id')->orderBy('position');
    }
}
```

### `app/Content/Models/ContentSectionItem.php`
```php
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
 * @property array $settings
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
            'link_type' => LinkType::class,
            'settings' => 'array',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ContentSection::class, 'section_id');
    }

    public function desktopMedia(): BelongsTo
    {
        return $this->belongsTo(ContentMedia::class, 'desktop_media_id');
    }

    public function mobileMedia(): BelongsTo
    {
        return $this->belongsTo(ContentMedia::class, 'mobile_media_id');
    }
}
```

### `app/Content/Models/ContentMedia.php`
```php
<?php

namespace App\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $filename
 * @property string $disk
 * @property string $path
 * @property array|null $variants
 * @property string $mime_type
 * @property int $size_bytes
 * @property int $width
 * @property int $height
 * @property string $alt_text
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

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function getWebpUrlAttribute(): ?string
    {
        if (isset($this->variants['webp'])) {
            return Storage::disk($this->disk)->url($this->variants['webp']);
        }

        return null;
    }
}
```

---

## 5. Contracts & Section Registry System

### `app/Content/Contracts/SectionTypeDefinitionInterface.php`
```php
<?php

namespace App\Content\Contracts;

interface SectionTypeDefinitionInterface
{
    public function type(): string;
    public function label(): string;
    public function description(): string;
    public function schemaVersion(): int;

    /** @return array<string, mixed> */
    public function settingsRules(): array;

    /** @return array<string, mixed> */
    public function itemRules(): array;

    /** @return array<string, mixed> */
    public function defaultSettings(): array;

    /** @return array<string, mixed> */
    public function defaultItemSettings(): array;

    public function supportsItems(): bool;
    public function maxItems(): ?int;
    public function bladeComponent(): string;

    /**
     * Upgrade old settings schemas to latest version.
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function upgradeSettings(array $settings, int $fromVersion): array;
}
```

### `app/Content/Sections/SectionTypeRegistry.php`
```php
<?php

namespace App\Content\Sections;

use App\Content\Contracts\SectionTypeDefinitionInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SectionTypeRegistry
{
    /** @var array<string, SectionTypeDefinitionInterface> */
    protected array $types = [];

    public function __construct()
    {
        $this->registerDefaultTypes();
    }

    protected function registerDefaultTypes(): void
    {
        $this->register(new HeroCarouselSection);
        $this->register(new SingleBannerSection);
        $this->register(new WideBannerSection);
        $this->register(new ImageTileGridSection);
        $this->register(new CategoryNavSection);
        $this->register(new PromoCardCarouselSection);
        $this->register(new BrandTilesSection);
        $this->register(new CountdownSection);
        $this->register(new RichTextSection);
    }

    public function register(SectionTypeDefinitionInterface $definition): void
    {
        $this->types[$definition->type()] = $definition;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function get(string $type): SectionTypeDefinitionInterface
    {
        if (! isset($this->types[$type])) {
            throw new InvalidArgumentException("Unknown section type: {$type}");
        }

        return $this->types[$type];
    }

    /** @return array<string, SectionTypeDefinitionInterface> */
    public function all(): array
    {
        return $this->types;
    }

    public function validateSettings(string $type, array $settings): array
    {
        $definition = $this->get($type);
        $rules = $definition->settingsRules();

        if (empty($rules)) {
            return $settings;
        }

        return Validator::make($settings, $rules)->validate();
    }

    public function validateItemSettings(string $type, array $settings): array
    {
        $definition = $this->get($type);
        $rules = $definition->itemRules();

        if (empty($rules)) {
            return $settings;
        }

        return Validator::make($settings, $rules)->validate();
    }
}
```

### Example Section Type Definition: `app/Content/Sections/HeroCarouselSection.php`
```php
<?php

namespace App\Content\Sections;

use App\Content\Contracts\SectionTypeDefinitionInterface;

class HeroCarouselSection implements SectionTypeDefinitionInterface
{
    public function type(): string { return 'hero_carousel'; }
    public function label(): string { return 'Hero Carousel'; }
    public function description(): string { return 'Full-width or framed responsive carousel with autoplay, dots, and CTA links.'; }
    public function schemaVersion(): int { return 1; }

    public function settingsRules(): array
    {
        return [
            'autoplay' => 'boolean',
            'interval_ms' => 'integer|min:2000|max:15000',
            'show_dots' => 'boolean',
            'show_arrows' => 'boolean',
        ];
    }

    public function itemRules(): array
    {
        return [
            'headline' => 'nullable|string|max:120',
            'subheadline' => 'nullable|string|max:255',
            'cta_text' => 'nullable|string|max:40',
        ];
    }

    public function defaultSettings(): array
    {
        return [
            'autoplay' => true,
            'interval_ms' => 5000,
            'show_dots' => true,
            'show_arrows' => true,
        ];
    }

    public function defaultItemSettings(): array
    {
        return [
            'headline' => 'Spring Clearance Event',
            'subheadline' => 'Up to 50% off top curated electronics & fashion',
            'cta_text' => 'Shop Collection',
        ];
    }

    public function supportsItems(): bool { return true; }
    public function maxItems(): ?int { return 10; }
    public function bladeComponent(): string { return 'content.sections.hero-carousel'; }

    public function upgradeSettings(array $settings, int $fromVersion): array
    {
        return $settings;
    }
}
```

Implement the other 8 default sections following the same interface:
1. `SingleBannerSection` (`single_banner`): 1 item banner with headline and CTA.
2. `WideBannerSection` (`wide_banner`): Edge-to-edge promotional banner.
3. `ImageTileGridSection` (`image_tile_grid`): 2x2, 3x3, or 4-column responsive grid of image tiles.
4. `CategoryNavSection` (`category_nav`): Circular category avatars with titles and product counts.
5. `PromoCardCarouselSection` (`promo_card_carousel`): Horizontal slider of discount vouchers, highlight cards, or offers.
6. `BrandTilesSection` (`brand_tiles`): Logo grid of authorized marketplace brands.
7. `CountdownSection` (`countdown`): Dynamic ticking flash sale banner (`end_time`, `badge`, `headline`, `subheadline`).
8. `RichTextSection` (`rich_text`): Content section rendering sanitized markdown (`content_markdown`).

---

## 6. Core Service Layer

### `PagePublishService`
Path: `app/Content/Services/PagePublishService.php`
- **Transactions**: Atomic execution of version creation and page status transition.
- **Single Published Home Invariant**: Flips any other published home page to `draft` and purges its cache.
- **Snapshot Compilation**: Deep JSON serialization of all sections and items.
- **Rollback Functionality**: Restores snapshot into live editable draft rows.

```php
<?php

namespace App\Content\Services;

use App\Content\Enums\PageStatus;
use App\Content\Enums\PageType;
use App\Content\Models\ContentPage;
use App\Content\Models\ContentPageVersion;
use App\Content\Models\ContentSection;
use App\Content\Models\ContentSectionItem;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PagePublishService
{
    public function publish(ContentPage $page, ?int $userId = null): ContentPageVersion
    {
        return DB::transaction(function () use ($page, $userId) {
            // 1. Enforce single published home page
            if ($page->type === PageType::Home) {
                $otherHomes = ContentPage::where('type', PageType::Home)
                    ->where('id', '!=', $page->id)
                    ->where('status', PageStatus::Published)
                    ->get();

                foreach ($otherHomes as $otherHome) {
                    $otherHome->update(['status' => PageStatus::Draft]);
                    $this->clearCache($otherHome->slug);
                }
            }

            // 2. Build full immutable snapshot
            $page->load(['sections.items']);
            $snapshot = $this->buildSnapshot($page);

            // 3. Compute next version number
            $latestVersion = ContentPageVersion::where('page_id', $page->id)->max('version_number') ?? 0;
            $nextVersionNumber = $latestVersion + 1;

            // 4. Create version record
            $version = ContentPageVersion::create([
                'page_id' => $page->id,
                'version_number' => $nextVersionNumber,
                'snapshot' => $snapshot,
                'published_by' => $userId,
            ]);

            // 5. Update page state
            $page->update([
                'status' => PageStatus::Published,
                'published_version_id' => $version->id,
                'scheduled_publish_at' => null,
            ]);

            // 6. Invalidate cache
            $this->clearCache($page->slug);

            return $version;
        });
    }

    public function schedule(ContentPage $page, \DateTimeInterface $publishAt): void
    {
        $page->update([
            'status' => PageStatus::Scheduled,
            'scheduled_publish_at' => $publishAt,
        ]);
    }

    public function rollback(ContentPage $page, ContentPageVersion $version): void
    {
        if ($version->page_id !== $page->id) {
            throw new DomainException("Version does not belong to page.");
        }

        DB::transaction(function () use ($page, $version) {
            $snapshot = $version->snapshot;

            // Delete current draft sections and cascade items
            $page->sections()->delete();

            // Reconstruct sections and items from snapshot
            foreach ($snapshot['sections'] ?? [] as $sectionData) {
                $section = ContentSection::create([
                    'page_id' => $page->id,
                    'type' => $sectionData['type'],
                    'schema_version' => $sectionData['schema_version'] ?? 1,
                    'position' => $sectionData['position'],
                    'visibility' => $sectionData['visibility'] ?? 'all',
                    'starts_at' => $sectionData['starts_at'] ?? null,
                    'ends_at' => $sectionData['ends_at'] ?? null,
                    'is_hidden' => $sectionData['is_hidden'] ?? false,
                    'settings' => $sectionData['settings'] ?? [],
                ]);

                foreach ($sectionData['items'] ?? [] as $itemData) {
                    ContentSectionItem::create([
                        'section_id' => $section->id,
                        'position' => $itemData['position'],
                        'desktop_media_id' => $itemData['desktop_media_id'] ?? null,
                        'mobile_media_id' => $itemData['mobile_media_id'] ?? null,
                        'link_type' => $itemData['link_type'] ?? null,
                        'link_id' => $itemData['link_id'] ?? null,
                        'link_url' => $itemData['link_url'] ?? null,
                        'settings' => $itemData['settings'] ?? [],
                    ]);
                }
            }

            $page->update(['status' => PageStatus::Draft]);
            $this->clearCache($page->slug);
        });
    }

    public function clearCache(string $slug): void
    {
        Cache::forget("content.page.{$slug}.published");
    }

    protected function buildSnapshot(ContentPage $page): array
    {
        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'title' => $page->title,
            'type' => $page->type->value,
            'seo_title' => $page->seo_title,
            'seo_description' => $page->seo_description,
            'snapshot_at' => now()->toIso8601String(),
            'sections' => $page->sections->map(function (ContentSection $section) {
                return [
                    'id' => $section->id,
                    'type' => $section->type,
                    'schema_version' => $section->schema_version,
                    'position' => $section->position,
                    'visibility' => $section->visibility->value,
                    'starts_at' => $section->starts_at?->toIso8601String(),
                    'ends_at' => $section->ends_at?->toIso8601String(),
                    'is_hidden' => $section->is_hidden,
                    'settings' => $section->settings,
                    'items' => $section->items->map(function (ContentSectionItem $item) {
                        return [
                            'id' => $item->id,
                            'position' => $item->position,
                            'desktop_media_id' => $item->desktop_media_id,
                            'mobile_media_id' => $item->mobile_media_id,
                            'link_type' => $item->link_type?->value,
                            'link_id' => $item->link_id,
                            'link_url' => $item->link_url,
                            'settings' => $item->settings,
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
        ];
    }
}
```

---

### `PageResolver`
Path: `app/Content/Services/PageResolver.php`
- Resolves presentation DTOs for storefront and live admin preview.
- Caches immutable section tree for 24 hours.
- Evaluates `starts_at`/`ends_at` time gates **after** reading cache.
- Dynamically enriches live catalog entities (pricing, stock, availability) so inventory is never stale.

```php
<?php

namespace App\Content\Services;

use App\Catalog\Contracts\CatalogReadInterface;
use App\Content\DTOs\ResolvedItemData;
use App\Content\DTOs\ResolvedMediaData;
use App\Content\DTOs\ResolvedPageData;
use App\Content\DTOs\ResolvedSectionData;
use App\Content\Enums\LinkType;
use App\Content\Models\ContentMedia;
use App\Content\Models\ContentPage;
use App\Content\Models\ContentPageVersion;
use App\Content\Models\ContentSectionItem;
use App\Content\Sections\SectionTypeRegistry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PageResolver
{
    public function __construct(
        protected SectionTypeRegistry $typeRegistry,
        protected LinkResolverService $linkResolver,
        protected CatalogReadInterface $catalogRead,
        protected MarkdownSanitizer $markdownSanitizer
    ) {}

    public function resolve(ContentPage $page, string $mode = 'storefront'): ?ResolvedPageData
    {
        if ($mode === 'preview') {
            $page->load(['sections.items.desktopMedia', 'sections.items.mobileMedia']);
            $resolved = $this->resolveFromPageModel($page, $mode);
            return $this->applyTimeFilters($resolved, now(), $mode);
        }

        if (! $page->isPublished() && ! $page->published_version_id) {
            return null;
        }

        $cacheKey = "content.page.{$page->slug}.published";

        /** @var ResolvedPageData $cachedStructure */
        $cachedStructure = Cache::remember($cacheKey, 86400, function () use ($page) {
            $version = $page->published_version_id
                ? ContentPageVersion::find($page->published_version_id)
                : $page->publishedVersion;

            if ($version) {
                return $this->resolveFromSnapshot($version->snapshot, 'storefront');
            }

            $page->load(['sections.items.desktopMedia', 'sections.items.mobileMedia']);
            return $this->resolveFromPageModel($page, 'storefront');
        });

        // Dynamic time evaluation and live stock/price enrichment
        $filteredPage = $this->applyTimeFilters($cachedStructure, now(), 'storefront');
        return $this->enrichLiveProductData($filteredPage);
    }

    public function resolveFromPageModel(ContentPage $page, string $mode): ResolvedPageData
    {
        $resolvedSections = [];
        $visibleIndex = 0;

        foreach ($page->sections as $section) {
            if ($section->is_hidden && $mode !== 'preview') {
                continue;
            }

            if (! $this->typeRegistry->has($section->type)) {
                continue;
            }

            $isEager = ($visibleIndex === 0);
            $resolvedItems = [];

            foreach ($section->items as $item) {
                $resolvedItems[] = $this->resolveItemModel($item);
            }

            $settings = $section->settings ?? [];
            if ($section->type === 'rich_text' && isset($settings['content_markdown'])) {
                $settings['rendered_html'] = $this->markdownSanitizer->toSafeHtml($settings['content_markdown']);
            }

            $resolvedSections[] = new ResolvedSectionData(
                id: $section->id,
                type: $section->type,
                position: $section->position,
                visibility: $section->visibility->value,
                settings: $settings,
                items: $resolvedItems,
                isEager: $isEager,
                startsAt: $section->starts_at?->toIso8601String(),
                endsAt: $section->ends_at?->toIso8601String(),
            );

            $visibleIndex++;
        }

        return new ResolvedPageData(
            id: $page->id,
            slug: $page->slug,
            title: $page->title,
            type: $page->type->value,
            seoTitle: $page->seo_title,
            seoDescription: $page->seo_description,
            sections: $resolvedSections,
        );
    }

    public function resolveFromSnapshot(array $snapshot, string $mode): ResolvedPageData
    {
        $mediaIds = [];
        foreach ($snapshot['sections'] ?? [] as $sec) {
            foreach ($sec['items'] ?? [] as $it) {
                if (! empty($it['desktop_media_id'])) $mediaIds[] = (int) $it['desktop_media_id'];
                if (! empty($it['mobile_media_id'])) $mediaIds[] = (int) $it['mobile_media_id'];
            }
        }

        $mediaMap = ! empty($mediaIds)
            ? ContentMedia::whereIn('id', array_unique($mediaIds))->get()->keyBy('id')
            : collect();

        $resolvedSections = [];
        $visibleIndex = 0;

        foreach ($snapshot['sections'] ?? [] as $secData) {
            if (! empty($secData['is_hidden']) && $mode !== 'preview') {
                continue;
            }

            $isEager = ($visibleIndex === 0);
            $resolvedItems = [];

            foreach ($secData['items'] ?? [] as $itemData) {
                $desktopMedia = ! empty($itemData['desktop_media_id']) && isset($mediaMap[$itemData['desktop_media_id']])
                    ? $this->buildMediaDto($mediaMap[$itemData['desktop_media_id']])
                    : null;

                $mobileMedia = ! empty($itemData['mobile_media_id']) && isset($mediaMap[$itemData['mobile_media_id']])
                    ? $this->buildMediaDto($mediaMap[$itemData['mobile_media_id']])
                    : null;

                $linkDto = $this->linkResolver->resolve(
                    $itemData['link_type'] ?? null,
                    $itemData['link_id'] ?? null,
                    $itemData['link_url'] ?? null
                );

                $resolvedItems[] = new ResolvedItemData(
                    id: (int) $itemData['id'],
                    desktopMedia: $desktopMedia,
                    mobileMedia: $mobileMedia,
                    link: $linkDto,
                    settings: $itemData['settings'] ?? [],
                );
            }

            $settings = $secData['settings'] ?? [];
            if (($secData['type'] ?? '') === 'rich_text' && isset($settings['content_markdown'])) {
                $settings['rendered_html'] = $this->markdownSanitizer->toSafeHtml($settings['content_markdown']);
            }

            $resolvedSections[] = new ResolvedSectionData(
                id: (int) $secData['id'],
                type: $secData['type'],
                position: (int) ($secData['position'] ?? 0),
                visibility: $secData['visibility'] ?? 'all',
                settings: $settings,
                items: $resolvedItems,
                isEager: $isEager,
                startsAt: $secData['starts_at'] ?? null,
                endsAt: $secData['ends_at'] ?? null,
            );

            $visibleIndex++;
        }

        return new ResolvedPageData(
            id: (int) $snapshot['id'],
            slug: $snapshot['slug'],
            title: $snapshot['title'],
            type: $snapshot['type'],
            seoTitle: $snapshot['seo_title'] ?? null,
            seoDescription: $snapshot['seo_description'] ?? null,
            sections: $resolvedSections,
        );
    }

    protected function resolveItemModel(ContentSectionItem $item): ResolvedItemData
    {
        $desktopDto = $item->desktopMedia ? $this->buildMediaDto($item->desktopMedia) : null;
        $mobileDto = $item->mobileMedia ? $this->buildMediaDto($item->mobileMedia) : null;
        $linkDto = $this->linkResolver->resolve(
            $item->link_type?->value,
            $item->link_id,
            $item->link_url
        );

        return new ResolvedItemData(
            id: $item->id,
            desktopMedia: $desktopDto,
            mobileMedia: $mobileDto,
            link: $linkDto,
            settings: $item->settings ?? [],
        );
    }

    public function buildMediaDto(ContentMedia $media): ResolvedMediaData
    {
        return new ResolvedMediaData(
            id: $media->id,
            url: $media->url,
            webpUrl: $media->webp_url,
            width: $media->width,
            height: $media->height,
            alt: $media->alt_text,
            variants: $media->variants ?? [],
        );
    }

    protected function applyTimeFilters(ResolvedPageData $page, Carbon $now, string $mode): ResolvedPageData
    {
        if ($mode === 'preview') {
            return $page;
        }

        $filteredSections = [];

        foreach ($page->sections as $section) {
            if ($section->startsAt && Carbon::parse($section->startsAt)->isFuture()) {
                continue;
            }

            if ($section->endsAt && Carbon::parse($section->endsAt)->isPast()) {
                continue;
            }

            if ($section->type === 'countdown') {
                $endTime = $section->settings['end_time'] ?? null;
                if ($endTime && Carbon::parse($endTime)->isPast()) {
                    continue;
                }
            }

            $filteredSections[] = $section;
        }

        return new ResolvedPageData(
            id: $page->id,
            slug: $page->slug,
            title: $page->title,
            type: $page->type,
            seoTitle: $page->seoTitle,
            seoDescription: $page->seoDescription,
            sections: $filteredSections,
        );
    }

    protected function enrichLiveProductData(ResolvedPageData $page): ResolvedPageData
    {
        $productUpids = [];

        foreach ($page->sections as $section) {
            foreach ($section->items as $item) {
                if (isset($item->link) && $item->link->isValid && ! empty($item->settings['product_upid'])) {
                    $productUpids[] = (string) $item->settings['product_upid'];
                }
            }
        }

        if (empty($productUpids)) {
            return $page;
        }

        $liveProducts = $this->catalogRead->getBulkProductCards(array_unique($productUpids));

        $enrichedSections = [];
        foreach ($page->sections as $section) {
            $enrichedItems = [];
            foreach ($section->items as $item) {
                $upid = $item->settings['product_upid'] ?? null;
                $settings = $item->settings;

                if ($upid && isset($liveProducts[$upid])) {
                    $live = $liveProducts[$upid];
                    $settings['live_price'] = $live['formatted_price'] ?? null;
                    $settings['is_in_stock'] = $live['is_in_stock'] ?? true;
                    $settings['is_active'] = $live['is_active'] ?? true;
                }

                $enrichedItems[] = new ResolvedItemData(
                    id: $item->id,
                    desktopMedia: $item->desktopMedia,
                    mobileMedia: $item->mobileMedia,
                    link: $item->link,
                    settings: $settings,
                );
            }

            $enrichedSections[] = new ResolvedSectionData(
                id: $section->id,
                type: $section->type,
                position: $section->position,
                visibility: $section->visibility,
                settings: $section->settings,
                items: $enrichedItems,
                isEager: $section->isEager,
                startsAt: $section->startsAt,
                endsAt: $section->endsAt,
            );
        }

        return new ResolvedPageData(
            id: $page->id,
            slug: $page->slug,
            title: $page->title,
            type: $page->type,
            seoTitle: $page->seoTitle,
            seoDescription: $page->seoDescription,
            sections: $enrichedSections,
        );
    }
}
```

---

### `MediaUploadService`
Path: `app/Content/Services/MediaUploadService.php`
- Extracts dimensions via `getimagesize()`.
- Automatically generates WebP variant if GD library supports `imagewebp()`.
- Validates disk and storage.
- Guards against deleting referenced images across both active sections and historical snapshot versions.

```php
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

        $fullPath = Storage::disk($disk)->path($storedPath);
        $imageInfo = @getimagesize($fullPath);
        $width = $imageInfo ? (int) $imageInfo[0] : 0;
        $height = $imageInfo ? (int) $imageInfo[1] : 0;
        $mimeType = $file->getMimeType() ?: ($imageInfo ? $imageInfo['mime'] : 'image/'.$extension);

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
            'size_bytes' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'alt_text' => trim($altText),
        ]);
    }

    public function delete(ContentMedia $media): void
    {
        // Check active section items
        $inActiveDraft = ContentSectionItem::where('desktop_media_id', $media->id)
            ->orWhere('mobile_media_id', $media->id)
            ->exists();

        if ($inActiveDraft) {
            throw new DomainException("Cannot delete media: it is currently used by active page sections.");
        }

        // Check published snapshots
        $inSnapshot = ContentPageVersion::whereRaw("json_contains(snapshot, '{\"desktop_media_id\": {$media->id}}', '$.sections[*].items[*]')")
            ->orWhereRaw("json_contains(snapshot, '{\"mobile_media_id\": {$media->id}}', '$.sections[*].items[*]')")
            ->exists();

        if ($inSnapshot) {
            throw new DomainException("Cannot delete media: it is referenced in published version history.");
        }

        Storage::disk($media->disk)->delete($media->path);
        if (isset($media->variants['webp'])) {
            Storage::disk($media->disk)->delete($media->variants['webp']);
        }

        $media->delete();
    }

    protected function convertToWebp(string $sourcePath, string $destinationPath, string $ext): bool
    {
        try {
            $image = match ($ext) {
                'jpg' => @imagecreatefromjpeg($sourcePath),
                'png' => @imagecreatefrompng($sourcePath),
                default => null,
            };

            if (! $image) return false;

            if ($ext === 'png') {
                imagepalettetotruecolor($image);
                imagealphablending($image, true);
                imagesavealpha($image, true);
            }

            $success = imagewebp($image, $destinationPath, 85);
            imagedestroy($image);
            return $success;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function validateFile(UploadedFile $file, string $altText): void
    {
        if (empty(trim($altText))) {
            throw ValidationException::withMessages(['alt_text' => 'Alt text is required for accessibility and SEO.']);
        }

        $validMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];
        if (! in_array($file->getMimeType(), $validMimes, true)) {
            throw ValidationException::withMessages(['file' => 'Only JPG, PNG, WebP, and SVG images are permitted.']);
        }
    }
}
```

---

### `LinkResolverService` & `CatalogReadInterface`

#### `app/Catalog/Contracts/CatalogReadInterface.php`
```php
<?php

namespace App\Catalog\Contracts;

interface CatalogReadInterface
{
    /** @return array{name: string, url: string}|null */
    public function resolveCategory(string $id): ?array;

    /** @return array{name: string, url: string}|null */
    public function resolveBrand(string $id): ?array;

    /** @return array{name: string, url: string}|null */
    public function resolveProduct(string $upid): ?array;

    /** @return array<string, array{formatted_price: string, is_in_stock: bool, is_active: bool}> */
    public function getBulkProductCards(array $upids): array;

    /** @return array<int, array{id: string, name: string, subtext: string}> */
    public function searchEntities(string $type, string $query): array;
}
```

#### `app/Content/Services/LinkResolverService.php`
```php
<?php

namespace App\Content\Services;

use App\Catalog\Contracts\CatalogReadInterface;
use App\Content\DTOs\ResolvedLinkData;
use App\Content\Enums\LinkType;
use App\Content\Models\ContentPage;

class LinkResolverService
{
    public function __construct(protected CatalogReadInterface $catalogRead) {}

    public function resolve(?string $type, ?string $linkId, ?string $linkUrl): ResolvedLinkData
    {
        if (! $type) {
            return new ResolvedLinkData(url: null, isValid: true, isExternal: false);
        }

        $enumType = LinkType::tryFrom($type);
        if (! $enumType) {
            return new ResolvedLinkData(url: null, isValid: false, isExternal: false, error: 'Invalid link type');
        }

        return match ($enumType) {
            LinkType::Category => $this->resolveCategory($linkId),
            LinkType::Brand => $this->resolveBrand($linkId),
            LinkType::Product => $this->resolveProduct($linkId),
            LinkType::Page => $this->resolvePage($linkId),
            LinkType::Search => $this->resolveSearch($linkId),
            LinkType::Url => $this->resolveUrl($linkUrl),
        };
    }

    protected function resolveCategory(?string $id): ResolvedLinkData
    {
        if (! $id) return new ResolvedLinkData(null, false, false, error: 'Missing Category ID');
        $cat = $this->catalogRead->resolveCategory($id);
        return $cat
            ? new ResolvedLinkData($cat['url'], true, false)
            : new ResolvedLinkData(null, false, false, error: 'Category not found');
    }

    protected function resolveBrand(?string $id): ResolvedLinkData
    {
        if (! $id) return new ResolvedLinkData(null, false, false, error: 'Missing Brand ID');
        $brand = $this->catalogRead->resolveBrand($id);
        return $brand
            ? new ResolvedLinkData($brand['url'], true, false)
            : new ResolvedLinkData(null, false, false, error: 'Brand not found');
    }

    protected function resolveProduct(?string $id): ResolvedLinkData
    {
        if (! $id) return new ResolvedLinkData(null, false, false, error: 'Missing Product ID');
        $product = $this->catalogRead->resolveProduct($id);
        return $product
            ? new ResolvedLinkData($product['url'], true, false)
            : new ResolvedLinkData(null, false, false, error: 'Product not found');
    }

    protected function resolvePage(?string $id): ResolvedLinkData
    {
        if (! $id) return new ResolvedLinkData(null, false, false, error: 'Missing Page ID');
        $page = ContentPage::where('slug', $id)->orWhere('id', $id)->first();
        return $page
            ? new ResolvedLinkData(route('content.page', ['slug' => $page->slug]), true, false)
            : new ResolvedLinkData(null, false, false, error: 'Target page not found');
    }

    protected function resolveSearch(?string $query): ResolvedLinkData
    {
        $q = trim((string) $query);
        return new ResolvedLinkData(route('products.index', ['q' => $q]), true, false);
    }

    protected function resolveUrl(?string $url): ResolvedLinkData
    {
        $trimmed = trim((string) $url);
        if (empty($trimmed)) {
            return new ResolvedLinkData(null, false, false, error: 'Empty URL');
        }

        $isExternal = str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://');
        return new ResolvedLinkData(
            url: $trimmed,
            isValid: true,
            isExternal: $isExternal,
            target: $isExternal ? '_blank' : null,
            rel: $isExternal ? 'noopener noreferrer' : null,
        );
    }
}
```

---

### `MarkdownSanitizer`
Path: `app/Content/Services/MarkdownSanitizer.php`
```php
<?php

namespace App\Content\Services;

use Illuminate\Support\Str;

class MarkdownSanitizer
{
    public function toSafeHtml(string $markdown): string
    {
        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        // Strip dangerous tags if inline HTML wasn't completely filtered
        return preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
    }
}
```

---

## 7. Multi-File Livewire Components (Admin)

### `PagesIndex`

#### Component Class: `app/Livewire/Admin/Content/PagesIndex.php`
```php
<?php

namespace App\Livewire\Admin\Content;

use App\Content\Enums\PageStatus;
use App\Content\Enums\PageType;
use App\Content\Models\ContentPage;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
#[Title('Pages Management')]
class PagesIndex extends Component
{
    use WithPagination;

    public string $search = '';
    public string $typeFilter = 'all';
    public string $statusFilter = 'all';

    // Create Modal state
    public bool $showCreateModal = false;
    public string $newTitle = '';
    public string $newSlug = '';
    public string $newType = 'landing';
    public string $newSeoTitle = '';
    public string $newSeoDescription = '';
    public bool $is_slug_customized = false;

    // Delete Modal state
    public ?int $deletingPageId = null;

    public function updatedNewTitle(string $val): void
    {
        if (! $this->is_slug_customized) {
            $this->newSlug = Str::slug($val) ?: ($this->newType === 'home' ? 'home' : '');
        }
    }

    public function updatedNewSlug(string $val): void
    {
        $clean = Str::slug($val);
        if ($clean !== $val) {
            $this->newSlug = $clean;
        }
        $this->is_slug_customized = true;
    }

    public function openCreateModal(): void
    {
        $this->reset(['newTitle', 'newSlug', 'newType', 'newSeoTitle', 'newSeoDescription', 'is_slug_customized']);
        $this->newType = 'landing';
        $this->showCreateModal = true;
    }

    public function createPage(): void
    {
        $this->validate([
            'newTitle' => 'required|string|max:255',
            'newSlug' => 'required|string|max:150|unique:content_pages,slug',
            'newType' => 'required|in:home,landing,static',
            'newSeoTitle' => 'nullable|string|max:255',
            'newSeoDescription' => 'nullable|string|max:500',
        ]);

        $page = ContentPage::create([
            'title' => $this->newTitle,
            'slug' => Str::slug($this->newSlug),
            'type' => PageType::from($this->newType),
            'status' => PageStatus::Draft,
            'seo_title' => $this->newSeoTitle ?: null,
            'seo_description' => $this->newSeoDescription ?: null,
        ]);

        $this->showCreateModal = false;
        $this->redirectRoute('admin.pages.builder', ['page' => $page->id]);
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingPageId = $id;
    }

    public function deletePage(): void
    {
        if (! $this->deletingPageId) return;

        $page = ContentPage::find($this->deletingPageId);
        if ($page) {
            $page->delete();
        }

        $this->deletingPageId = null;
        session()->flash('status', 'Page deleted successfully.');
    }

    #[Computed]
    public function pages()
    {
        return ContentPage::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($sub) => $sub->where('title', 'like', "%{$this->search}%")->orWhere('slug', 'like', "%{$this->search}%")))
            ->when($this->typeFilter !== 'all', fn ($q) => $q->where('type', $this->typeFilter))
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->with(['publishedVersion'])
            ->orderByDesc('updated_at')
            ->paginate(15);
    }

    public function render(): View
    {
        return view('livewire.admin.content.pages-index', [
            'pagesList' => $this->pages,
        ]);
    }
}
```

#### Blade View Template: `resources/views/livewire/admin/content/pages-index.blade.php`
```html
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Content Pages & Landing Pages</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Design, schedule, and publish homepages and marketing landing pages.</p>
        </div>
        <button
            wire:click="openCreateModal"
            class="inline-flex items-center gap-2 rounded-xl bg-zinc-900 px-4 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition"
        >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Create New Page
        </button>
    </div>

    <!-- Filters Bar -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <input
            wire:model.live.debounce.300ms="search"
            type="text"
            placeholder="Search by title or slug..."
            class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-3.5 py-2 text-sm text-zinc-900 dark:text-white"
        />
        <select wire:model.live="typeFilter" class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-3.5 py-2 text-sm text-zinc-900 dark:text-white">
            <option value="all">All Page Types</option>
            <option value="home">Homepage</option>
            <option value="landing">Landing Page</option>
            <option value="static">Static Page</option>
        </select>
        <select wire:model.live="statusFilter" class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-3.5 py-2 text-sm text-zinc-900 dark:text-white">
            <option value="all">All Statuses</option>
            <option value="published">Published</option>
            <option value="scheduled">Scheduled</option>
            <option value="draft">Draft</option>
        </select>
    </div>

    <!-- Table -->
    <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
        <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
            <thead class="bg-zinc-50 dark:bg-zinc-800/50 text-xs font-semibold uppercase text-zinc-700 dark:text-zinc-300">
                <tr>
                    <th class="px-6 py-3.5">Page Title</th>
                    <th class="px-6 py-3.5">Slug</th>
                    <th class="px-6 py-3.5">Type</th>
                    <th class="px-6 py-3.5">Status</th>
                    <th class="px-6 py-3.5">Version</th>
                    <th class="px-6 py-3.5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                @forelse ($pagesList as $p)
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                        <td class="px-6 py-4 font-semibold text-zinc-900 dark:text-white">{{ $p->title }}</td>
                        <td class="px-6 py-4 font-mono text-xs text-zinc-500">/{{ $p->slug }}</td>
                        <td class="px-6 py-4">
                            <span class="rounded-md bg-zinc-100 dark:bg-zinc-800 px-2 py-1 text-xs font-medium text-zinc-800 dark:text-zinc-200">
                                {{ $p->type->label() }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="rounded-md px-2 py-1 text-xs font-medium {{ $p->status->badgeClasses() }}">
                                {{ $p->status->label() }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-xs">
                            {{ $p->publishedVersion ? 'v'.$p->publishedVersion->version_number : '—' }}
                        </td>
                        <td class="px-6 py-4 text-right space-x-2">
                            <a href="{{ route('admin.pages.builder', ['page' => $p->id]) }}" class="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                Page Builder
                            </a>
                            <button wire:click="confirmDelete({{ $p->id }})" class="font-semibold text-rose-600 hover:text-rose-500">
                                Delete
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-zinc-500">No pages found matching criteria.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">
            {{ $pagesList->links() }}
        </div>
    </div>

    <!-- Create Modal -->
    @if ($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4">
            <div class="w-full max-w-md rounded-2xl bg-white dark:bg-zinc-900 p-6 shadow-2xl border border-zinc-200 dark:border-zinc-800 space-y-4">
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Create New Page</h3>
                <div class="space-y-3">
                    <div>
                        <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">Title</label>
                        <input wire:model.live="newTitle" type="text" class="w-full mt-1 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 px-3 py-2 text-sm text-zinc-900 dark:text-white" />
                        @error('newTitle') <p class="text-xs text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">Slug</label>
                        <input wire:model.live="newSlug" type="text" class="w-full mt-1 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 px-3 py-2 text-sm text-zinc-900 dark:text-white" />
                        @error('newSlug') <p class="text-xs text-rose-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">Page Type</label>
                        <select wire:model.live="newType" class="w-full mt-1 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 px-3 py-2 text-sm text-zinc-900 dark:text-white">
                            <option value="landing">Marketing Campaign / Landing Page</option>
                            <option value="home">Homepage</option>
                            <option value="static">Standard Static Page</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button wire:click="$set('showCreateModal', false)" class="rounded-xl px-4 py-2 text-sm text-zinc-600 dark:text-zinc-400">Cancel</button>
                    <button wire:click="createPage" class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-zinc-900">Create & Open Builder</button>
                </div>
            </div>
        </div>
    @endif
</div>
```

---

### `PageBuilder`

#### Component Class: `app/Livewire/Admin/Content/PageBuilder.php`
```php
<?php

namespace App\Livewire\Admin\Content;

use App\Catalog\Contracts\CatalogReadInterface;
use App\Content\Enums\LinkType;
use App\Content\Enums\SectionVisibility;
use App\Content\Models\ContentMedia;
use App\Content\Models\ContentPage;
use App\Content\Models\ContentPageVersion;
use App\Content\Models\ContentSection;
use App\Content\Models\ContentSectionItem;
use App\Content\Sections\SectionTypeRegistry;
use App\Content\Services\MediaUploadService;
use App\Content\Services\PagePublishService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.admin')]
#[Title('Page Builder')]
class PageBuilder extends Component
{
    use WithFileUploads;

    public int $pageId;

    // Active editing section & settings drawer
    public ?int $editingSectionId = null;
    public string $editingSectionType = '';
    public array $editingSettings = [];
    public string $editingVisibility = 'all';
    public ?string $editingStartsAt = null;
    public ?string $editingEndsAt = null;

    // Modals
    public bool $showLibraryModal = false;
    public bool $showMediaModal = false;
    public ?int $mediaTargetItemId = null;
    public string $mediaTargetSlot = 'desktop';
    public $uploadedImage = null;
    public string $mediaAltText = '';
    public string $mediaModalTab = 'browse';
    public ?string $mediaDeleteError = null;

    public bool $showLinkModal = false;
    public ?int $linkTargetItemId = null;
    public string $linkSearchType = 'category';
    public string $linkSearchQuery = '';
    public array $linkSearchResults = [];
    public string $linkCustomUrl = '';

    public bool $showScheduleModal = false;
    public string $scheduleDate = '';

    public bool $showVersionModal = false;

    public function mount(int|ContentPage $page): void
    {
        $this->pageId = $page instanceof ContentPage ? (int) $page->id : (int) $page;
        if (! $this->page) {
            abort(404, 'Page not found.');
        }
    }

    #[Computed]
    public function page(): ?ContentPage
    {
        return ContentPage::with(['sections.items.desktopMedia', 'sections.items.mobileMedia', 'versions.publisher'])
            ->find($this->pageId);
    }

    public function addSection(string $type, SectionTypeRegistry $registry): void
    {
        if (! $registry->has($type)) return;

        $definition = $registry->get($type);
        $nextPos = (ContentSection::where('page_id', $this->pageId)->max('position') ?? -1) + 1;

        $section = ContentSection::create([
            'page_id' => $this->pageId,
            'type' => $type,
            'schema_version' => $definition->schemaVersion(),
            'position' => $nextPos,
            'visibility' => SectionVisibility::All,
            'settings' => $definition->defaultSettings(),
        ]);

        if ($definition->supportsItems()) {
            ContentSectionItem::create([
                'section_id' => $section->id,
                'position' => 0,
                'settings' => $definition->defaultItemSettings(),
            ]);
        }

        $this->showLibraryModal = false;
        $this->editSection($section->id);
    }

    public function editSection(int $sectionId): void
    {
        $section = ContentSection::where('page_id', $this->pageId)->find($sectionId);
        if (! $section) return;

        $this->editingSectionId = $section->id;
        $this->editingSectionType = $section->type;
        $this->editingSettings = $section->settings ?? [];
        $this->editingVisibility = $section->visibility->value;
        $this->editingStartsAt = $section->starts_at?->format('Y-m-d\TH:i');
        $this->editingEndsAt = $section->ends_at?->format('Y-m-d\TH:i');
    }

    public function saveEditingSection(SectionTypeRegistry $registry): void
    {
        if (! $this->editingSectionId) return;

        $section = ContentSection::where('page_id', $this->pageId)->find($this->editingSectionId);
        if (! $section) return;

        $validatedSettings = $registry->validateSettings($section->type, $this->editingSettings);

        $section->update([
            'settings' => $validatedSettings,
            'visibility' => SectionVisibility::from($this->editingVisibility),
            'starts_at' => $this->editingStartsAt ? Carbon::parse($this->editingStartsAt) : null,
            'ends_at' => $this->editingEndsAt ? Carbon::parse($this->editingEndsAt) : null,
        ]);

        $this->editingSectionId = null;
    }

    public function deleteSection(int $sectionId): void
    {
        ContentSection::where('page_id', $this->pageId)->where('id', $sectionId)->delete();
        $this->reorderPositions();
    }

    public function moveSectionUp(int $sectionId): void
    {
        $sections = ContentSection::where('page_id', $this->pageId)->orderBy('position')->get();
        $idx = $sections->search(fn ($s) => $s->id === $sectionId);

        if ($idx !== false && $idx > 0) {
            $prev = $sections[$idx - 1];
            $curr = $sections[$idx];

            $prevPos = $prev->position;
            $prev->update(['position' => $curr->position]);
            $curr->update(['position' => $prevPos]);
        }
    }

    public function moveSectionDown(int $sectionId): void
    {
        $sections = ContentSection::where('page_id', $this->pageId)->orderBy('position')->get();
        $idx = $sections->search(fn ($s) => $s->id === $sectionId);

        if ($idx !== false && $idx < $sections->count() - 1) {
            $next = $sections[$idx + 1];
            $curr = $sections[$idx];

            $nextPos = $next->position;
            $next->update(['position' => $curr->position]);
            $curr->update(['position' => $nextPos]);
        }
    }

    protected function reorderPositions(): void
    {
        $sections = ContentSection::where('page_id', $this->pageId)->orderBy('position')->get();
        foreach ($sections as $i => $s) {
            $s->update(['position' => $i]);
        }
    }

    public function publishNow(PagePublishService $publisher): void
    {
        $publisher->publish($this->page, auth()->id());
        session()->flash('status', 'Page published successfully!');
    }

    public function schedulePublish(PagePublishService $publisher): void
    {
        $this->validate(['scheduleDate' => 'required|date|after:now']);
        $publisher->schedule($this->page, Carbon::parse($this->scheduleDate));
        $this->showScheduleModal = false;
        session()->flash('status', 'Page scheduled successfully.');
    }

    public function rollbackVersion(int $versionId, PagePublishService $publisher): void
    {
        $version = ContentPageVersion::where('page_id', $this->pageId)->find($versionId);
        if ($version) {
            $publisher->rollback($this->page, $version);
            session()->flash('status', "Restored version #{$version->version_number} as draft.");
        }
        $this->showVersionModal = false;
    }

    public function getPreviewUrlProperty(): string
    {
        return URL::temporarySignedRoute(
            'admin.pages.preview',
            now()->addHours(4),
            ['page' => $this->pageId]
        );
    }

    public function render(): View
    {
        return view('livewire.admin.content.page-builder', [
            'page' => $this->page,
            'allMedia' => ContentMedia::latest()->take(30)->get(),
        ]);
    }
}
```

#### Blade View Template: `resources/views/livewire/admin/content/page-builder.blade.php`
```html
<div class="space-y-6">
    <!-- Top Action Bar -->
    <div class="sticky top-0 z-30 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white/90 dark:bg-zinc-900/90 backdrop-blur px-6 py-4 shadow-xs">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.pages.index') }}" class="rounded-xl border border-zinc-200 dark:border-zinc-800 p-2 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                <svg class="w-4 h-4 text-zinc-600 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            </a>
            <div>
                <h1 class="text-xl font-bold text-zinc-900 dark:text-white">{{ $page->title }}</h1>
                <p class="font-mono text-xs text-zinc-500">/{{ $page->slug }} • <span class="{{ $page->status->badgeClasses() }} px-1.5 py-0.5 rounded">{{ $page->status->label() }}</span></p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ $this->previewUrl }}" target="_blank" class="rounded-xl border border-zinc-200 dark:border-zinc-800 px-3.5 py-2 text-xs font-semibold hover:bg-zinc-50 dark:hover:bg-zinc-800 text-zinc-700 dark:text-zinc-200">
                Open Device Preview
            </a>
            <button wire:click="$set('showVersionModal', true)" class="rounded-xl border border-zinc-200 dark:border-zinc-800 px-3.5 py-2 text-xs font-semibold hover:bg-zinc-50 dark:hover:bg-zinc-800 text-zinc-700 dark:text-zinc-200">
                Version History ({{ $page->versions->count() }})
            </button>
            <button wire:click="$set('showScheduleModal', true)" class="rounded-xl border border-zinc-200 dark:border-zinc-800 px-3.5 py-2 text-xs font-semibold hover:bg-zinc-50 dark:hover:bg-zinc-800 text-zinc-700 dark:text-zinc-200">
                Schedule
            </button>
            <button wire:click="publishNow" class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white shadow-xs hover:bg-emerald-500 transition">
                Publish Page
            </button>
        </div>
    </div>

    <!-- Main Workspace -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Section Tree (Left 2 cols) -->
        <div class="lg:col-span-2 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-bold uppercase tracking-wider text-zinc-500">Page Sections Layout</h2>
                <button wire:click="$set('showLibraryModal', true)" class="text-xs font-bold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                    + Add New Section
                </button>
            </div>

            @forelse ($page->sections as $section)
                <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-xs flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <span class="rounded bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 font-mono text-[11px] font-semibold text-zinc-600 dark:text-zinc-300">
                            #{{ $section->position + 1 }} {{ strtoupper($section->type) }}
                        </span>
                        <div class="text-xs text-zinc-500">
                            {{ count($section->items) }} child items • Visibility: {{ $section->visibility->value }}
                        </div>
                    </div>
                    <div class="flex items-center gap-1">
                        <button wire:click="moveSectionUp({{ $section->id }})" class="p-1 text-zinc-400 hover:text-zinc-600">▲</button>
                        <button wire:click="moveSectionDown({{ $section->id }})" class="p-1 text-zinc-400 hover:text-zinc-600">▼</button>
                        <button wire:click="editSection({{ $section->id }})" class="ml-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 px-3 py-1.5 text-xs font-semibold text-zinc-700 dark:text-zinc-200">
                            Edit Settings
                        </button>
                        <button wire:click="deleteSection({{ $section->id }})" class="p-1.5 text-rose-500 hover:text-rose-600">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </div>
                </div>
            @empty
                <div class="rounded-2xl border-2 border-dashed border-zinc-200 dark:border-zinc-800 p-12 text-center space-y-3">
                    <p class="text-sm text-zinc-500">This page does not have any sections yet.</p>
                    <button wire:click="$set('showLibraryModal', true)" class="rounded-xl bg-zinc-900 px-4 py-2 text-xs font-bold text-white dark:bg-white dark:text-zinc-900">
                        Choose From Section Library
                    </button>
                </div>
            @endforelse
        </div>

        <!-- Settings Drawer (Right col) -->
        <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-xs h-fit space-y-5">
            @if ($editingSectionId)
                <h3 class="text-sm font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300">
                    Configure {{ $editingSectionType }}
                </h3>
                <div class="space-y-4">
                    <div>
                        <label class="text-xs font-semibold text-zinc-600 dark:text-zinc-400">Visibility</label>
                        <select wire:model.live="editingVisibility" class="w-full mt-1 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 px-3 py-2 text-xs text-zinc-900 dark:text-white">
                            <option value="all">All Devices</option>
                            <option value="desktop">Desktop Only</option>
                            <option value="mobile">Mobile Only</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-zinc-600 dark:text-zinc-400">Schedule Active Window</label>
                        <div class="grid grid-cols-2 gap-2 mt-1">
                            <input wire:model.live="editingStartsAt" type="datetime-local" class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 px-2 py-1.5 text-xs text-zinc-900 dark:text-white" />
                            <input wire:model.live="editingEndsAt" type="datetime-local" class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 px-2 py-1.5 text-xs text-zinc-900 dark:text-white" />
                        </div>
                    </div>
                    <!-- Dynamic form based on type -->
                    @if ($editingSectionType === 'hero_carousel')
                        <label class="flex items-center gap-2 text-xs text-zinc-700 dark:text-zinc-300">
                            <input type="checkbox" wire:model.live="editingSettings.autoplay" class="rounded" /> Autoplay Slides
                        </label>
                        <div>
                            <label class="text-xs text-zinc-600">Interval (ms)</label>
                            <input type="number" wire:model.live="editingSettings.interval_ms" step="500" class="w-full mt-1 rounded-xl border border-zinc-200 dark:border-zinc-800 px-3 py-1.5 text-xs" />
                        </div>
                    @elseif ($editingSectionType === 'countdown')
                        <div>
                            <label class="text-xs text-zinc-600">Countdown Expiry (UTC)</label>
                            <input type="datetime-local" wire:model.live="editingSettings.end_time" class="w-full mt-1 rounded-xl border border-zinc-200 dark:border-zinc-800 px-3 py-1.5 text-xs" />
                        </div>
                        <div>
                            <label class="text-xs text-zinc-600">Badge Text</label>
                            <input type="text" wire:model.live="editingSettings.badge" class="w-full mt-1 rounded-xl border border-zinc-200 dark:border-zinc-800 px-3 py-1.5 text-xs" />
                        </div>
                        <div>
                            <label class="text-xs text-zinc-600">Headline</label>
                            <input type="text" wire:model.live="editingSettings.headline" class="w-full mt-1 rounded-xl border border-zinc-200 dark:border-zinc-800 px-3 py-1.5 text-xs" />
                        </div>
                    @elseif ($editingSectionType === 'rich_text')
                        <div>
                            <label class="text-xs text-zinc-600">Markdown Content</label>
                            <textarea wire:model.live="editingSettings.content_markdown" rows="8" class="w-full mt-1 font-mono rounded-xl border border-zinc-200 dark:border-zinc-800 px-3 py-1.5 text-xs"></textarea>
                        </div>
                    @endif
                    <div class="flex justify-end gap-2 pt-4">
                        <button wire:click="$set('editingSectionId', null)" class="text-xs text-zinc-500">Cancel</button>
                        <button wire:click="saveEditingSection" class="rounded-xl bg-zinc-900 px-4 py-2 text-xs font-bold text-white dark:bg-white dark:text-zinc-900">Save Changes</button>
                    </div>
                </div>
            @else
                <div class="py-12 text-center text-xs text-zinc-400">
                    Select a section to configure its settings and child items.
                </div>
            @endif
        </div>
    </div>
</div>
```

---

## 8. Multi-File Livewire Components (Storefront) & Preview Shell

### `HomePage`

#### Component Class: `app/Livewire/Storefront/HomePage.php`
```php
<?php

namespace App\Livewire\Storefront;

use App\Content\Enums\PageStatus;
use App\Content\Enums\PageType;
use App\Content\Models\ContentPage;
use App\Content\Services\PageResolver;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.storefront')]
#[Title('Home')]
class HomePage extends Component
{
    #[Computed]
    public function page(): ?ContentPage
    {
        return ContentPage::query()
            ->where('type', PageType::Home)
            ->where('status', PageStatus::Published)
            ->first();
    }

    #[Computed]
    public function resolvedPage()
    {
        $page = $this->page;
        if (! $page) {
            return null;
        }

        return app(PageResolver::class)->resolve($page, 'storefront');
    }

    public function render(): View
    {
        return view('livewire.storefront.home-page', [
            'resolvedPage' => $this->resolvedPage,
        ]);
    }
}
```

#### Blade View Template: `resources/views/livewire/storefront/home-page.blade.php`
```html
<div>
    @if ($resolvedPage && count($resolvedPage->sections) > 0)
        <div class="space-y-10 sm:space-y-14 py-6 sm:py-8">
            @foreach ($resolvedPage->sections as $section)
                @php
                    $visClass = match ($section->visibility) {
                        'desktop' => 'hidden md:block',
                        'mobile' => 'block md:hidden',
                        default => 'block',
                    };
                @endphp
                <section class="{{ $visClass }}" data-section-type="{{ $section->type }}">
                    <x-dynamic-component
                        :component="'content.sections.' . str_replace('_', '-', $section->type)"
                        :section="$section"
                    />
                </section>
            @endforeach
        </div>
    @else
        <!-- Fallback Default Homepage when no page is published -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 text-center space-y-6">
            <h1 class="text-4xl font-extrabold text-zinc-900 dark:text-white">Welcome to Our Store</h1>
            <p class="text-sm text-zinc-500 max-w-lg mx-auto">Explore our curated collection of verified products and deals.</p>
        </div>
    @endif
</div>
```

---

### `StaticPage`

#### Component Class: `app/Livewire/Storefront/StaticPage.php`
```php
<?php

namespace App\Livewire\Storefront;

use App\Content\Enums\PageStatus;
use App\Content\Models\ContentPage;
use App\Content\Services\PageResolver;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class StaticPage extends Component
{
    public string $slug = '';

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        $page = $this->page;
        if (! $page || ! $page->isPublished()) {
            abort(404, 'Page not found.');
        }
    }

    #[Computed]
    public function page(): ?ContentPage
    {
        return ContentPage::query()
            ->where('slug', $this->slug)
            ->where('status', PageStatus::Published)
            ->first();
    }

    #[Computed]
    public function resolvedPage()
    {
        $page = $this->page;
        if (! $page) return null;

        return app(PageResolver::class)->resolve($page, 'storefront');
    }

    public function render(): View
    {
        return view('livewire.storefront.static-page', [
            'resolvedPage' => $this->resolvedPage,
        ]);
    }
}
```

#### Blade View Template: `resources/views/livewire/storefront/static-page.blade.php`
```html
<div>
    @if ($resolvedPage)
        @if ($resolvedPage->seoTitle)
            @section('title', $resolvedPage->seoTitle)
        @else
            @section('title', $resolvedPage->title)
        @endif

        <div class="space-y-10 sm:space-y-14 py-6 sm:py-8">
            @foreach ($resolvedPage->sections as $section)
                @php
                    $visClass = match ($section->visibility) {
                        'desktop' => 'hidden md:block',
                        'mobile' => 'block md:hidden',
                        default => 'block',
                    };
                @endphp
                <section class="{{ $visClass }}" data-section-type="{{ $section->type }}">
                    <x-dynamic-component
                        :component="'content.sections.' . str_replace('_', '-', $section->type)"
                        :section="$section"
                    />
                </section>
            @endforeach
        </div>
    @endif
</div>
```

---

### Device-Adaptive Live Preview Shell
Route definition:
```php
Route::get('pages/{page}/preview', function (ContentPage $page, Request $request, PageResolver $resolver) {
    if (! $request->hasValidSignature()) {
        abort(403, 'Invalid or expired preview signature.');
    }

    $resolvedPage = $resolver->resolve($page, 'preview');

    return response()->view('pages.admin.content.preview', [
        'page' => $page,
        'resolvedPage' => $resolvedPage,
    ])->header('X-Robots-Tag', 'noindex, nofollow');
})->name('admin.pages.preview');
```

The view template `resources/views/pages/admin/content/preview.blade.php` embeds an interactive device switcher (`Desktop: 100%`, `Tablet: 768px`, `Mobile: 375px`) wrapping an `<iframe>` targeting the self URL.

---

## 9. Storefront Blade Section Components

All section components reside in `resources/views/components/content/sections/` and use `<picture>` WebP tags and Alpine.js.

### Example: `hero-carousel.blade.php`
```html
@props(['section'])

@php
    $settings = $section->settings;
    $items = $section->items;
    $itemCount = count($items);
    $autoplay = $settings['autoplay'] ?? true;
    $interval = $settings['interval_ms'] ?? 5000;
@endphp

@if ($itemCount > 0)
    <div
        x-data="{
            current: 0,
            total: {{ $itemCount }},
            timer: null,
            init() {
                if ({{ $autoplay ? 'true' : 'false' }} && this.total > 1) {
                    this.timer = setInterval(() => this.current = (this.current + 1) % this.total, {{ $interval }});
                }
            }
        }"
        class="relative w-full max-w-7xl mx-auto overflow-hidden rounded-3xl bg-zinc-900 shadow-md"
    >
        <div class="relative w-full aspect-[21/9] min-h-[220px]">
            @foreach ($items as $idx => $item)
                <div
                    x-show="current === {{ $idx }}"
                    x-transition:enter="transition ease-out duration-500"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    class="absolute inset-0 w-full h-full"
                >
                    @if ($item->desktopMedia)
                        <picture>
                            @if ($item->desktopMedia->webpUrl)
                                <source srcset="{{ $item->desktopMedia->webpUrl }}" type="image/webp">
                            @endif
                            <img
                                src="{{ $item->desktopMedia->url }}"
                                alt="{{ $item->desktopMedia->alt }}"
                                class="w-full h-full object-cover"
                                loading="{{ $section->isEager && $idx === 0 ? 'eager' : 'lazy' }}"
                            />
                        </picture>
                    @endif
                    <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent flex items-end p-8 sm:p-12">
                        <div class="space-y-2 max-w-xl text-white">
                            @if (!empty($item->settings['headline']))
                                <h2 class="text-2xl sm:text-4xl font-extrabold tracking-tight">{{ $item->settings['headline'] }}</h2>
                            @endif
                            @if (!empty($item->settings['cta_text']) && $item->link?->url)
                                <a href="{{ $item->link->url }}" class="inline-block mt-3 rounded-xl bg-white px-5 py-2.5 text-xs font-bold text-zinc-900 shadow-sm hover:bg-zinc-100">
                                    {{ $item->settings['cta_text'] }}
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
```

---

## 10. Console Automation & Scheduled Publishing

### `app/Content/Commands/PublishScheduledPagesCommand.php`
```php
<?php

namespace App\Content\Commands;

use App\Content\Enums\PageStatus;
use App\Content\Models\ContentPage;
use App\Content\Services\PagePublishService;
use Illuminate\Console\Command;

class PublishScheduledPagesCommand extends Command
{
    protected $signature = 'content:publish-scheduled';
    protected $description = 'Publish scheduled content pages whose target timestamp has arrived';

    public function handle(PagePublishService $publisher): int
    {
        $pages = ContentPage::where('status', PageStatus::Scheduled)
            ->where('scheduled_publish_at', '<=', now())
            ->get();

        if ($pages->isEmpty()) {
            $this->info('No scheduled pages ready to publish.');
            return self::SUCCESS;
        }

        foreach ($pages as $page) {
            $this->info("Publishing page [{$page->id}] '{$page->title}' (slug: {$page->slug})...");
            $publisher->publish($page, null);
        }

        return self::SUCCESS;
    }
}
```

Register this command in `routes/console.php`:
```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('content:publish-scheduled')->everyMinute();
```

---

## 11. Step-by-Step AI Agent Implementation Guide

When an AI agent is instructed to implement this module in an existing Laravel Livewire project, execute the following steps in sequence:

### Phase 1: Database & Foundation
1. Run `make:migration create_content_module_tables` and copy the schema from [Section 2](#2-database-schema--migrations).
2. Create Enums: `PageType`, `PageStatus`, `SectionVisibility`, `LinkType` in `app/Content/Enums/`.
3. Create Models: `ContentMedia`, `ContentPage`, `ContentPageVersion`, `ContentSection`, `ContentSectionItem` in `app/Content/Models/`.
4. Create DTOs: `ResolvedPageData`, `ResolvedSectionData`, `ResolvedItemData`, `ResolvedMediaData`, `ResolvedLinkData` in `app/Content/DTOs/`.
5. Run `php artisan migrate`.

### Phase 2: Contracts, Services & Section Registry
1. Create `SectionTypeDefinitionInterface` in `app/Content/Contracts/`.
2. Implement 9 section classes in `app/Content/Sections/` (`HeroCarouselSection`, `SingleBannerSection`, etc.).
3. Implement `SectionTypeRegistry` in `app/Content/Sections/`.
4. Implement `MarkdownSanitizer`, `MediaUploadService`, `LinkResolverService`, `PagePublishService`, and `PageResolver` in `app/Content/Services/`.
5. Register singletons in `app/Providers/AppServiceProvider.php` (or a dedicated `ContentServiceProvider`).

### Phase 3: Multi-File Admin Livewire Components
1. Create Component Class: `app/Livewire/Admin/Content/PagesIndex.php`.
2. Create Blade View: `resources/views/livewire/admin/content/pages-index.blade.php`.
3. Create Component Class: `app/Livewire/Admin/Content/PageBuilder.php`.
4. Create Blade View: `resources/views/livewire/admin/content/page-builder.blade.php`.
5. Register admin routes in `routes/admin.php` or `routes/web.php`.

### Phase 4: Storefront Components & Blade Sections
1. Create Component Class: `app/Livewire/Storefront/HomePage.php`.
2. Create Blade View: `resources/views/livewire/storefront/home-page.blade.php`.
3. Create Component Class: `app/Livewire/Storefront/StaticPage.php`.
4. Create Blade View: `resources/views/livewire/storefront/static-page.blade.php`.
5. Implement section blade components in `resources/views/components/content/sections/*.blade.php`.
6. Add routes for `/` and `/pages/{slug}`.

### Phase 5: Verification & Edge Cases
1. Create a page with slug `test-landing`.
2. Add a `HeroCarouselSection` and `CountdownSection`.
3. Verify signed preview at `/admin/pages/{id}/preview`.
4. Publish page; verify snapshot record in `content_page_versions`.
5. Test rollback to verify snapshot restoration.
6. Verify single home page invariant by publishing two pages with type `home`.
