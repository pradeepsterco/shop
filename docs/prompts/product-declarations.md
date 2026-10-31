# Product declarations spec (country of origin, Legal Metrology)

Show the declarations Indian law expects on a product page: country of origin, manufacturer / packer / importer, generic name, net quantity, MRP and unit sale price, consumer care. Today the catalog stores none of these.

Branch: `catalog-declarations`. Module: `App\Catalog` (new `Models/`, `Services/`, `Support/`); existing catalog models stay in `App\Models`.

## Basis

To be confirmed by the company's CA or lawyer before launch (items marked **[CA]** below need their answer).

- **Legal Metrology (Packaged Commodities) Rules, 2011, rule 6(1) and 6(10)** (in force since 1 January 2018): an e-commerce entity must show the package declarations on its site, except the month and year of manufacture. The declarations: name and address of the manufacturer (and the packer, if different); for imported goods, the importer and the country of origin, manufacture or assembly; common or generic name; net quantity in standard units or number; MRP inclusive of all taxes; unit sale price (since 1 April 2023); consumer care details.
- **Legal Metrology (Packaged Commodities) Amendment Rules, 2026, new rule 6(10A)** (notified 13 February 2026, in force **1 July 2026**, so already in force): a site selling imported products must offer a searchable and sortable filter by country of origin.
- **Consumer Protection (E-Commerce) Rules, 2020, rule 6(5)(d)**: the seller gives the marketplace all relevant product details, including country of origin, before purchase. **Amendment Rules, 2026** (notified 9 September 2026, in force **1 January 2027**): rewrites 6(5)(d) as a list (country of origin; best-before / use-before date; returns, refund, warranty; delivery and payment), and rule 4(6) requires imported goods to be identified with their country of origin.
- CCPA has issued notices both for missing **and for false** country of origin, so the seller's answer must be an explicit, recorded declaration.

## Where the data lives

Different sellers can sell the same catalog product from different sources (one makes it, one imports it), so:

