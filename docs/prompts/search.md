# Search — spec

Source of truth for the storefront search module (branch `search`). Flipkart-style: search box with
suggestions, query understanding, relevance-ranked results with filter counts, and search analytics.

## Current state (what we build on)

- `pages::storefront.product-index` (`/products`, `products.index`) already filters by category,
  vertical, brands, price, discount, in stock and variant attributes (`attrs`), all as URL params, with
  sorts `featured`, `price_asc`, `price_desc`, `newest`. Search adds `q` to this page; there is no
  separate results page.
- Products carry buy-box fields (`buy_box_listing_id`, `min_price`, `buy_box_mrp`, `discount_percent`,
  `in_stock`), written by `ProductBuyBoxService::refresh()` with `saveQuietly()` (no model events).
- `CatalogReadService::searchProducts()` is a `LIKE '%q%'` on title/slug/UPID, used by admin pickers.
  It stays as is; storefront search does not use it.
- `review_summaries` (`rating_avg`, `rating_count`) from the Reviews module, via
  `ReviewSummaryService::recalculate()` / `getSummariesForProducts()`.
- Header search icon in `layouts/app/header.blade.php` links to `#`; storefront layout is
  `layouts/storefront.blade.php`.

## Base spec

- Search box in the storefront header on every storefront page (desktop: inline; mobile: full-screen
  overlay). Enter or a suggestion click → `/products?q=…` (or a redirect rule's target).
- Suggestions while typing: recent searches, popular searches matching the prefix, category/vertical
  matches ("shoes in Men's Footwear"), up to 4 products (image, title, price). Keyboard navigation.
- Results: `/products?q=…` with every existing filter still working and combinable with `q`. Default
  sort is `relevance` when `q` is set (`featured` otherwise). Filter counts for brands, price buckets,
  discount buckets, rating and variant attributes. "Showing 1–40 of N results for "q"".
- Typo tolerance ("iphnoe" → iphone) with "Showing results for X · Search instead for Y".
- Zero results: message, spelling suggestion if any, popular searches, popular products.
- Only searchable: products with `qc_status = approved` and a buy box. Out-of-stock products still show,
  ranked below in-stock ones.
- Prices and stock on the results page are always live: the engine returns product IDs (ordered) and
  filter counts only; the page loads products from the database in that order.

## Decisions

1. Engine: our own `SearchEngineInterface` (no Laravel Scout: its `Searchable` trait would sit on
   `Product` and sync through model events, which decision 6 avoids, and its database driver only
   searches columns of the products table). Engines: `database` (S1, `search_documents` table, LIKE
   word-prefix matching), `meilisearch` (S2, `meilisearch/meilisearch-php` client) self-hosted on our own
   machine for now (Windows binary, `MEILISEARCH_HOST=http://127.0.0.1:7700`, master key in `.env`, data
   directory outside the repo), `fake` for tests. Engine choice is config (`search.engine`), never
   referenced outside `app/Search`. Moving to another server or Meilisearch Cloud later is a `.env` change.
2. One index document per product (not per listing or variant). Variant values are merged into the
   product document.
3. Searchable fields, in ranking priority: title; brand name; vertical name and category path names;
   variant attribute values (colour, size, …); highlights; spec values; UPIDs and barcodes (exact match).
4. Filterable / sortable fields: `vertical_id`, `category_ids` (vertical's category and ancestors),
   `brand_slug`, `min_price` (paise), `discount_percent`, `in_stock`, `rating_avg`, `rating_count`,
   `attrs.{code}` (variant attribute values from approved variants with an active listing),
   `sales_30d`, `created_at`.
5. Ranking: text relevance (words, typo, proximity, attribute priority, exactness) first, then
   `in_stock` desc, `sales_30d` desc, rating score (`rating_avg` weighted by `log(rating_count + 1)`),
   `discount_percent` desc. Pinned results (admin) override per query.
6. Reindexing is explicit, never through model observers on buy-box fields (they are saved quietly):
   `ProductBuyBoxService::refresh()`, product QC approve/reject/edit, variant and listing changes,
   `ReviewSummaryService::recalculate()` and brand/vertical/category renames dispatch
   `ReindexProduct` (queued, `ShouldBeUnique` per product, short delay to absorb bursts). A product that
   is no longer searchable is removed from the index. Category/brand renames reindex their products in
   chunks.
7. `php artisan search:reindex {--fresh}` rebuilds the whole index in chunks (inline, with a progress
   bar) and, from S2, applies index settings (searchable/filterable/sortable fields, ranking rules,
   synonyms, stop words).
8. Search never fails the page: engine down/timeout → fall back to a database `LIKE` query on title +
   brand name (no filter counts, no typo tolerance) and log a warning.
9. Cache only arrays (`serializable_classes` allows only listed classes): suggestion results per
   normalised prefix, 10 minutes.
10. `sales_30d` = units on order items placed in the last 30 days (paid, or COD confirmed), minus units
    cancelled, minus units refunded through returns. Placed orders give new products credit quickly;
    subtracting cancellations and refunds stops bad products ranking on volume alone. Read through an
    Orders contract (Search never queries order tables), recomputed nightly by a scheduled command that
    reindexes only products whose value changed.
11. Guest searches are logged on the server like logged-in ones, keyed by a random `search_visitor_id`
    stored in the session (session data survives login's session-ID regeneration). On login, that
    visitor's rows without a user are attached to the user, so recent searches carry over.

## Query understanding (S3)

- Normalise: lowercase, trim, collapse spaces, strip punctuation except `-` and `.` inside words.
- Synonyms (admin-managed, pushed to the engine): two-way (mobile ↔ phone) and one-way (tv → television).
- Redirect rules (admin): exact normalised query → URL (vertical/category page, content page, product).
- Price phrases: "under 500", "below ₹1,000", "500 to 1000", "above 2000" → `min_price` / `max_price`,
  phrase removed from the text query. Brand name exactly matching an active brand → `brands` filter
  (only when the rest of the query still has words, so "samsung" alone stays a text search).
- Parsed filters are applied as normal URL params so the customer sees and can clear them.

## Analytics (S3)

- `search_queries`: query, normalised query, user_id (nullable), search_visitor_id, results count,
  filters used, corrected query, created_at. One row per results page load with a new `q` (not per
  pagination/filter change).
- `search_clicks`: search_query_id, product_id, position. Recorded when a result is clicked
  (`?sq={id}` on the product link, read once on product show).
- Popular searches = top normalised queries with results > 0 over 30 days (min 3 distinct visitors),
  rebuilt hourly into a cached array.
- Recent searches: last 8 distinct from `search_queries`, by user_id when logged in, else by
  search_visitor_id (decision 11). Customer can clear them (rows kept for analytics, hidden from recents).
- Prune `search_queries` / `search_clicks` older than 180 days (scheduled).

## Admin (S4)

- Permission `search.manage` (AdminPermissions, sync, RBAC datasets); activity log for every change.
- Screens: synonyms, redirect rules, pinned results (query → ordered product IDs, max 8), reindex
  button (queues `search:reindex`, shows last run), reports: top searches, zero-result searches,
  low-CTR searches (30 days).

## Module layout

- `app/Search/`: `Contracts/SearchEngineInterface`, `Contracts/ProductSearchInterface`,
  `Engines/MeilisearchEngine`, `Engines/DatabaseEngine`, `Engines/FakeEngine`,
  `Services/ProductSearchService`, `Services/SuggestService`, `Services/QueryParser`,
  `Services/ProductDocumentBuilder`, `Jobs/ReindexProduct`, `DTOs/SearchQuery`, `DTOs/SearchResult`
  (ordered IDs, total, facet counts, corrected query), `Models/` (queries, clicks, synonyms, redirects,
  pins), `SearchServiceProvider`, `config/search.php`.
- Other modules call only the contracts; `tests/Feature/Architecture/SearchDecouplingTest.php` enforces
  it like the other decoupling tests.

## Chunks

- S1: `app/Search` skeleton, document builder, `search:reindex`, database + fake engines; `q` on
  product-index (the `featured` sort means relevance while searching, labelled "Relevance"; in-stock
  first); header search box with simple suggestions (scopes, categories, products); DB fallback.
  Queued `ReindexProduct` wired into `ProductBuyBoxService::refresh()` and product text edits/deletes
  (the database engine needs fresh documents). (done)
- S2: Meilisearch engine and index settings, typo tolerance + "showing results for", filter counts on the
  results page, ranking rules (rating, `sales_30d` added to documents), remaining reindex triggers
  (review summaries, brand/vertical/category renames).
- S3: query parser (price/brand phrases), synonyms and redirects, analytics tables, recent + popular
  searches in suggestions, zero-results page.
- S4: admin screens, `search.manage`, reports, pinned results, pruning.
- Later: personalised ranking, Hindi/Hinglish transliteration, sponsored results.
