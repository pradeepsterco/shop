<?php

namespace App\Http\Controllers;

use App\Content\Enums\PageStatus;
use App\Content\Enums\PageType;
use App\Content\Models\ContentPage;
use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Category;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Vertical;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    public const DEFAULT_CHUNK_SIZE = 10000;
    public const CACHE_TTL_SECONDS = 21600; // 6 hours

    public function getChunkSize(): int
    {
        return max(1, (int) config('seo.sitemap_chunk_size', self::DEFAULT_CHUNK_SIZE));
    }

    public function index(): Response
    {
        $xml = Cache::remember('sitemap.index', self::CACHE_TTL_SECONDS, function () {
            $chunkSize = $this->getChunkSize();
            $productVariantQuery = $this->eligibleProductVariantsQuery();
            $totalProducts = (clone $productVariantQuery)->count();
            $totalChunks = max(1, (int) ceil($totalProducts / $chunkSize));

            // Determine lastmod for each child
            $pagesLastmod = ContentPage::where('status', PageStatus::Published)->max('updated_at') ?? now()->toIso8601String();
            $categoriesLastmod = max(
                Category::where('is_active', true)->max('updated_at') ?? now()->toIso8601String(),
                Vertical::where('is_active', true)->max('updated_at') ?? now()->toIso8601String()
            );
            $sellersLastmod = SellerProfile::where('status', SellerStatus::Approved->value)->max('updated_at') ?? now()->toIso8601String();
            $productsLastmod = (clone $productVariantQuery)->max('product_variants.updated_at') ?? now()->toIso8601String();

            $sitemaps = [
                [
                    'loc' => route('sitemap.pages'),
                    'lastmod' => Carbon::parse($pagesLastmod)->toIso8601String(),
                ],
                [
                    'loc' => route('sitemap.categories'),
                    'lastmod' => Carbon::parse($categoriesLastmod)->toIso8601String(),
                ],
                [
                    'loc' => route('sitemap.sellers'),
                    'lastmod' => Carbon::parse($sellersLastmod)->toIso8601String(),
                ],
            ];

            for ($chunk = 1; $chunk <= $totalChunks; $chunk++) {
                $sitemaps[] = [
                    'loc' => route('sitemap.products', ['chunk' => $chunk]),
                    'lastmod' => Carbon::parse($productsLastmod)->toIso8601String(),
                ];
            }

            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

            foreach ($sitemaps as $item) {
                $xml .= "    <sitemap>\n";
                $xml .= '        <loc>' . htmlspecialchars($item['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
                $xml .= '        <lastmod>' . htmlspecialchars($item['lastmod'], ENT_XML1, 'UTF-8') . "</lastmod>\n";
                $xml .= "    </sitemap>\n";
            }

            $xml .= '</sitemapindex>';

            return $xml;
        });

        return response($xml, 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }

    public function pages(): Response
    {
        $xml = Cache::remember('sitemap.pages', self::CACHE_TTL_SECONDS, function () {
            $urls = [];

            // 1. Home page
            $homePage = ContentPage::where('type', PageType::Home)
                ->where('status', PageStatus::Published)
                ->first();
            $homeLastmod = $homePage?->updated_at ?? now();

            $urls[] = [
                'loc' => route('home'),
                'lastmod' => Carbon::parse($homeLastmod)->toIso8601String(),
            ];

            // 2. Grievance officer
            $urls[] = [
                'loc' => route('grievance-officer'),
                'lastmod' => now()->startOfDay()->toIso8601String(),
            ];

            // 3. Published CMS static and landing pages
            $pages = ContentPage::query()
                ->where('status', PageStatus::Published)
                ->whereIn('type', [PageType::Landing, PageType::Static])
                ->get();

            foreach ($pages as $page) {
                $urls[] = [
                    'loc' => route('content.page', ['slug' => $page->slug]),
                    'lastmod' => $page->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                ];
            }

            return $this->buildUrlsetXml($urls);
        });

        return response($xml, 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }

    public function categories(): Response
    {
        $xml = Cache::remember('sitemap.categories', self::CACHE_TTL_SECONDS, function () {
            $urls = [];

            // Active categories
            $categories = Category::where('is_active', true)->get();
            foreach ($categories as $cat) {
                $urls[] = [
                    'loc' => route('products.index', ['category' => $cat->slug]),
                    'lastmod' => $cat->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                ];
            }

            // Active verticals
            $verticals = Vertical::where('is_active', true)->get();
            foreach ($verticals as $vert) {
                $urls[] = [
                    'loc' => route('products.index', ['vertical' => $vert->slug]),
                    'lastmod' => $vert->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                ];
            }

            return $this->buildUrlsetXml($urls);
        });

        return response($xml, 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }

    public function sellers(): Response
    {
        $xml = Cache::remember('sitemap.sellers', self::CACHE_TTL_SECONDS, function () {
            $urls = [];

            $sellers = SellerProfile::where('status', SellerStatus::Approved->value)
                ->whereNotNull('store_slug')
                ->get();

            foreach ($sellers as $seller) {
                $urls[] = [
                    'loc' => route('sellers.show', ['slug' => $seller->store_slug]),
                    'lastmod' => $seller->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                ];
            }

            return $this->buildUrlsetXml($urls);
        });

        return response($xml, 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }

    public function products(int $chunk = 1): Response
    {
        if ($chunk < 1) {
            abort(404, 'Invalid sitemap chunk.');
        }

        $chunkSize = $this->getChunkSize();
        $totalProducts = $this->eligibleProductVariantsQuery()->count();
        $totalChunks = max(1, (int) ceil($totalProducts / $chunkSize));

        if ($chunk > $totalChunks) {
            abort(404, 'Sitemap chunk not found.');
        }

        $cacheKey = "sitemap.products.{$chunk}";

        $xml = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($chunk, $chunkSize) {
            $query = $this->eligibleProductVariantsQuery();

            $variants = $query->skip(($chunk - 1) * $chunkSize)
                ->take($chunkSize)
                ->get();

            $urls = [];

            foreach ($variants as $variant) {
                $product = $variant->product;
                if (! $product) {
                    continue;
                }

                $loc = route('product.show', [
                    'slug' => $product->slug,
                    'upid' => $variant->upid,
                ]);

                // Find primary live image
                $primaryImg = $variant->images->first(fn ($img) => $img->status === ProductImageStatus::Live)
                    ?? $product->images->first(fn ($img) => $img->is_primary && $img->status === ProductImageStatus::Live)
                    ?? $product->images->first(fn ($img) => $img->status === ProductImageStatus::Live);

                $imageUrl = $primaryImg ? $primaryImg->url() : null;

                $urlEntry = [
                    'loc' => $loc,
                    'lastmod' => $variant->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                ];

                if ($imageUrl) {
                    $urlEntry['image'] = [
                        'loc' => $imageUrl,
                        'title' => $product->title,
                    ];
                }

                $urls[] = $urlEntry;
            }

            return $this->buildUrlsetXml($urls, true);
        });

        return response($xml, 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }

    /**
     * Query for storefront-eligible product variants.
     */
    protected function eligibleProductVariantsQuery()
    {
        return ProductVariant::query()
            ->where('product_variants.qc_status', VariantQcStatus::Approved)
            ->whereHas('product', function ($q) {
                $q->where('qc_status', ProductQcStatus::Approved)
                    ->whereNotNull('buy_box_listing_id')
                    ->whereHas('buyBoxListing', function ($b) {
                        $b->where('status', ListingStatus::Active)
                            ->whereHas('sellerProfile', fn ($s) => $s->where('status', SellerStatus::Approved));
                    });
            })
            ->with([
                'product' => fn ($q) => $q->with(['images' => fn ($img) => $img->where('status', ProductImageStatus::Live)]),
                'images' => fn ($img) => $img->where('status', ProductImageStatus::Live),
            ])
            ->orderBy('product_variants.id');
    }

    /**
     * Build standard urlset XML.
     *
     * @param array<int, array<string, mixed>> $urls
     */
    protected function buildUrlsetXml(array $urls, bool $includeImageNamespace = false): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        if ($includeImageNamespace) {
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        } else {
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        }

        foreach ($urls as $item) {
            $xml .= "    <url>\n";
            $xml .= '        <loc>' . htmlspecialchars($item['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
            $xml .= '        <lastmod>' . htmlspecialchars($item['lastmod'], ENT_XML1, 'UTF-8') . "</lastmod>\n";

            if (! empty($item['image'])) {
                $xml .= "        <image:image>\n";
                $xml .= '            <image:loc>' . htmlspecialchars($item['image']['loc'], ENT_XML1, 'UTF-8') . "</image:loc>\n";
                if (! empty($item['image']['title'])) {
                    $xml .= '            <image:title>' . htmlspecialchars($item['image']['title'], ENT_XML1, 'UTF-8') . "</image:title>\n";
                }
                $xml .= "        </image:image>\n";
            }

            $xml .= "    </url>\n";
        }

        $xml .= '</urlset>';

        return $xml;
    }

    /**
     * Cache clearing helpers (database cache driver friendly).
     */
    public static function clearCache(?string $section = null): void
    {
        Cache::forget('sitemap.index');

        if ($section === 'pages' || $section === null) {
            Cache::forget('sitemap.pages');
        }

        if ($section === 'categories' || $section === null) {
            Cache::forget('sitemap.categories');
        }

        if ($section === 'sellers' || $section === null) {
            Cache::forget('sitemap.sellers');
        }

        if ($section === 'products' || $section === null) {
            for ($chunk = 1; $chunk <= 500; $chunk++) {
                Cache::forget("sitemap.products.{$chunk}");
            }
        }
    }

    public static function clearPagesCache(): void
    {
        self::clearCache('pages');
    }

    public static function clearProductsCache(): void
    {
        self::clearCache('products');
    }

    public static function clearSellersCache(): void
    {
        self::clearCache('sellers');
    }

    public static function clearCategoriesCache(): void
    {
        self::clearCache('categories');
    }
}

