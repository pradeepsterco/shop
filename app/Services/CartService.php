<?php

namespace App\Services;

use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Multi-vendor cart.
 *
 * - One line per seller listing (cart_items.listing_id, unique per cart).
 * - Guests and users both use the database (guest = hashed cookie token).
 * - Every write runs in a transaction that first locks the cart row, so
 *   concurrent requests on the same cart are serialized (no lost updates,
 *   no duplicate lines). Deadlocks are retried.
 * - Prices live as integer paise (minor units). The cart stores a snapshot;
 *   view() re-syncs it from the live listing and flags price changes / stock problems.
 */
class CartService
{
    public const MAX_QTY_PER_LINE = 10;

    public const MAX_LINES = 100;

    public const STALE_AFTER_SECONDS = 60;

    private const COOKIE = 'cart_token';

    private const COOKIE_MINUTES = 60 * 24 * 60; // 60 days

    private const TX_ATTEMPTS = 3;

    /** A guest cart created during this request: its cookie is only queued, so request()->cookie() can't see it yet. */
    private ?Cart $newGuestCart = null;

    // ───────────────────────── Mutations ─────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function add(int $listingId, int $qty = 1): array
    {
        $qty = max(1, $qty);
        $listing = $this->listings([$listingId])->get($listingId);
        if (! $listing) {
            return $this->result(false, 'This item is not available.');
        }

        // A seller cannot add their own listings to their cart
        $user = auth()->user();
        if ($user instanceof User && $user->sellerProfile && $listing->seller_profile_id === $user->sellerProfile->id) {
            return $this->result(false, 'You cannot add your own listings to your cart.');
        }

        [$status] = $this->availabilityOf($listing, 1);
        if ($status !== 'ok') {
            return $this->result(false, $status === 'out_of_stock' ? 'This item is out of stock.' : 'This item is not available.');
        }

        $cart = $this->currentCart(true);

        [$ok, $msg] = DB::transaction(function () use ($cart, $listing, $qty) {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->first();
            if (! $cart) {
                return [false, 'Cart not found.'];
            }

            $item = $cart->items()->where('listing_id', $listing->id)->first();
            if (! $item && $cart->items()->count() >= self::MAX_LINES) {
                return [false, 'Your cart is full.'];
            }

            $wanted = ($item ? $item->qty : 0) + $qty;
            $cap = min(self::MAX_QTY_PER_LINE, (int) $listing->stock);
            $newQty = min($wanted, $cap);
            if ($item && $newQty <= $item->qty) {
                return [false, "You already have the maximum available ({$item->qty})."];
            }

            $fields = ['qty' => $newQty, 'availability' => 'ok', 'available_qty' => null, 'synced_at' => now()]
                + $this->display($listing)
                + ['unit_price' => $this->toMinor($listing->selling_price)];

            if ($item) {
                // Price the user sees now is the live one; clear any stale "price changed" marker.
                $item->update($fields + ['prev_unit_price' => null]);
            } else {
                $cart->items()->create($fields + ['listing_id' => $listing->id]);
            }

            $cart->forceFill(['last_activity_at' => now()])->save();

            return [true, $newQty < $wanted ? "Only {$newQty} available, quantity adjusted." : 'Added to cart.'];
        }, self::TX_ATTEMPTS);

        return $this->result($ok, $msg);
    }

