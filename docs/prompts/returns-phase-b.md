# Returns — Phase B spec

Source of truth for returns phase B (branch `returns-phase-b`). Phase A (NDR, RTO, lost, partial
delivery, Fake simulation, admin/seller/customer screens) is merged into `master`.

## Base spec

- Admin staff approve/reject all returns (no seller accept/reject). Seller can view returns on
  their packages (read-only) and mark received + condition (notes + photos).
- Return window per vertical (days after delivery). Non-returnable verticals still allow
  reasons: damaged, wrong item, defective, missing item/part.
- Reasons: damaged, defective, wrong item, missing item/part, not as described, size/fit, quality,
  changed mind (only if vertical returnable). Photos required for damaged/defective/wrong/missing.
- Customer chooses Refund or Replacement. Partial returns by item and units allowed; a unit is
  never split; quantity guard (delivered − already returned/refunded); one active return per
  seller package. Window expired → blocked; admin override logged.
- Missing-item claims: no pickup; admin decides refund or reship.
- Reverse pickup failures → re-attempts → failed → return cancelled (customer may request again
  within window). Lost reverse shipment → customer still refunded.
- QC by admin after seller's received report: pass → refund or replacement; fail → ship back to
  customer (seller pays) or partial/full refund anyway. Restock only on pass.
- Replacement: after QC pass, linked ₹0 replacement order (same listing, same address) through the
  normal forward flow; out of stock → auto-convert to refund; max one replacement per unit, then
  refund only.
- Customer never pays any shipping. Seller pays all forward, reship, reverse and replacement
  shipping.
- Block customer account deletion with open returns or unpaid refunds.

## Additions (reuse phase A)

1. Pending `undelivered_items` from partial deliveries become customer-side decisions in the admin
   returns queue: refund or reship (no pickup needed). Settlement reversals for them already
   happened in phase A; don't reverse twice.
2. Reverse pickups use the same `shipments` / `shipment_events` pipeline with `direction = reverse`,
   including Fake simulation (simulation toolbar extended to reverse shipments).
3. Coupons: refunds use the existing coupon allocation per item (refund = what the customer
   actually paid for that unit after coupon discount); coupon usage release follows the existing
   partial-return rules in the Coupons module (`releaseCumulativeItemUnits`).

## Decisions

1. Scope as above, including replacements. Settlement hold for a package is at least the longest
   return window among its items (per vertical), not a fixed 7 days; an open return keeps the
   `return_hold`.
2. Reship shipping: seller pays. Delivered reship items re-credit the seller (new ledger source
   keys; the phase A reversal is never repeated or undone).
3. Return (reverse) shipping fee = forward shipping fee snapshot ×
   `returns.return_shipping_multiplier` (default 1.0), charged to the seller.
4. COD refunds: customer provides UPI ID or bank details (validated, stored encrypted); admin pays
   manually and records the UTR; statuses `pending → paid / failed` (customer updates details →
   retry). No store credit for now.
5. Partial units of a line may be returned.
6. Ekart reverse QC: off (config flag; needs account enablement).
7. Seller-funded coupons are blocked by the Coupons module, so all coupons are platform-funded and
   the full-price sale ledger entry is correct. A code comment + test documents this and fails if
   seller-funded coupons are ever enabled.

## Refund amount (per unit, coupon-aware)

For a line with quantity `Q`, unit price `P` and coupon allocation `D`, refunding `k` units when `p`
units were already refunded:

```
cum(n)   = (n == Q) ? D : floor(D * n / Q)
coupon   = cum(p + k) - cum(p)
refund   = P * k - coupon
```

This is the same rule as `CouponRedemptionInterface::releaseCumulativeItemUnits`, so the refund and
the coupon release always agree, and all units together refund exactly `line_total − D`. `p` is read
from stored refund rows, never from the caller. One refund per source (unique).

## B3 additions

- Customer refund details: UPI ID or bank (account holder, account number, IFSC) validated (UPI
  format, IFSC `^[A-Z]{4}0[A-Z0-9]{6}$`), stored encrypted (text columns), masked in admin unless
  the admin has `refunds.manage`; never logged. Statuses: `not_collected`, `pending`, `paid` (admin
  records UTR), `failed` (reason) → customer updates details → retry. Customer and admin notified on
  paid/failed.
- Per-vertical settlement hold: `hold_until` for a delivered package = `delivered_at` + the longest
  return window among its items' verticals, not a fixed 7 days. Applies to new deliveries; existing
  settlements unchanged. The window used is stored on the settlement. A package with only
  non-returnable items uses the damaged/defective claim window (config, default 7).
- Permission `refunds.manage` (AdminPermissions, sync, RBAC datasets); activity log for refund
  status changes.

## B4 follow-up: courier calls outside transactions

