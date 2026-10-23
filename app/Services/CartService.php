<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductOffer;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Multi-vendor cart.
 *
 * - One line per seller offer (cart_items.offer_id, unique per cart).
 * - Guests and users both use the database (guest = hashed cookie token).
 * - Every write runs in a transaction that first locks the cart row, so
 *   concurrent requests on the same cart are serialized (no lost updates,
 *   no duplicate lines). Deadlocks are retried.
 * - Prices live as integer paise. The cart stores a snapshot; view() re-syncs
 *   it from the live offer and flags price changes / stock problems.
 *
 * ADAPT HERE: the only place that knows your offer schema is offers(),
 * availabilityOf() and display() below. Assumed `product_offers` columns:
 * id, product_id, variant_id, seller_id, sku, mrp, price, stock, status
 * and relations product (title, thumb_image), variant (name), seller (name).
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

    public function add(int $offerId, int $qty = 1): array
    {
        $qty = max(1, $qty);
        $offer = $this->offers([$offerId])->get($offerId);
        [$status] = $this->availabilityOf($offer, 1);
        if ($status !== 'ok') {
            return $this->result(false, $status === 'out_of_stock' ? 'This item is out of stock.' : 'This item is not available.');
        }

        $cart = $this->currentCart(true);

        [$ok, $msg] = DB::transaction(function () use ($cart, $offer, $qty) {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->first();
            if (!$cart) {
                return [false, 'Cart not found.'];
            }

            $item = $cart->items()->where('offer_id', $offer->id)->first();
            if (!$item && $cart->items()->count() >= self::MAX_LINES) {
                return [false, 'Your cart is full.'];
            }

            $wanted = ($item?->qty ?? 0) + $qty;
            $cap = min(self::MAX_QTY_PER_LINE, (int) $offer->stock);
            $newQty = min($wanted, $cap);
            if ($item && $newQty <= $item->qty) {
                return [false, "You already have the maximum available ({$item->qty})."];
            }

            $fields = ['qty' => $newQty, 'availability' => 'ok', 'available_qty' => null, 'synced_at' => now()]
                + $this->display($offer)
                + ['unit_price' => $this->toMinor($offer->price)];

            if ($item) {
                // Price the user sees now is the live one; clear any stale "price changed" marker.
                $item->update($fields + ['prev_unit_price' => null]);
            } else {
                $cart->items()->create($fields + ['offer_id' => $offer->id]);
            }

            $cart->forceFill(['last_activity_at' => now()])->save();

            return [true, $newQty < $wanted ? "Only {$newQty} available, quantity adjusted." : 'Added to cart.'];
        }, self::TX_ATTEMPTS);

        return $this->result($ok, $msg);
    }

    public function setQty(int $itemId, int $qty): array
    {
        return $this->updateQty($itemId, $qty, false);
    }

    public function increase(int $itemId, int $by = 1): array
    {
        return $this->updateQty($itemId, max(1, $by), true);
    }

    public function decrease(int $itemId, int $by = 1): array
    {
        return $this->updateQty($itemId, -max(1, $by), true);
    }

    private function updateQty(int $itemId, int $value, bool $relative): array
    {
        [$ok, $msg] = DB::transaction(function () use ($itemId, $value, $relative) {
            $cart = $this->lockedCart();
            if (!$cart) {
                return [false, 'Cart not found.'];
            }

            $item = $cart->items()->whereKey($itemId)->first();
            if (!$item) {
                return [false, 'Item not found.'];
            }

            // Read the qty under the lock, so rapid +/- clicks never overwrite each other.
            $target = $relative ? $item->qty + $value : $value;

            if ($target <= 0) {
                $item->delete();
                return [true, 'Item removed.'];
            }

            if ($target > $item->qty) {
                $offer = $this->offers([$item->offer_id])->get($item->offer_id);
                [$status] = $this->availabilityOf($offer, 1);
                if ($status === 'unavailable' || $status === 'out_of_stock') {
                    return [false, 'This item is no longer available.'];
                }
                $cap = min(self::MAX_QTY_PER_LINE, (int) $offer->stock);
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

    public function remove(int $itemId): array
    {
        DB::transaction(function () use ($itemId) {
            $cart = $this->lockedCart();
            $cart?->items()->whereKey($itemId)->delete();
        }, self::TX_ATTEMPTS);

        return $this->result(true, 'Item removed.');
    }

    public function removeSeller(int $sellerId): array
    {
        DB::transaction(function () use ($sellerId) {
            $cart = $this->lockedCart();
            $cart?->items()->where('seller_id', $sellerId)->delete();
        }, self::TX_ATTEMPTS);

        return $this->result(true, 'Seller items removed.');
    }

    public function clear(): array
    {
        DB::transaction(function () {
            $cart = $this->lockedCart();
            $cart?->items()->delete();
        }, self::TX_ATTEMPTS);

        return $this->result(true, 'Cart cleared.');
    }

    /** User saw the "price changed" notice and accepts the new prices. */
    public function acknowledgePriceChanges(): array
    {
        if ($cart = $this->currentCart()) {
            $cart->items()->whereNotNull('prev_unit_price')->update(['prev_unit_price' => null]);
        }

        return $this->result(true, 'Prices accepted.');
    }

    /** Call on the Login event. Moves/merges the guest cart into the user's cart. */
    public function mergeGuestIntoUser(User $user): void
    {
        $token = request()->cookie(self::COOKIE);
        if (!$token) {
            return;
        }

        $guest = Cart::where('guest_token', hash('sha256', $token))->whereNull('user_id')->first();
        if (!$guest) {
            Cookie::queue(Cookie::forget(self::COOKIE));
            return;
        }

        $userCart = Cart::where('user_id', $user->id)->first() ?? $this->createCart(['user_id' => $user->id]);

        DB::transaction(function () use ($guest, $userCart) {
            // Lock both carts in id order so two merges can never deadlock each other.
            Cart::whereIn('id', [$guest->id, $userCart->id])->orderBy('id')->lockForUpdate()->get();

            $existing = $userCart->items()->get()->keyBy('offer_id');
            $lines = $existing->count();

            foreach ($guest->items()->get() as $gItem) {
                if ($mine = $existing->get($gItem->offer_id)) {
                    $mine->update(['qty' => min($mine->qty + $gItem->qty, self::MAX_QTY_PER_LINE)]);
                } elseif ($lines < self::MAX_LINES) {
                    $gItem->update(['cart_id' => $userCart->id]);
                    $lines++;
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
     * Full cart grouped by seller. Re-syncs prices/stock from live offers when the
     * snapshot is older than STALE_AFTER_SECONDS (or always when $force, e.g. before checkout).
     */
    public function view(bool $force = false): array
    {
        $cart = $this->currentCart();
        if (!$cart) {
            return $this->emptyView();
        }

        $stale = $force || $cart->items()
            ->where(fn ($q) => $q->whereNull('synced_at')->orWhere('synced_at', '<', now()->subSeconds(self::STALE_AFTER_SECONDS)))
            ->exists();

        $changes = $stale ? $this->reprice($cart) : [];

        $items = $cart->items()->orderBy('seller_id')->orderBy('id')->get();
        if ($items->isEmpty()) {
            return $this->emptyView();
        }

        $sellers = [];
        $subtotal = $mrpTotal = $units = 0;
        $issues = 0;

        foreach ($items->groupBy('seller_id') as $sellerId => $rows) {
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
                    'id'              => $it->id,
                    'offer_id'        => $it->offer_id,
                    'product_id'      => $it->product_id,
                    'variant_id'      => $it->variant_id,
                    'seller_id'       => $it->seller_id,
                    'name'            => $it->name,
                    'sku'             => $it->sku,
                    'image'           => $it->image,
                    'qty'             => $it->qty,
                    'unit_price'      => $it->unit_price,
                    'mrp'             => $it->mrp,
                    'line_total'      => $lineTotal,
                    'price_changed'   => $it->prev_unit_price !== null,
                    'prev_unit_price' => $it->prev_unit_price,
                    'availability'    => $it->availability,   // ok | insufficient | out_of_stock | unavailable
                    'available_qty'   => $it->available_qty,
                ];
            }

            $sellers[] = [
                'seller_id'   => (int) $sellerId,
                'seller_name' => $rows->first()->seller_name,
                'items'       => $lines,
                'units'       => $sUnits,
                'subtotal'    => $sSub,
                'mrp_total'   => $sMrp,
            ];

            $subtotal += $sSub;
            $mrpTotal += $sMrp;
            $units += $sUnits;
        }

        return [
            'sellers'      => $sellers,
            'lines'        => $items->count(),
            'units'        => $units,        // only purchasable lines are counted in units and totals
            'subtotal'     => $subtotal,     // paise
            'mrp_total'    => $mrpTotal,     // paise
            'savings'      => max(0, $mrpTotal - $subtotal),
            'price_changes' => $changes,     // only filled on the request that detected them
            'has_issues'   => $issues > 0,
            'can_checkout' => $issues === 0 && $units > 0,
        ];
    }

    // ───────────────────────── Internals ─────────────────────────

    /** Batch re-sync of every line from the live offers (1 SELECT + writes only for changed rows). */
    private function reprice(Cart $cart): array
    {
        return DB::transaction(function () use ($cart) {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->first();
            if (!$cart) {
                return [];
            }

            $items = $cart->items()->get();
            if ($items->isEmpty()) {
                return [];
            }

            $offers = $this->offers($items->pluck('offer_id')->all());
            $changes = [];
            $untouched = [];

            foreach ($items as $item) {
                $offer = $offers->get($item->offer_id);
                [$status, $avail] = $this->availabilityOf($offer, $item->qty);

                $attrs = ['availability' => $status, 'available_qty' => $avail];

                if ($offer) {
                    $new = $this->toMinor($offer->price);
                    $orig = $item->prev_unit_price ?? $item->unit_price; // the price the user originally saw
                    $attrs += $this->display($offer);
                    $attrs['unit_price'] = $new;
                    $attrs['prev_unit_price'] = $new === (int) $orig ? null : $orig;

                    if ($new !== (int) $item->unit_price) {
                        $changes[] = [
                            'item_id'   => $item->id,
                            'name'      => $item->name,
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

            if ($untouched) {
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

        $token = request()->cookie(self::COOKIE);
        if ($token) {
            $cart = Cart::where('guest_token', hash('sha256', $token))->whereNull('user_id')->first();
            if ($cart) {
                return $cart;
            }
        }

        if (!$create) {
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

    private function createCart(array $attrs): Cart
    {
        try {
            return Cart::create($attrs + ['last_activity_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            // Two requests tried to create the same user's cart at once; use the winner's.
            return Cart::where($attrs)->firstOrFail();
        }
    }

    private function result(bool $ok, string $message): array
    {
        return ['success' => $ok, 'message' => $message, 'cart' => $this->view()];
    }

    private function emptyView(): array
    {
        return [
            'sellers' => [], 'lines' => 0, 'units' => 0, 'subtotal' => 0, 'mrp_total' => 0,
            'savings' => 0, 'price_changes' => [], 'has_issues' => false, 'can_checkout' => false,
        ];
    }

    private function toMinor(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    // ─────────── Offer schema adapters (edit these for your tables) ───────────

    /** @return Collection<int, ProductOffer> keyed by offer id */
    private function offers(array $ids): Collection
    {
        return ProductOffer::with(['product:id,title,thumb_image', 'variant:id,name', 'seller:id,name'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /** @return array{0:string,1:?int} [status, available qty when not ok] */
    private function availabilityOf(?ProductOffer $offer, int $qty): array
    {
        if (!$offer || !$offer->status || !$offer->product) {
            return ['unavailable', null];
        }
        $stock = (int) $offer->stock;
        if ($stock <= 0) {
            return ['out_of_stock', 0];
        }
        if ($stock < $qty) {
            return ['insufficient', $stock];
        }

        return ['ok', null];
    }

    /** Snapshot columns copied from the live offer onto the cart line. */
    private function display(ProductOffer $offer): array
    {
        $price = $this->toMinor($offer->price);

        return [
            'product_id'  => $offer->product_id,
            'variant_id'  => $offer->variant_id,
            'seller_id'   => $offer->seller_id,
            'seller_name' => $offer->seller?->name,
            'name'        => trim($offer->product->title . ($offer->variant ? ' - ' . $offer->variant->name : '')),
            'sku'         => $offer->sku,
            'image'       => $offer->product->thumb_image,
            'mrp'         => max($this->toMinor($offer->mrp), $price),
        ];
    }
}