    /**
     * @return array<string, mixed>
     */
    public function setQty(int $itemId, int $qty): array
    {
        return $this->updateQty($itemId, $qty, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function increase(int $itemId, int $by = 1): array
    {
        return $this->updateQty($itemId, max(1, $by), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function decrease(int $itemId, int $by = 1): array
    {
        return $this->updateQty($itemId, -max(1, $by), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function updateQty(int $itemId, int $value, bool $relative): array
    {
        [$ok, $msg] = DB::transaction(function () use ($itemId, $value, $relative) {
            $cart = $this->lockedCart();
            if (! $cart) {
                return [false, 'Cart not found.'];
            }

            $item = $cart->items()->whereKey($itemId)->first();
            if (! $item) {
                return [false, 'Item not found.'];
            }

            // Read the qty under the lock, so rapid +/- clicks never overwrite each other.
            $target = $relative ? $item->qty + $value : $value;

            if ($target <= 0) {
                $item->delete();

                return [true, 'Item removed.'];
            }

            if ($target > $item->qty) {
                $listing = $this->listings([$item->listing_id])->get($item->listing_id);
                [$status] = $this->availabilityOf($listing, 1);
                if ($status === 'unavailable' || $status === 'out_of_stock') {
                    return [false, 'This item is no longer available.'];
                }
                $cap = min(self::MAX_QTY_PER_LINE, (int) $listing->stock);
                if ($cap <= $item->qty) {
                    return [false, "Maximum available quantity is {$item->qty}."];
                }
                $target = min($target, $cap);
            }

            $item->update(['qty' => $target]);
            $cart->forceFill(['last_activity_at' => now()])->save();

            return [true, 'Quantity updated.'];
        }, self::TX_ATTEMPTS);

        return $this->result($ok, $msg);
    }

    /**
     * @return array<string, mixed>
     */
    public function remove(int $itemId): array
    {
        DB::transaction(function () use ($itemId) {
            $cart = $this->lockedCart();
            $cart?->items()->whereKey($itemId)->delete();
        }, self::TX_ATTEMPTS);

        return $this->result(true, 'Item removed.');
    }

    /**
     * @return array<string, mixed>
     */
    public function removeSeller(int $sellerProfileId): array
    {
        DB::transaction(function () use ($sellerProfileId) {
            $cart = $this->lockedCart();
            $cart?->items()->where('seller_profile_id', $sellerProfileId)->delete();
        }, self::TX_ATTEMPTS);

        return $this->result(true, 'Seller items removed.');
    }

    /**
     * @return array<string, mixed>
     */
    public function clear(): array
    {
        DB::transaction(function () {
            $cart = $this->lockedCart();
            $cart?->items()->delete();
        }, self::TX_ATTEMPTS);

        return $this->result(true, 'Cart cleared.');
    }

    /**
     * User saw the "price changed" notice and accepts the new prices.
     *
     * @return array<string, mixed>
     */
    public function acknowledgePriceChanges(): array
    {
        if ($cart = $this->currentCart()) {
            $cart->items()->whereNotNull('prev_unit_price')->update(['prev_unit_price' => null]);
        }

        return $this->result(true, 'Prices accepted.');
    }

    /**
     * Call on the Login event. Moves/merges the guest cart into the user's cart.
     */
    public function mergeGuestIntoUser(User $user): void
    {
        $rawToken = request()->cookie(self::COOKIE) ?? Cookie::queued(self::COOKIE)?->getValue();
        $token = is_string($rawToken) && $rawToken !== '' ? $rawToken : null;
        $guest = null;

        if ($token !== null) {
            $guest = Cart::where('guest_token', hash('sha256', $token))->whereNull('user_id')->first();
        }

        if (! $guest && $this->newGuestCart && $this->newGuestCart->user_id === null) {
            $guest = $this->newGuestCart;
        }

        if (! $guest) {
            Cookie::queue(Cookie::forget(self::COOKIE));

            return;
        }

        $userCart = Cart::where('user_id', $user->id)->first() ?? $this->createCart(['user_id' => $user->id]);

        DB::transaction(function () use ($guest, $userCart, $user) {
            // Lock both carts in id order so two merges can never deadlock each other.
            Cart::whereIn('id', [$guest->id, $userCart->id])->orderBy('id')->lockForUpdate()->get();

            $existing = $userCart->items()->get()->keyBy('listing_id');
            $guestItems = $guest->items()->get();
            $allListingIds = $guestItems->pluck('listing_id')->merge($existing->pluck('listing_id'))->unique()->all();
            $listings = $this->listings($allListingIds);

            $lines = $existing->count();
            $userSellerProfileId = $user->sellerProfile?->id;

            foreach ($guestItems as $gItem) {
                $listing = $listings->get($gItem->listing_id);
                if (! $listing) {
                    continue;
                }

                // Requirement 8: A seller can't add their own listings to their cart.
                if ($userSellerProfileId && $listing->seller_profile_id === $userSellerProfileId) {
                    continue;
                }

                // Requirement 6: On merge, cap qty by min(stock, MAX_QTY_PER_LINE).
                $cap = min(max(0, (int) $listing->stock), self::MAX_QTY_PER_LINE);

                if ($mine = $existing->get($gItem->listing_id)) {
                    $wanted = $mine->qty + $gItem->qty;
                    $newQty = min($wanted, $cap);
                    if ($newQty > 0) {
                        $mine->update(['qty' => $newQty]);
                    }
                } elseif ($lines < self::MAX_LINES) {
                    $newQty = min($gItem->qty, $cap);
                    if ($newQty > 0) {
                        $gItem->update([
                            'cart_id' => $userCart->id,
                            'qty' => $newQty,
                        ]);
                        $lines++;
                    }
                }
            }

            $userCart->forceFill(['last_activity_at' => now()])->save();
            $guest->delete(); // cascades any leftover guest lines
        }, self::TX_ATTEMPTS);

        $this->newGuestCart = null;
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    // ───────────────────────── Reads ─────────────────────────

    /** Cheap number for the header badge. */
    public function count(): int
    {
        $cart = $this->currentCart();

        return $cart ? (int) $cart->items()->sum('qty') : 0;
    }

    /**
     * Check if a listing is currently in the active cart.
     */
    public function hasListing(int $listingId): bool
    {
        $cart = $this->currentCart();

        return $cart ? $cart->items()->where('listing_id', $listingId)->exists() : false;
    }

    /**
     * Full cart grouped by seller. Re-syncs prices/stock from live listings when the
     * snapshot is older than STALE_AFTER_SECONDS (or always when $force, e.g. before checkout).
     *
     * @return array<string, mixed>
     */
    public function view(bool $force = false): array
    {
        $cart = $this->currentCart();
        if (! $cart) {
            return $this->emptyView();
        }

        $stale = $force || $cart->items()
            ->where(fn ($q) => $q->whereNull('synced_at')->orWhere('synced_at', '<', now()->subSeconds(self::STALE_AFTER_SECONDS)))
            ->exists();

        $changes = $stale ? $this->reprice($cart) : [];

        $items = $cart->items()->orderBy('seller_profile_id')->orderBy('id')->get();
        if ($items->isEmpty()) {
            return $this->emptyView();
        }

        $sellers = [];
        $subtotal = $mrpTotal = $units = 0;
        $issues = 0;

        foreach ($items->groupBy('seller_profile_id') as $sellerProfileId => $rows) {
            $sSub = $sMrp = $sUnits = 0;
            $lines = [];

            foreach ($rows as $it) {
                $buyable = $it->availability === 'ok';
                $lineTotal = $it->qty * $it->unit_price;

                if ($buyable) {
                    $sSub += $lineTotal;
                    $sMrp += $it->qty * max($it->mrp, $it->unit_price);
                    $sUnits += $it->qty;
                } else {
                    $issues++;
                }
                if ($it->prev_unit_price !== null) {
                    $issues++;
                }

                $lines[] = [
                    'id' => $it->id,
                    'listing_id' => $it->listing_id,
                    'product_id' => $it->product_id,
                    'product_variant_id' => $it->product_variant_id,
                    'seller_profile_id' => $it->seller_profile_id,
                    'seller_name' => $it->seller_name,
                    'name' => $it->name,
                    'sku' => $it->sku,
                    'image' => $it->image,
                    'qty' => $it->qty,
                    'unit_price' => $it->unit_price,
                    'mrp' => $it->mrp,
                    'line_total' => $lineTotal,
                    'price_changed' => $it->prev_unit_price !== null,
                    'prev_unit_price' => $it->prev_unit_price,
                    'availability' => $it->availability,   // ok | insufficient | out_of_stock | unavailable
                    'available_qty' => $it->available_qty,
                ];
            }

            $sellers[] = [
                'seller_profile_id' => (int) $sellerProfileId,
                'seller_name' => $rows->first()->seller_name,
                'items' => $lines,
                'units' => $sUnits,
                'subtotal' => $sSub,
                'mrp_total' => $sMrp,
            ];

            $subtotal += $sSub;
            $mrpTotal += $sMrp;
            $units += $sUnits;
        }

        return [
            'sellers' => $sellers,
            'lines' => $items->count(),
            'units' => $units,        // only purchasable lines are counted in units and totals
            'subtotal' => $subtotal,     // paise
            'mrp_total' => $mrpTotal,     // paise
            'savings' => max(0, $mrpTotal - $subtotal),
            'price_changes' => $changes,     // only filled on the request that detected them
            'has_issues' => $issues > 0,
            'can_checkout' => $issues === 0 && $units > 0,
        ];
    }

    // ───────────────────────── Internals ─────────────────────────

    /**
     * Batch re-sync of every line from the live listings (1 SELECT + writes only for changed rows).
     *
     * @return array<int, array<string, mixed>>
     */
    private function reprice(Cart $cart): array
    {
        return DB::transaction(function () use ($cart) {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->first();
            if (! $cart) {
                return [];
            }

            $items = $cart->items()->get();
            if ($items->isEmpty()) {
                return [];
            }

            $listings = $this->listings($items->pluck('listing_id')->all());
            $changes = [];
            $untouched = [];

            foreach ($items as $item) {
                $listing = $listings->get($item->listing_id);
                [$status, $avail] = $this->availabilityOf($listing, $item->qty);

                $attrs = ['availability' => $status, 'available_qty' => $avail];

                if ($listing) {
                    $new = $this->toMinor($listing->selling_price);
                    $orig = $item->prev_unit_price ?? $item->unit_price; // the price the user originally saw
                    $attrs += $this->display($listing);
                    $attrs['unit_price'] = $new;
                    $attrs['prev_unit_price'] = $new === (int) $orig ? null : $orig;

                    if ($new !== (int) $item->unit_price) {
                        $changes[] = [
                            'item_id' => $item->id,
                            'name' => $item->name,
                            'old_price' => (int) $orig,
                            'new_price' => $new,
                        ];
                    }
                }

                $item->fill($attrs);
                if ($item->isDirty()) {
                    $item->synced_at = now();
                    $item->save();
                } else {
                    $untouched[] = $item->id;
                }
            }

            if (! empty($untouched)) {
                CartItem::whereIn('id', $untouched)->update(['synced_at' => now()]);
            }

            return $changes;
        }, self::TX_ATTEMPTS);
    }

    private function currentCart(bool $create = false): ?Cart
    {
        if ($user = auth()->user()) {
            return Cart::where('user_id', $user->id)->first()
                ?? ($create ? $this->createCart(['user_id' => $user->id]) : null);
        }

        if ($this->newGuestCart) {
            return $this->newGuestCart;
        }

        $rawToken = request()->cookie(self::COOKIE);
        $token = is_string($rawToken) && $rawToken !== '' ? $rawToken : null;
        if ($token !== null) {
            $cart = Cart::where('guest_token', hash('sha256', $token))->whereNull('user_id')->first();
            if ($cart) {
                return $cart;
            }
        }

        if (! $create) {
            return null;
        }

        $token = Str::random(40);
        Cookie::queue(self::COOKIE, $token, self::COOKIE_MINUTES);

        return $this->newGuestCart = $this->createCart(['guest_token' => hash('sha256', $token)]);
    }

    /** Must be called inside a transaction: returns the cart row locked for this request. */
    private function lockedCart(): ?Cart
    {
        $cart = $this->currentCart();

        return $cart ? Cart::whereKey($cart->id)->lockForUpdate()->first() : null;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function createCart(array $attrs): Cart
    {
        try {
            return Cart::create($attrs + ['last_activity_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            // Two requests tried to create the same user's cart at once; use the winner's.
            return Cart::where($attrs)->firstOrFail();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function result(bool $ok, string $message): array
    {
        return ['success' => $ok, 'message' => $message, 'cart' => $this->view()];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyView(): array
    {
        return [
            'sellers' => [],
            'lines' => 0,
            'units' => 0,
            'subtotal' => 0,
            'mrp_total' => 0,
            'savings' => 0,
            'price_changes' => [],
            'has_issues' => false,
            'can_checkout' => false,
        ];
    }

    /**
     * Money is already stored as minor units (paise) on listings.
     */
    private function toMinor(mixed $amount): int
    {
        return (int) $amount;
    }

    // ─────────── Listing schema adapters ───────────

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Listing> keyed by listing id
     */
    private function listings(array $ids): Collection
    {
        return Listing::withTrashed()
            ->with([
                'variant.product.images',
                'variant.product.vertical.attributes',
                'sellerProfile',
            ])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * Full visibility rule:
     * - listing status Active (enum compare, not truthiness)
     * - not archived / soft-deleted
     * - variant approved
     * - product approved
     * - seller approved
     *
     * @return array{0: string, 1: ?int} [status, available qty when not ok]
     */
    private function availabilityOf(?Listing $listing, int $qty): array
    {
        if (! $listing || $listing->trashed()) {
            return ['unavailable', null];
        }

        if ($listing->status !== ListingStatus::Active) {
            return ['unavailable', null];
        }

        $variant = $listing->relationLoaded('variant') ? $listing->variant : $listing->variant()->first();
        if (! $variant || $variant->trashed() || $variant->qc_status !== VariantQcStatus::Approved) {
            return ['unavailable', null];
        }

        $product = $variant->relationLoaded('product') ? $variant->product : $variant->product()->first();
        if (! $product || $product->qc_status !== ProductQcStatus::Approved) {
            return ['unavailable', null];
        }

        $seller = $listing->relationLoaded('sellerProfile') ? $listing->sellerProfile : $listing->sellerProfile()->first();
        if (! $seller || $seller->status !== SellerStatus::Approved) {
            return ['unavailable', null];
        }

        $stock = (int) $listing->stock;
        if ($stock <= 0) {
            return ['out_of_stock', 0];
        }

        if ($stock < $qty) {
            return ['insufficient', $stock];
        }

        return ['ok', null];
    }

    /**
     * Snapshot columns copied from the live listing onto the cart line.
     * Line name = product title + variant attribute labels (e.g. "Black, M"); image = primary live product image.
     *
     * @return array<string, mixed>
     */
    private function display(Listing $listing): array
    {
        $variant = $listing->relationLoaded('variant') ? $listing->variant : $listing->variant()->first();
        $product = $variant ? ($variant->relationLoaded('product') ? $variant->product : $variant->product()->first()) : null;
        $seller = $listing->relationLoaded('sellerProfile') ? $listing->sellerProfile : $listing->sellerProfile()->first();

        $labels = [];
        if ($variant && ! empty($variant->variant_attributes)) {
            foreach ($variant->variant_attributes as $code => $val) {
                $label = $variant->optionDisplayLabel($code);
                $labels[] = $label !== '' ? $label : (string) $val;
            }
        }
        $labelsStr = implode(', ', array_filter($labels));
        $productTitle = $product ? $product->title : 'Product';
        $lineName = $labelsStr !== '' ? "{$productTitle} - {$labelsStr}" : $productTitle;

        // Primary live product image
        $primaryImg = null;
        if ($product) {
            if ($product->relationLoaded('images')) {
                $primaryImg = $product->images->first(fn ($img) => $img->is_primary && $img->status === ProductImageStatus::Live)
                    ?? $product->images->first(fn ($img) => $img->status === ProductImageStatus::Live)
                    ?? $product->images->first();
            } else {
                $primaryImg = $product->images()->where('status', ProductImageStatus::Live)->orderByDesc('is_primary')->first()
                    ?? $product->images()->first();
            }
        }
        $image = $primaryImg ? $primaryImg->url() : null;

        $price = $this->toMinor($listing->selling_price);
        $mrp = $this->toMinor($listing->mrp);

        return [
            'product_id' => $product ? $product->id : 0,
            'product_variant_id' => $variant ? $variant->id : null,
            'seller_profile_id' => $listing->seller_profile_id,
            'seller_name' => $seller?->store_name,
            'name' => $lineName,
            'sku' => $listing->sku,
            'image' => $image,
            'mrp' => max($mrp, $price),
        ];
    }
}