Never call a courier inside a DB transaction. Reships and return pickups are created in one transaction
(stock reserved, shipment row in `booking_status = pending`, charges applied) and committed; the courier
is called afterwards with no locks held. Success → AWB/label saved (`booked`). Failure → `failed` with the
error; the admin can **Retry booking** (same shipment, nothing reserved or charged again; step-based
carriers like Shiprocket resume from the saved step) or **Cancel** (restock + reverse charge, item back to
the queue; a cancelled return pickup closes the return request).

## B5: return eligibility

- Eligibility uses the **return window saved at delivery** (on the package's settlement), never the
  vertical's current window: later edits to a vertical never change eligibility for orders already
  delivered. Deadline = delivery date + saved window; units delivered later by a reship count from the
  reship delivery date. (If a package has no settlement, the verticals' current windows are used.)
- Per unit: available = delivered − in returns (any request not rejected/closed) − not delivered.
- Non-returnable verticals only accept damaged, defective, wrong item or missing item/part.
- Window expired → blocked; an admin may create the request anyway (override, logged as
  `returns.window_override`).
- One active return per seller package; an open return holds the package's settlement (no release, no
  payout) until it is closed, rejected or resolved.
- Missing-item claims skip the pickup: approval reverses the seller's sale and fees for those units (in a
  separate `return` settlement, carried into the next payout) and hands them to the B4 refund/reship
  queue with `customer_paid = true` (refund pending, or reship collecting nothing).

## B6: received, QC, refund or replacement

- Return parcel delivered to the seller → request `received`; the seller submits a condition report
  (notes + up to 5 JPG/PNG/WebP photos, private disk, own packages, read-only) within
  `returns.return_report_days` (default 3, shown in India time). Missed → flagged; admin decides without it.
- Admin QC (returns.manage, confirmation, logged, once): **pass** → restock, then refund or replacement per
  the customer's choice; **fail** → ship back to the customer (seller pays), partial refund (≤ refundable) or
  full refund; no restock.
- Refund: B3 per-unit amount after coupon; COD → pending, paid manually with UTR; coupon units released.
- Settlement (separate `return` settlement per request, eligible, so a paid-out seller carries the negative):
  per-unit sale reversal under `return:{returnItem}:{type}:{item}` (partial refund = same share); fees by
  `returns.commission_on_return` (seller_fault keeps them; customer_remorse and courier_fault reverse them);
  return shipping = forward allocated shipping × `returns.return_shipping_multiplier`; replacement and
  ship-back shipping × `returns.replacement_shipping_multiplier`. Return hold released on resolution.
- Replacement: linked ₹0 seller order (same listing and delivery address snapshot, no fees) through the
  normal forward flow; stock reserved; out of stock / inactive → converted to refund (customer notified);
  a replacement can only be returned for a refund.
- Lost return pickup (after pickup): customer refunded in full; seller's sale not reversed; platform loss
  (`lost_return`) and courier claim recorded.
- Seller order → `partially_refunded` / `refunded` (parent rollup likewise).
- Emails: customer (QC result, converted to refund, replacement shipped, lost return, refund paid/failed);
  seller (return received, QC result).
- **Failed QC + refund (full or partial) = platform goodwill by default**: the seller's sale and fees are
  not reversed; a `platform_losses` row (`goodwill_refund`) records the refunded amount. Admin may
  explicitly choose fault = seller instead; then the seller reversal applies (partial = same share). The
  choice is logged (`fault_chosen_by_admin`, `goodwill_refund`). Return shipping, and ship-back shipping on
  a failed QC, stay charged to the seller.

## B7: screens

- Customer order page, per delivered package: "Return items" while the window is open (end shown in India
  time); units per line, reason, photos (required for claims), refund or replacement (hidden on replacement
  orders); errors from the service shown inline. Reply to "more info needed" with text + photos (max 5 total).
  Timeline: request status, reverse pickup events (courier's text, location, India time), refund (amount,
  status, UTR masked to last 4), replacement order link. UPI/bank form when a refund needs details or after
  a failed payout. Order/package show Partially Refunded / Refunded.
- Seller: "Returns" tab on orders (own packages only); package page shows returns, photos, report form,
  QC outcome.
- Admin: return override form on the order page (returns.manage; reason required, logged with the
  window override); returns queue filters (status, reason, seller, overdue seller report, one package via
  `?sellerOrder=`), inline photo thumbnails (viewing logged once per admin/request/hour), links from the
  order page and from return-pickup / ship-back shipment pages.

## Chunks

- B2: shipment direction/purpose/items; reverse lifecycle; Fake reverse toolbar. (done)
- B3: refund model + coupon-aware unit calculator + cumulative coupon release; refund details;
  per-vertical hold; `refunds.manage`.
- B4: undelivered-items queue (refund or reship), reship shipment, seller re-credit, reship COD.
- B5: customer return requests, reverse pickup via Fake/Ekart/Shiprocket drivers, return hold.
- B6: return received → condition report → QC decision → refund / per-unit reversal / return
  shipping fee / coupon release; replacement orders.
- B7: customer, seller and admin screens; permissions; activity log; manual checklist. (done)
