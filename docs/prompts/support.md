# Support (helpdesk) spec

In-house ticketing for customers (phase 1) and sellers (phase 2), worked by admin staff from one inbox.
Outside tools (live chat, WhatsApp) may feed tickets later (phase 3); the ticket tables stay the source of truth.

Module: `App\Helpdesk` (`App\Support` is already the helpers namespace).

## Phases

- **Phase 1 (merged)**: customer tickets, admin inbox, emails, auto-close.
- **Phase 2 (branch `support-phase-2`)**: seller tickets on the same tables (`requester_type = seller`), seller panel pages, seller categories, customer/seller filter in the inbox.
- **Phase 3 (optional)**: canned replies, SLA timers and reports, outside channels feeding tickets.

## Data

`support_tickets` (ULID id)
- `ticket_number` unique, e.g. `SUP-261030-4K7Q`; shown to requesters and used in URLs.
- `requester_type` (`customer` or `seller`), `user_id` (null on account deletion), `requester_name` (customer name or store name), `requester_email` snapshots.
- `seller_profile_id` for seller tickets (null on profile deletion).
- `category`, `subject`, `status`, `priority`.
- `order_id` / `order_number`: optional, must belong to the requester. Seller tickets also store `seller_order_id` (the seller's package). Looked up through `App\Helpdesk\Contracts\CustomerOrderLookupInterface` and `SellerOrderLookupInterface`, implemented in `App\Orders\Adapters` (Helpdesk never touches Orders models).
- `assigned_admin_id` (null on admin delete).
- `last_message_at`, `first_response_at`, `resolved_at`, `closed_at`.

`support_ticket_messages`
- `author_type`: `requester`, `staff`, `system`; `user_id` / `admin_id`.
- `body`, `is_internal` (staff notes and system notes requesters never see), `attachments` (paths on the private `local` disk).

## Statuses

| Status | Meaning | Who moves it here |
|---|---|---|
| `open` | Waiting on staff | New ticket; any requester reply (reopens pending/resolved) |
| `pending` | Waiting on the requester | Staff public reply (default) |
| `resolved` | Staff thinks it is done; requester can still reply to reopen | Staff |
| `closed` | Final, no more replies | Requester closes; staff closes; auto-close of resolved after 7 days |

Internal notes never change status. Closed tickets cannot be replied to; the requester opens a new one.

## Categories

- Customer: Order issue, Delivery & tracking, Payment & refund, Return & replacement, Product question, Account & login, Other.
- Seller: Orders & shipping, Returns/RTO & claims, Payouts & settlements, Fees & commission, Listings & catalog, KYC/bank & store account, Other.

A ticket's category must be one of its requester type's categories.

## Priority

Low, Normal (default), High, Urgent. Staff only.

## Rules

- At most 5 non-closed tickets and 10 new tickets per day, per requester type (a seller's store tickets do not count against their shopping account).
- Up to 3 attachments per message: jpg, jpeg, png, webp, pdf; 5 MB each.
- Subject 5–150 chars; first message 10–5000 chars; replies up to 5000 chars.
- First public staff reply sets `first_response_at` and, if the ticket is unassigned, assigns it to that staff member.
- Customer pages only show customer tickets and seller pages only seller tickets, even for a user who is both.

## Permissions

- `support.view`: see the inbox and tickets.
- `support.reply`: reply, add internal notes, change status and priority, take an unassigned ticket.
- `support.assign`: assign any ticket to any active staff member.

All admin changes go to the activity log.

## Customer pages (`layouts.app`)

- `/support`: my tickets, newest activity first, "waiting on you" highlighted.
- `/support/new`: category, optional order (recent orders; `?order=ORDER_NUMBER` preselects), subject, message, attachments.
- `/support/{ticket_number}`: thread (no internal notes), reply with attachments, close.
- Links: customer sidebar "Help & Support", order page "Need help with this order?".

## Seller pages (`layouts.seller`)

Open to approved and suspended sellers (same middleware as orders and payments; suspended sellers often need support most).

- `/seller/support`, `/seller/support/new` (`?order=SELLER_ORDER_ID` preselects a package), `/seller/support/{ticket_number}`: same behaviour as the customer pages.
- Links: seller sidebar "Support", seller order page "Need help with this order?".

## Admin pages

- `/admin/support`: inbox. Filters: status (default "needs reply" = open), assignee (all / mine / unassigned), customers / sellers, category, priority, search (ticket number, subject, requester or store name, email, order number). Counts per status. Seller tickets carry a "Seller" badge.
- `/admin/support/{ticket_number}`: thread with internal notes, reply box (public reply or internal note, status after reply), side panel with requester (customer link, or seller link and store status), order link, status, priority, assignee.
- Sidebar "Support" with a badge for open unassigned tickets.

## Emails (queued, to the requester)

- Ticket received (acknowledgement with ticket number).
- New staff reply (no reply body in email, link to the ticket).
- Ticket resolved.

Links go to the storefront account for customers and the seller panel for sellers.

## Scheduled

`helpdesk:close-resolved` daily: resolved tickets with no activity for 7 days become closed.

---

# Phase 3: Indian e-commerce rules, Flipkart-style (branch `support-phase-3`)

Basis (to be confirmed by the company's CA or lawyer before launch): Consumer Protection (E-Commerce) Rules, 2020 (seller details shown to customers, grievance officer, acknowledge in 48 hours, redress in one month); GST (seller issues the invoice); DPDP Rules, 2025 (data minimisation; core duties apply about 18 months after November 2025).

Principle: in support, customers and sellers only ever deal with the marketplace. Outside support, customers see the seller's business details (legally required) and sellers see only what shipping needs.

## C1: Grievance officer and customer phone

- `helpdesk_settings` (single row): grievance officer name, designation, email, phone, address, working hours. Admin page `/admin/support/settings`, permission `support.settings`.
- Public page `/grievance-officer`: officer details, how to complain (Help & Support or email), the 48-hour acknowledgement and one-month resolution commitments. Linked from the storefront footer and the Help & Support page.
- Seller order page no longer shows the customer's phone or alternate phone (the courier gets them through the shipping integration). Name and delivery address stay for the label. The customer's email is never shown to sellers.

## C2: Linked-ticket relay (anonymous both ways)

- `support_tickets.linked_ticket_id` pairs one customer ticket with one seller ticket.
- **Ask the seller** (admin, `support.reply`): from a customer ticket linked to an order, staff pick the seller's package, write the message and tick which of the customer's attachments to pass on. This creates a seller ticket opened by staff (first message from "{brand} Support"), status `pending`, and emails the seller. The customer ticket moves to the new status **`awaiting_seller`** (not counted as "needs reply"; customer sees "We are checking with the seller").
- **Forward** (admin, `support.reply`): any customer or seller message in a linked ticket can be forwarded to the other ticket as a staff message. Staff edit the text first and tick each attachment to include.
- **Anonymity checks, enforced in code** before anything crosses: forwarded or "ask the seller" text is rejected if it contains an email address, a phone number, a UPI ID, the other side's name or store name, or the other side's ticket number. Image attachments are re-encoded to strip metadata (location, device); PDFs pass only when ticked.
- Seller tickets never contain customer identity; customer tickets never mention the store name in staff text (checked as above). Both sides only see "{brand} Support".
- **Seller replies:** a seller reply on a linked ticket returns the customer ticket from `awaiting_seller` to `open` and marks it "Seller replied" in the inbox until staff reply or change its status.
- **Seller reply deadline:** each staff message to a seller sets `reply_due_at` (48 hours, `helpdesk.seller_reply_hours`). `helpdesk:remind-sellers` (hourly) emails one reminder when 24 hours are left. Overdue seller tickets and their customer tickets show "Seller overdue" in the inbox.
- Admin ticket page shows the linked ticket (number, status, due time, link) in the side panel.

## C3: Seller details for customers

- `seller_profiles.legal_name` (registered business name) and `declaration_accepted_at`. Onboarding asks for the legal name and requires the declaration that all details are true. Existing sellers see a banner in the seller panel to accept the declaration.
- Public seller page `/sellers/{store_slug}`: store name, legal business name, registered address (pickup address), GSTIN, rating from approved product reviews, member since. Customer care points to the marketplace's Help & Support (sellers are contacted only through the marketplace).
- "Sold by" on product, cart and order pages links to it.

## C4: Complaint timelines

- Inbox shows each unresolved ticket's age; warning from day 25, "Overdue" after 30 days; filter "Over 30 days".
- Report `/admin/support/report` (per month): tickets received, acknowledged within 48 hours (the automatic email counts as acknowledgement), resolved within 30 days, still open past 30 days, median first staff response.

## Case view (one row per problem)

A customer ticket and the seller ticket staff opened from it stay two tickets in the database (separate numbers, conversations, statuses and emails keep each side anonymous), but admin treats them as one **case**:

- Inbox: one row per case (the customer ticket), with a "Seller" line showing the store, the seller side's status and ticket number. Linked seller tickets have no row of their own and are not counted separately. Seller tickets the seller opened themselves keep their own rows. Search by the seller ticket number or store name finds the case.
- Ticket page: case header (customer's subject, order, owner, status) and **Customer / Seller** tabs, each with its own conversation, reply box, status and deadline. Opening a linked seller ticket's address redirects to the case on the Seller tab (`?side=seller`).
- New seller-ticket status **`answered`**: set when staff forward the seller's reply to the customer, so the seller side stops showing as needing a reply.
- Resolving or closing the customer ticket resolves or closes the seller side too; assigning either side assigns both.

## Unread badges

- **Customers and sellers:** `support_tickets.requester_unread` is set by anything support posts that they can see (public staff replies, forwarded messages, a new seller ticket from "Ask the seller", resolved/closed/reopened notes) and cleared when they open the ticket. Internal notes never set it. Badge on "Help & Support" (customer sidebar) and "Support" (seller sidebar); "New reply" on list rows. Customer and seller counts are separate for a user who is both.
- **Staff, per person:** `support_ticket_reads` records when each staff member last opened a ticket; `support_tickets.requester_activity_at` is the last time the customer wrote (or, for a case, the seller on the linked ticket). Unread = activity after my last read. Opening a case on either tab reads both sides. Admin sidebar "Support" badge counts open cases that are unread by me and assigned to me or unassigned; the inbox marks unread rows, shows "Unread: N" and has an "Unread by me" filter.

## Resolving and closing

| Step | What happens |
|---|---|
| Staff resolve (status, or reply with "then Resolved") | Customer gets the "resolved" email and a choice on the ticket page: **Yes, it is solved** (closes it) or **No, I still need help** (reopens it; a message saying what is still wrong is required). A plain reply is not accepted on a resolved ticket, so a "thanks" never reopens it. |
| Nobody answers a resolved ticket | `helpdesk:close-resolved` closes it 7 days after it was resolved. The page shows the date. |
| Ticket waits on the customer (or on a seller's own ticket) with no reply | `helpdesk:resolve-unanswered` resolves it after 7 days (`helpdesk.pending_auto_resolve_days`), with a note and the resolved email; it can still be reopened until it auto-closes. Seller sides of cases are excluded; their 48-hour deadline applies instead. |
| Customer closes ("My issue is sorted" or "Yes, it is solved") | Ticket closes. If it is a case, the seller side closes too. |
| Seller closes their side of a case | Seller side closes; the customer's case is marked unread for staff with an internal note. |
| Staff resolve or close a case | The seller side is **closed** (not resolved): the seller has nothing left to do and cannot reopen it. If the customer reopens and staff need the seller again, they use "Ask the seller" again, which opens a new seller ticket. |
| Closed | Final for customers and sellers; they open a new request. Staff can reopen it. |

The same rules apply to sellers' own tickets (payouts, KYC and so on).

## Checks outside support (findings, 2026-10-30)

- **Cancellation charges:** none exist; customers cancel free while the package allows it. Nothing to change.
- **Country of origin:** not stored or shown anywhere. The rules (and the Legal Metrology (Packaged Commodities) Rules for packaged goods) expect it on the product page, with manufacturer/packer details, net quantity and similar declarations. This is catalog work, not support; to be done separately.

## Before launch

- Fill in the grievance officer at `/admin/support/settings` (the public page says "coming shortly" until then).
- Ask existing sellers to confirm their business name and declaration (dashboard banner).
- Have the CA or lawyer review: the seller details page contents (registered address, GSTIN; whether a seller customer-care number must also be shown), the grievance page wording, and the 48-hour / one-month process.