| Data | Level | Why | Entered by |
|---|---|---|---|
| Generic name, net quantity | Product (catalog content, QC'd) | Same for every seller of the product | Product creator |
| Country of origin, manufacturer, packer, importer, consumer care | **Seller + product** (one row per seller per product, covering all that seller's variants) | Depends on where the seller sources it | Each seller |
| MRP, unit sale price | Listing (already there; unit sale price computed) | Per seller, per variant | Each seller |

Net quantity is per product, not per variant: today's variants are size, colour, RAM etc., not pack size. A future "pack of N" variant attribute would need a per-variant override (not in this spec).

## Data

`products` (new columns, nullable for existing rows)
- `generic_name` string(120): common name of the commodity, e.g. "Action figure", "T-shirt", "Board game".
- `net_quantity` decimal(10,3) and `net_quantity_unit` string(10): one of `g`, `kg`, `ml`, `l`, `cm`, `m`, `sq_m`, `count` (shown as "N"), `pair`.
- Both go through `pending_changes` like title and specs when an approved product is edited.

`seller_product_declarations`
- `seller_profile_id` (cascade), `product_id` (cascade), unique together.
- `country_of_origin` char(2), ISO 3166-1 alpha-2 (`IN` for made in India).
- `manufacturer_name` (≤150), `manufacturer_address` (≤500): required.
- `packer_name`, `packer_address`: optional, only when the packer is not the manufacturer.
- `importer_name`, `importer_address`: required when `country_of_origin` is not `IN`, cleared when it is.
- `consumer_care` (≤500): name, phone and/or email, and address to contact for complaints, as printed on the pack.
- `declared_at`: when the seller last ticked "These details match the product's packaging and are true" (required on every save).

`order_items.declaration` json (nullable): snapshot of the seller's declaration and the product's generic name and net quantity at order time, so a later "wrong country of origin" complaint can be checked against what the customer was shown. Not shown to customers in this spec.

`App\Catalog\Support\Countries`: static ISO list (code → name), India first. No package.

## Rules

- A seller cannot save an **active** listing for a product without a complete declaration for that product (wizard, quick sell, edit page, quick stock). Draft and inactive listings may lack one.
- Product creators must give generic name and net quantity before submitting a new product to QC; QC edits to an approved product must keep them filled.
- Net quantity > 0; unit must be from the list.
- Changing a declaration takes effect immediately (no QC), is logged on the declaration (`declared_at`), and reindexes the product for search.
- **Existing data (Open question 1):** existing active listings have no declaration. Recommended: sellers see a banner and a "Declarations missing" filter in My Products; after `catalog.declarations_required_from` (a date in config, set by the user), listings without a declaration drop out of the buy box and cannot be bought. Until then the product page shows what is filled and omits the rest.

## Seller pages

- **Add product wizard:** Step 3 (product details) gets generic name and net quantity. Step 4 (variants and offers) gets a "Declarations" block: country (searchable select), manufacturer, "packed by someone else" toggle for packer, importer (appears when country is not India), consumer care, the declaration tick. A "Use my business details" button fills manufacturer (or packer) with the seller's legal name and pickup address.
- **Quick sell modal** (listing an existing catalog product): the same block, prefilled from the seller's existing declaration for that product if any.
- **Edit product:** a "Your declarations for this product" card next to "Your Listing Offers & Inventory" (every seller); generic name and net quantity in the catalog card (creator only).
- **My Products:** "Declarations missing" badge per product and filter.

## Admin

- Product QC modal (`/admin/catalog/products`): generic name and net quantity (with pending changes shown like title/specs); approving requires them. A "Seller declarations" table: store, country, manufacturer, packer, importer, consumer care, declared at. Read only.
- No new permission; uses the existing catalog permission.

## Storefront

- Product page, next to "Sold by": **Country of origin: {country}** for the shown seller's listing.
- Section **"Manufacturing, packaging and import info"** below specifications: generic name, net quantity, MRP (inclusive of all taxes), unit sale price, manufacturer, packer, importer, consumer care. All of it follows the listing being shown (buy box, or the seller the customer picked).
- Unit sale price: MRP ÷ net quantity, per 1 kg / 1 l / 1 m / 1 m² (or per g / ml / cm when net quantity is under one of those), or per piece for count. **[CA]** confirm format.
- **Country of origin filter** on `/products` (rule 6(10A)): search documents get `origin_countries` (countries of the product's active, approved-seller listings); the filter lists countries with counts and works with every other filter and with `q`. "Sortable" is read as "the filter's countries can be sorted"; **[CA]** confirm whether a "sort results by country" option is expected. This touches `App\Search` (`ProductDocumentBuilder`, product-index filters), within its existing rules.

## Chunks

1. **D1 Catalog fields:** product columns, countries list, wizard step 3, edit catalog card, `pending_changes`, admin QC.
2. **D2 Seller declarations:** table, model, `DeclarationService` (validate, save, completeness), wizard step 4, quick sell, edit card, My Products badge and filter, active-listing rule, config date and buy-box exclusion.
3. **D3 Storefront and orders:** product page section and country line, unit sale price, order item snapshot.
4. **D4 Filter:** search document field, `/products` country filter with counts.

Each chunk: related Pest tests, then `composer test`, `vendor/bin/pint --dirty`, commit and push.

## Not in this spec

- **Best-before / use-before date** (E-Commerce amendment from 1 January 2027; Legal Metrology for perishables). It changes per batch, so it belongs on the listing with a per-vertical switch. None of today's verticals (apparel, mobiles, toys) need it; needed before selling food, cosmetics or medicines. **[CA]**
- **BIS / quality certification:** toys need the ISI mark under the Toys (Quality Control) Order, 2020, and mobiles need BIS CRS registration. That is a condition for selling, not a page declaration, but marketplaces have faced BIS raids for uncertified toys. A per-vertical "certificate number required" field is a good follow-up. **[CA]**
- Per-variant net quantity (pack sizes); showing the snapshot on the customer's order page or invoice.

## Open questions

1. Existing listings without a declaration: grace date then hidden (recommended), hidden immediately, or only flagged?
2. Build the country filter (D4) here, or hand it to the paused search work? It is already legally required, so recommended here.
3. **[CA]** Is a marketplace (not inventory) model exempt from any of rule 6(10)? Is the manufacturer's or seller's consumer care acceptable, or must it be the marketplace's grievance contact? Are any categories exempt from net quantity?
