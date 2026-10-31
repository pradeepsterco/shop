<?php

namespace App\Helpdesk\Services;

use App\Helpdesk\Contracts\CustomerOrderLookupInterface;
use App\Helpdesk\Contracts\SellerOrderLookupInterface;
use App\Helpdesk\Enums\MessageAuthor;
use App\Helpdesk\Enums\RequesterType;
use App\Helpdesk\Enums\TicketCategory;
use App\Helpdesk\Enums\TicketPriority;
use App\Helpdesk\Enums\TicketStatus;
use App\Helpdesk\Models\SupportTicket;
use App\Helpdesk\Models\SupportTicketMessage;
use App\Mail\SupportTicketMail;
use App\Models\Admin;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\AdminActivityLogger;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Ticket lifecycle for requesters (customers and sellers) and admin staff.
 * Staff actions are written to the admin activity log here so every caller is covered.
 */
class HelpdeskService
{
    public function __construct(
        protected CustomerOrderLookupInterface $orders,
        protected SellerOrderLookupInterface $sellerOrders,
        protected AnonymityGuard $guard,
        protected AttachmentSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $attachments
     */
    public function openCustomerTicket(
        User $user,
        TicketCategory $category,
        string $subject,
        string $body,
        ?string $orderNumber = null,
        array $attachments = [],
    ): SupportTicket {
        [$subject, $body] = $this->validateNewTicket(RequesterType::Customer, $category, $subject, $body, $attachments);

        $order = null;
        if ($orderNumber !== null && $orderNumber !== '') {
            $order = $this->orders->findForCustomer((int) $user->id, $orderNumber);
            if (! $order) {
                throw new DomainException('We could not find that order on your account.');
            }
        }

        return $this->createTicket($user, [
            'requester_type' => RequesterType::Customer,
            'requester_name' => $user->name,
            'order_id' => $order?->orderId,
            'order_number' => $order?->orderNumber,
        ], $category, $subject, $body, $attachments);
    }

    /**
     * A ticket from the seller who owns $user's store, optionally about one of their packages.
     *
     * @param  array<int, UploadedFile>  $attachments
     */
    public function openSellerTicket(
        User $user,
        TicketCategory $category,
        string $subject,
        string $body,
        ?string $sellerOrderId = null,
        array $attachments = [],
    ): SupportTicket {
        $profile = $user->sellerProfile;
        if (! $profile) {
            throw new DomainException('Only sellers can open seller support requests.');
        }

        [$subject, $body] = $this->validateNewTicket(RequesterType::Seller, $category, $subject, $body, $attachments);

        $sellerOrder = null;
        if ($sellerOrderId !== null && $sellerOrderId !== '') {
            $sellerOrder = $this->sellerOrders->findForSeller((int) $profile->id, $sellerOrderId);
            if (! $sellerOrder) {
                throw new DomainException('We could not find that order in your store.');
            }
        }

        return $this->createTicket($user, [
            'requester_type' => RequesterType::Seller,
            'requester_name' => $profile->store_name,
            'seller_profile_id' => $profile->id,
            'order_id' => $sellerOrder?->orderId,
            'order_number' => $sellerOrder?->orderNumber,
            'seller_order_id' => $sellerOrder?->sellerOrderId,
        ], $category, $subject, $body, $attachments);
    }

    /**
     * @param  array<int, mixed>  $attachments
     * @return array{0: string, 1: string} trimmed subject and body
     */
    protected function validateNewTicket(RequesterType $type, TicketCategory $category, string $subject, string $body, array $attachments): array
    {
        if (! in_array($category, TicketCategory::for($type), true)) {
            throw new DomainException('Choose one of the listed topics.');
        }

        $subject = trim($subject);
        $body = trim($body);

        Validator::make(['subject' => $subject, 'body' => $body], [
            'subject' => ['required', 'string', 'min:'.config('helpdesk.subject_min'), 'max:'.config('helpdesk.subject_max')],
            'body' => ['required', 'string', 'min:'.config('helpdesk.body_min'), 'max:'.config('helpdesk.body_max')],
        ], [
            'subject.required' => 'Add a short subject.',
            'body.required' => 'Tell us what happened.',
        ])->validate();
        $this->validateAttachments($attachments);

        return [$subject, $body];
    }

    /**
     * Applies the per-requester limits, then creates the ticket with its first message.
     *
     * @param  array<string, mixed>  $requester  requester_type, requester_name and order/seller fields
     * @param  array<int, UploadedFile>  $attachments
     */
    protected function createTicket(User $user, array $requester, TicketCategory $category, string $subject, string $body, array $attachments): SupportTicket
    {
        // Limits count per requester type, so a seller's store tickets do not block their shopping account
        $mine = fn () => SupportTicket::query()->where('user_id', $user->id)->where('requester_type', $requester['requester_type']->value);

        $openCount = $mine()->notClosed()->count();
        if ($openCount >= (int) config('helpdesk.max_open_tickets')) {
            throw new DomainException('You already have '.$openCount.' open requests. Please reply on one of those, or close one you no longer need.');
        }

        if ($mine()->where('created_at', '>=', now()->subDay())->count() >= (int) config('helpdesk.max_new_tickets_per_day')) {
            throw new DomainException('You have opened too many requests today. Please try again tomorrow.');
        }

        $ticketId = (string) Str::ulid();
        $stored = $this->storeAttachments($ticketId, $attachments);

        try {
            $ticket = DB::transaction(function () use ($ticketId, $user, $requester, $category, $subject, $body, $stored) {
                $ticket = SupportTicket::create([
                    'id' => $ticketId,
                    'ticket_number' => $this->newTicketNumber(),
                    'user_id' => $user->id,
                    'requester_email' => $user->email,
                    'category' => $category,
                    'subject' => $subject,
                    'status' => TicketStatus::Open,
                    'priority' => TicketPriority::Normal,
                    'last_message_at' => now(),
                    'requester_activity_at' => now(),
                    ...$requester,
                ]);

                $ticket->messages()->create([
                    'author_type' => MessageAuthor::Requester,
                    'user_id' => $user->id,
                    'body' => $body,
                    'attachments' => $stored ?: null,
                ]);

                return $ticket;
            });
        } catch (Throwable $e) {
            $this->deleteStored($stored);

            throw $e;
        }

        $this->notifyRequester($ticket, 'received');

        return $ticket;
    }

    /**
     * A customer or seller reply. On a resolved ticket it must be an explicit reopen ("No, I still need help"),
     * so a "thanks" does not put a settled ticket back in the inbox.
     *
     * @param  array<int, UploadedFile>  $attachments
     */
    public function replyAsRequester(SupportTicket $ticket, User $user, string $body, array $attachments = [], bool $reopen = false): SupportTicketMessage
    {
        if (! $ticket->isOwnedBy((int) $user->id)) {
            throw new DomainException('Request not found.');
        }

        $body = $this->validateReply($body);
        $this->validateAttachments($attachments);
        $stored = $this->storeAttachments($ticket->id, $attachments);

        try {
            return DB::transaction(function () use ($ticket, $user, $body, $stored, $reopen) {
                $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
                if (! $locked->status->acceptsReplies()) {
                    throw new DomainException('This request is closed. Please open a new one.');
                }
                if ($locked->status === TicketStatus::Resolved) {
                    if (! $reopen) {
                        throw new DomainException('This request is resolved. Choose "No, I still need help" to reopen it.');
                    }
                    $this->systemNote($locked, 'Reopened by the '.$locked->requester_type->value.'.', internal: false);
                }

                $message = $locked->messages()->create([
                    'author_type' => MessageAuthor::Requester,
                    'user_id' => $user->id,
                    'body' => $body,
                    'attachments' => $stored ?: null,
                ]);

                // Any requester reply puts the ticket back with staff; a seller's reply also meets their deadline
                $locked->update([
                    'status' => TicketStatus::Open,
                    'resolved_at' => null,
                    'last_message_at' => now(),
                    'requester_activity_at' => now(),
                    'reply_due_at' => null,
                    'reminded_at' => null,
                ]);

                if ($locked->requester_type === RequesterType::Seller && $locked->linked_ticket_id) {
                    $this->flagSellerReply($locked);
                }

                return $message;
            });
        } catch (Throwable $e) {
            $this->deleteStored($stored);

            throw $e;
        }
    }

    public function closeByRequester(SupportTicket $ticket, User $user): SupportTicket
    {
        if (! $ticket->isOwnedBy((int) $user->id)) {
            throw new DomainException('Request not found.');
        }

        return DB::transaction(function () use ($ticket) {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            if ($locked->status === TicketStatus::Closed) {
                return $locked;
            }

            $confirmed = $locked->status === TicketStatus::Resolved;
            $this->applyStatus($locked, TicketStatus::Closed);
            $this->systemNote($locked, $confirmed
                ? 'The '.$locked->requester_type->value.' confirmed it is solved.'
                : 'Closed by the '.$locked->requester_type->value.'.', internal: false);

            if ($locked->requester_type === RequesterType::Customer) {
                // The customer is done, so the seller side of the case is too
                $this->closeSellerSide($locked);
            } elseif ($locked->linked_ticket_id) {
                // The seller stepped away from a case: staff need to see it
                $customerTicket = SupportTicket::query()->lockForUpdate()->find($locked->linked_ticket_id);
                if ($customerTicket && $customerTicket->status->acceptsReplies()) {
                    $customerTicket->forceFill(['requester_activity_at' => now()])->save();
                    $this->systemNote($customerTicket, "The seller closed {$locked->ticket_number}.", internal: true);
                }
            }

            return $locked;
        });
    }

    /**
     * Closes the seller side of a customer's case once the case is settled. The seller cannot reopen it;
     * if staff need the seller again they ask afresh.
     */
    protected function closeSellerSide(SupportTicket $customerTicket): void
    {
        if (! $customerTicket->linked_ticket_id) {
            return;
        }

        $sellerTicket = SupportTicket::query()->lockForUpdate()->find($customerTicket->linked_ticket_id);
        if (! $sellerTicket || $sellerTicket->status === TicketStatus::Closed) {
            return;
        }

        $sellerTicket->requester_unread = true;
        $sellerTicket->attention = null;
        $this->applyStatus($sellerTicket, TicketStatus::Closed);
        $this->systemNote($sellerTicket, 'Closed by support. Thank you for your help.', internal: false);
    }

    /**
     * A public reply (default: waiting on the customer afterwards) or an internal note (no status change, no email).
     *
     * @param  array<int, UploadedFile>  $attachments
     */
    public function replyAsStaff(
        SupportTicket $ticket,
        Admin $admin,
        string $body,
        array $attachments = [],
        bool $internal = false,
        ?TicketStatus $statusAfter = null,
    ): SupportTicketMessage {
        $body = $this->validateReply($body);
        $this->validateAttachments($attachments);
        $stored = $this->storeAttachments($ticket->id, $attachments);

        try {
            $message = DB::transaction(function () use ($ticket, $admin, $body, $stored, $internal, $statusAfter) {
                $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
                if (! $internal && ! $locked->status->acceptsReplies()) {
                    throw new DomainException('This ticket is closed. Reopen it before replying.');
                }

                return $this->recordStaffMessage($locked, $admin, $body, $stored, $internal, $statusAfter);
            });
        } catch (Throwable $e) {
            $this->deleteStored($stored);

            throw $e;
        }

        if (! $internal) {
            $ticket->refresh();
            $this->notifyRequester($ticket, $ticket->requester_type === RequesterType::Seller ? 'seller_action' : 'staff_reply');
        }

        return $message;
    }

    /**
     * Opens a seller ticket for one package of a customer ticket's order and links the two.
     * The seller sees only the marketplace: the text must not identify the customer, and only ticked attachments,
     * cleaned of metadata, are passed on.
     *
     * @param  list<string>  $attachmentRefs  "messageId:index" of the customer's attachments to pass on
     */
    public function askSeller(
        SupportTicket $customerTicket,
        Admin $admin,
        string $sellerOrderId,
        TicketCategory $category,
        string $subject,
        string $body,
        array $attachmentRefs = [],
    ): SupportTicket {
        if ($customerTicket->requester_type !== RequesterType::Customer || ! $customerTicket->order_id) {
            throw new DomainException('Only a customer ticket about an order can be passed to its seller.');
        }
        if ($customerTicket->linkedTicket && $customerTicket->linkedTicket->status !== TicketStatus::Closed) {
            throw new DomainException('This ticket is already linked to seller ticket '.$customerTicket->linkedTicket->ticket_number.'. Forward messages there instead.');
        }
        if (! $customerTicket->status->acceptsReplies()) {
            throw new DomainException('This ticket is closed. Reopen it first.');
        }
        if (! in_array($category, TicketCategory::forSellers(), true)) {
            throw new DomainException('Choose one of the seller topics.');
        }

        $sellerOrder = collect($this->sellerOrders->forOrder($customerTicket->order_id))->firstWhere('sellerOrderId', $sellerOrderId);
        $profile = $sellerOrder?->sellerProfileId ? SellerProfile::query()->with('user')->find($sellerOrder->sellerProfileId) : null;
        if (! $sellerOrder || ! $profile) {
            throw new DomainException('Choose one of the sellers in this order.');
        }

        $subject = trim($subject);
        $body = $this->validateReply($body);
        Validator::make(['subject' => $subject], [
            'subject' => ['required', 'string', 'min:'.config('helpdesk.subject_min'), 'max:'.config('helpdesk.subject_max')],
        ])->validate();

        $customerIds = AnonymityGuard::customerIdentifiers($customerTicket->requester_name, $customerTicket->requester_email, $customerTicket->ticket_number);
        $this->guard->assertClean($subject."\n".$body, $customerIds);

        $sellerTicketId = (string) Str::ulid();
        $stored = $this->copyAttachments($customerTicket, $attachmentRefs, $sellerTicketId);

        try {
            $sellerTicket = DB::transaction(function () use ($customerTicket, $admin, $sellerTicketId, $profile, $sellerOrder, $category, $subject, $body, $stored) {
                $sellerTicket = SupportTicket::create([
                    'id' => $sellerTicketId,
                    'ticket_number' => $this->newTicketNumber(),
                    'requester_type' => RequesterType::Seller,
                    'user_id' => $profile->user->id,
                    'seller_profile_id' => $profile->id,
                    'requester_name' => $profile->store_name,
                    'requester_email' => $profile->user->email,
                    'category' => $category,
                    'subject' => $subject,
                    'status' => TicketStatus::Pending,
                    'priority' => $customerTicket->priority,
                    'order_id' => $sellerOrder->orderId,
                    'order_number' => $sellerOrder->orderNumber,
                    'seller_order_id' => $sellerOrder->sellerOrderId,
                    'linked_ticket_id' => $customerTicket->id,
                    // One owner for both sides of the case
                    'assigned_admin_id' => $customerTicket->assigned_admin_id ?? $admin->id,
                    'last_message_at' => now(),
                    'first_response_at' => now(),
                    'reply_due_at' => now()->addHours((int) config('helpdesk.seller_reply_hours')),
                    'requester_unread' => true,
                ]);

                $sellerTicket->messages()->create([
                    'author_type' => MessageAuthor::Staff,
                    'admin_id' => $admin->id,
                    'body' => $body,
                    'attachments' => $stored ?: null,
                ]);

                $locked = SupportTicket::query()->lockForUpdate()->findOrFail($customerTicket->id);
                $locked->fill([
                    'linked_ticket_id' => $sellerTicket->id,
                    'attention' => null,
                    'assigned_admin_id' => $locked->assigned_admin_id ?? $admin->id,
                ]);
                $this->applyStatus($locked, TicketStatus::AwaitingSeller);

                $this->systemNote($locked, "Asked the seller ({$profile->store_name}) on {$sellerTicket->ticket_number}.", internal: true);
                $this->systemNote($sellerTicket, "Opened by {$admin->name} for customer ticket {$customerTicket->ticket_number}.", internal: true);

                AdminActivityLogger::log('support.asked_seller', $locked, [
                    'ticket_number' => $locked->ticket_number,
                    'seller_ticket_number' => $sellerTicket->ticket_number,
                    'seller_profile_id' => $profile->id,
                    'attachments' => count($stored),
                ], $admin);

                return $sellerTicket;
            });
        } catch (Throwable $e) {
            $this->deleteStored($stored);

            throw $e;
        }

        $this->notifyRequester($sellerTicket, 'seller_action');
        $customerTicket->refresh();

        return $sellerTicket;
    }

    /**
     * Passes a customer's or seller's message to the linked ticket as a staff message, after staff have edited it.
     * The text must not identify the sender; only ticked attachments, cleaned of metadata, go across.
     *
     * @param  list<int>  $attachmentIndexes  indexes into the message's attachments
     */
    public function forward(
        SupportTicketMessage $message,
        Admin $admin,
        string $body,
        array $attachmentIndexes = [],
        ?TicketStatus $statusAfter = null,
    ): SupportTicketMessage {
        $source = $message->ticket;
        $target = $source->linkedTicket;
        if ($message->is_internal || $message->author_type !== MessageAuthor::Requester) {
            throw new DomainException('Only customer and seller messages can be forwarded.');
        }
        if (! $target || ! $target->status->acceptsReplies()) {
            throw new DomainException('There is no open linked ticket to forward to.');
        }

        $body = $this->validateReply($body);
        $identifiers = $source->requester_type === RequesterType::Customer
            ? AnonymityGuard::customerIdentifiers($source->requester_name, $source->requester_email, $source->ticket_number)
            : AnonymityGuard::sellerIdentifiers($source->requester_name, $source->sellerProfile?->legal_name, $source->requester_email, $source->ticket_number);
        $this->guard->assertClean($body, $identifiers);

        $refs = array_map(fn (int $index) => $message->id.':'.$index, array_values(array_unique($attachmentIndexes)));
        $stored = $this->copyAttachments($source, $refs, $target->id);

        // Forwarding to the seller parks the customer's ticket; forwarding to the customer waits on them
        $toSeller = $target->requester_type === RequesterType::Seller;

        try {
            $forwarded = DB::transaction(function () use ($source, $target, $admin, $body, $stored, $statusAfter, $toSeller) {
                $lockedTarget = SupportTicket::query()->lockForUpdate()->findOrFail($target->id);
                $forwarded = $this->recordStaffMessage($lockedTarget, $admin, $body, $stored, false, $toSeller ? TicketStatus::Pending : $statusAfter);

                $lockedSource = SupportTicket::query()->lockForUpdate()->findOrFail($source->id);
                if ($toSeller && $lockedSource->status->acceptsReplies()) {
                    $lockedSource->attention = null;
                    $this->applyStatus($lockedSource, TicketStatus::AwaitingSeller);
                } elseif (! $toSeller && $lockedSource->status === TicketStatus::Open) {
                    // The seller's reply has been passed on: nothing waits on staff or the seller any more
                    $this->applyStatus($lockedSource, TicketStatus::Answered);
                }
                $this->systemNote($lockedSource, "Forwarded to {$lockedTarget->ticket_number} by {$admin->name}.", internal: true);

                return $forwarded;
            });
        } catch (Throwable $e) {
            $this->deleteStored($stored);

            throw $e;
        }

        $target->refresh();
        $this->notifyRequester($target, $toSeller ? 'seller_action' : 'staff_reply');

        return $forwarded;
    }

    /**
     * Emails one reminder to sellers whose reply is due within the reminder window.
     */
    public function remindSellers(): int
    {
        $reminded = 0;

        SupportTicket::query()
            ->where('requester_type', RequesterType::Seller->value)
            ->where('status', TicketStatus::Pending->value)
            ->whereNull('reminded_at')
            ->whereNotNull('reply_due_at')
            ->where('reply_due_at', '>', now())
            ->where('reply_due_at', '<=', now()->addHours((int) config('helpdesk.seller_reminder_hours_left')))
            ->orderBy('id')
            ->each(function (SupportTicket $ticket) use (&$reminded) {
                $ticket->update(['reminded_at' => now()]);
                $this->notifyRequester($ticket, 'seller_reminder');
                $reminded++;
            });

        return $reminded;
    }

    /**
     * The customer or seller opened their ticket.
     */
    public function markReadByRequester(SupportTicket $ticket): void
    {
        if ($ticket->requester_unread) {
            $ticket->forceFill(['requester_unread' => false])->saveQuietly();
        }
    }

    /**
     * A staff member opened a case: both its tickets count as read for them.
     */
    public function markReadByStaff(SupportTicket $ticket, int $adminId): void
    {
        $now = now();
        $rows = array_map(fn (string $ticketId) => [
            'support_ticket_id' => $ticketId,
            'admin_id' => $adminId,
            'read_at' => $now,
        ], array_values(array_filter([$ticket->id, $ticket->linked_ticket_id])));

        DB::table('support_ticket_reads')->upsert($rows, ['support_ticket_id', 'admin_id'], ['read_at']);
    }

    /**
     * Tickets of this type with support replies the user has not opened.
     */
    public function unreadCountForRequester(int $userId, RequesterType $type): int
    {
        return SupportTicket::query()
            ->where('user_id', $userId)
            ->where('requester_type', $type->value)
            ->where('requester_unread', true)
            ->count();
    }

    /**
     * Open cases, mine or unassigned, with customer or seller activity this staff member has not opened.
     */
    public function unreadCountForStaff(int $adminId): int
    {
        return SupportTicket::query()->staffBadge($adminId)->count();
    }

    /**
     * A seller replied on a linked ticket: bring the customer's ticket back to staff and mark it.
     */
    protected function flagSellerReply(SupportTicket $sellerTicket): void
    {
        $customerTicket = SupportTicket::query()->lockForUpdate()->find($sellerTicket->linked_ticket_id);
        if (! $customerTicket || ! $customerTicket->status->acceptsReplies()) {
            return;
        }

        $customerTicket->attention = 'seller_replied';
        // The case has new activity for staff, even though it came in on the seller side
        $customerTicket->requester_activity_at = now();
        if ($customerTicket->status === TicketStatus::AwaitingSeller) {
            $this->applyStatus($customerTicket, TicketStatus::Open);
        } else {
            $customerTicket->save();
        }

        $this->systemNote($customerTicket, "The seller replied on {$sellerTicket->ticket_number}.", internal: true);
    }

    /**
     * @param  list<array{path: string, name: string, mime: string, size: int}>  $stored
     */
    protected function recordStaffMessage(SupportTicket $locked, Admin $admin, string $body, array $stored, bool $internal, ?TicketStatus $statusAfter): SupportTicketMessage
    {
        $message = $locked->messages()->create([
            'author_type' => MessageAuthor::Staff,
            'admin_id' => $admin->id,
            'body' => $body,
            'is_internal' => $internal,
            'attachments' => $stored ?: null,
        ]);

        if (! $internal) {
            $changes = ['last_message_at' => now(), 'attention' => null, 'requester_unread' => true];
            if ($locked->first_response_at === null) {
                $changes['first_response_at'] = now();
            }
            if ($locked->assigned_admin_id === null) {
                $changes['assigned_admin_id'] = $admin->id;
            }
            $locked->fill($changes);
            $this->applyStatus($locked, $statusAfter ?? TicketStatus::Pending);

            // Each staff message to a seller (re)starts their reply deadline
            if ($locked->requester_type === RequesterType::Seller && $locked->status === TicketStatus::Pending) {
                $locked->update([
                    'reply_due_at' => now()->addHours((int) config('helpdesk.seller_reply_hours')),
                    'reminded_at' => null,
                ]);
            }
        }

        AdminActivityLogger::log($internal ? 'support.note_added' : 'support.replied', $locked, [
            'ticket_number' => $locked->ticket_number,
            'message_id' => $message->id,
            'status' => $locked->status->value,
        ], $admin);

        return $message;
    }

    /**
     * Copies ticked attachments ("messageId:index") from the ticket's own requester messages, cleaned of metadata.
     *
     * @param  list<string>  $refs
     * @return list<array{path: string, name: string, mime: string, size: int}>
     */
    protected function copyAttachments(SupportTicket $source, array $refs, string $targetTicketId): array
    {
        $stored = [];

        try {
            foreach (array_values(array_unique($refs)) as $position => $ref) {
                [$messageId, $index] = array_map('intval', explode(':', $ref) + [1 => -1]);
                $message = $source->messages()
                    ->whereKey($messageId)
                    ->where('author_type', MessageAuthor::Requester->value)
                    ->where('is_internal', false)
                    ->first();
                $file = $message ? ($message->attachments ?? [])[$index] ?? null : null;
                if (! is_array($file)) {
                    throw new DomainException('One of the ticked attachments could not be found.');
                }

                $stored[] = $this->sanitizer->copy($file, $targetTicketId, $position + 1);
            }
        } catch (Throwable $e) {
            $this->deleteStored($stored);

            throw $e;
        }

        return $stored;
    }

    public function setStatus(SupportTicket $ticket, Admin $admin, TicketStatus $status): SupportTicket
    {
        $previous = $ticket->status;
        if ($previous === $status) {
            return $ticket;
        }
        if ($status === TicketStatus::AwaitingSeller && ($ticket->requester_type !== RequesterType::Customer || ! $ticket->linked_ticket_id)) {
            throw new DomainException('Only a customer ticket linked to a seller ticket can wait on the seller.');
        }
        if ($status === TicketStatus::Answered && $ticket->requester_type !== RequesterType::Seller) {
            throw new DomainException('Only a seller ticket can be marked answered.');
        }

        DB::transaction(function () use ($ticket, $admin, $status, $previous) {
            $this->changeStatus($ticket, $admin, $status, $previous);

            // Resolving or closing the customer's case closes its seller side: the seller has nothing left to do
            if (in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true) && $ticket->requester_type === RequesterType::Customer) {
                $this->closeSellerSide($ticket);
            }
        });

        if ($status === TicketStatus::Resolved) {
            $this->notifyRequester($ticket, 'resolved');
        }

        return $ticket;
    }

    /**
     * Resolves tickets that have waited on the customer (or a seller's own ticket on the seller) with no reply for the
     * configured days. They can still reopen it until it auto-closes. Seller sides of cases are left to their deadline.
     */
    public function resolveUnanswered(?int $days = null): int
    {
        $days ??= (int) config('helpdesk.pending_auto_resolve_days');
        $resolved = 0;

        SupportTicket::query()
            ->caseRows()
            ->where('status', TicketStatus::Pending->value)
            ->where('last_message_at', '<=', now()->subDays($days))
            ->orderBy('id')
            ->each(function (SupportTicket $ticket) use ($days, &$resolved) {
                DB::transaction(function () use ($ticket, $days) {
                    $ticket->requester_unread = true;
                    $this->applyStatus($ticket, TicketStatus::Resolved);
                    $this->systemNote($ticket, "Resolved because we did not hear back for {$days} days. If you still need help, reopen it within ".config('helpdesk.auto_close_resolved_days').' days.', internal: false);
                    if ($ticket->requester_type === RequesterType::Customer) {
                        $this->closeSellerSide($ticket);
                    }
                });
                $this->notifyRequester($ticket, 'resolved');
                $resolved++;
            });

        return $resolved;
    }

    protected function changeStatus(SupportTicket $ticket, Admin $admin, TicketStatus $status, TicketStatus $previous): void
    {
        $note = match ($status) {
            TicketStatus::Resolved => 'Marked as resolved by support.',
            TicketStatus::Closed => 'Closed by support.',
            TicketStatus::Open => $previous === TicketStatus::Closed ? 'Reopened by support.' : null,
            TicketStatus::Pending, TicketStatus::AwaitingSeller, TicketStatus::Answered => null,
        };

        $ticket->attention = null;
        if ($note) {
            // The requester sees this note in their thread
            $ticket->requester_unread = true;
        }
        $this->applyStatus($ticket, $status);

        if ($note) {
            $this->systemNote($ticket, $note, internal: false);
        }

        AdminActivityLogger::log('support.status_changed', $ticket, [
            'ticket_number' => $ticket->ticket_number,
            'from' => $previous->value,
            'to' => $status->value,
        ], $admin);
    }

    public function setPriority(SupportTicket $ticket, Admin $admin, TicketPriority $priority): SupportTicket
    {
        $previous = $ticket->priority;
        if ($previous === $priority) {
            return $ticket;
        }

        $ticket->update(['priority' => $priority]);

        AdminActivityLogger::log('support.priority_changed', $ticket, [
            'ticket_number' => $ticket->ticket_number,
            'from' => $previous->value,
            'to' => $priority->value,
        ], $admin);

        return $ticket;
    }

    /**
     * Assign to an active staff member, or unassign with null. A case's linked ticket follows, so one person owns both sides.
     */
    public function assign(SupportTicket $ticket, Admin $actor, ?int $assigneeId): SupportTicket
    {
        $assignee = null;
        if ($assigneeId !== null) {
            $assignee = Admin::query()->where('is_active', true)->find($assigneeId);
            if (! $assignee) {
                throw new DomainException('That staff member is not available.');
            }
        }

        $tickets = collect([$ticket]);
        if ($ticket->linked_ticket_id && ($linked = SupportTicket::query()->find($ticket->linked_ticket_id))) {
            $tickets->push($linked);
        }
        $tickets = $tickets->reject(fn (SupportTicket $t) => (int) $t->assigned_admin_id === (int) $assignee?->id);

        if ($tickets->isEmpty()) {
            return $ticket;
        }

        DB::transaction(function () use ($tickets, $actor, $assignee) {
            foreach ($tickets as $t) {
                $previousId = $t->assigned_admin_id;
                $t->update(['assigned_admin_id' => $assignee?->id]);

                $this->systemNote($t, $assignee
                    ? "Assigned to {$assignee->name} by {$actor->name}."
                    : "Unassigned by {$actor->name}.", internal: true);

                AdminActivityLogger::log('support.assigned', $t, [
                    'ticket_number' => $t->ticket_number,
                    'from_admin_id' => $previousId,
                    'to_admin_id' => $assignee?->id,
                ], $actor);
            }
        });

        return $ticket->refresh()->load('assignee');
    }

    /**
     * Close resolved tickets with no activity for the configured number of days.
     */
    public function closeStaleResolved(?int $days = null): int
    {
        $days ??= (int) config('helpdesk.auto_close_resolved_days');
        $closed = 0;

        SupportTicket::query()
            ->where('status', TicketStatus::Resolved->value)
            ->where('last_message_at', '<=', now()->subDays($days))
            ->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '<=', now()->subDays($days)))
            ->orderBy('id')
            ->each(function (SupportTicket $ticket) use ($days, &$closed) {
                DB::transaction(function () use ($ticket, $days) {
                    $this->applyStatus($ticket, TicketStatus::Closed);
                    $this->systemNote($ticket, "Closed automatically after {$days} days without a reply.", internal: false);
                });
                $closed++;
            });

        return $closed;
    }

    /**
     * Sets the status with its timestamps and saves the ticket.
     */
    protected function applyStatus(SupportTicket $ticket, TicketStatus $status): void
    {
        $ticket->status = $status;
        $ticket->resolved_at = $status === TicketStatus::Resolved ? now() : ($status === TicketStatus::Closed ? $ticket->resolved_at : null);
        $ticket->closed_at = $status === TicketStatus::Closed ? now() : null;
        // A seller deadline only runs while the seller is the one being waited on
        if ($status !== TicketStatus::Pending) {
            $ticket->reply_due_at = null;
            $ticket->reminded_at = null;
        }
        $ticket->save();
    }

    protected function systemNote(SupportTicket $ticket, string $body, bool $internal): void
    {
        $ticket->messages()->create([
            'author_type' => MessageAuthor::System,
            'body' => $body,
            'is_internal' => $internal,
        ]);
    }

    protected function validateReply(string $body): string
    {
        $body = trim($body);

        Validator::make(['body' => $body], [
            'body' => ['required', 'string', 'max:'.config('helpdesk.body_max')],
        ], [
            'body.required' => 'Write a message first.',
        ])->validate();

        return $body;
    }

    /**
     * @param  array<int, mixed>  $attachments
     */
    protected function validateAttachments(array $attachments): void
    {
        Validator::make(['attachments' => $attachments], [
            'attachments' => ['array', 'max:'.config('helpdesk.max_attachments')],
            'attachments.*' => ['file', 'mimes:'.implode(',', config('helpdesk.attachment_mimes')), 'max:'.config('helpdesk.attachment_max_kb')],
        ], [
            'attachments.max' => 'You can attach up to '.config('helpdesk.max_attachments').' files.',
            'attachments.*.mimes' => 'Attach images (JPG, PNG, WEBP) or PDF files only.',
            'attachments.*.max' => 'Each file must be '.((int) config('helpdesk.attachment_max_kb') / 1024).' MB or smaller.',
        ])->validate();
    }

    /**
     * @param  array<int, UploadedFile>  $attachments
     * @return list<array{path: string, name: string, mime: string, size: int}>
     */
    protected function storeAttachments(string $ticketId, array $attachments): array
    {
        $stored = [];
        foreach ($attachments as $file) {
            $path = $file->store("support_attachments/{$ticketId}", 'local');
            if ($path === false) {
                $this->deleteStored($stored);

                throw new RuntimeException('Could not save the attachment. Please try again.');
            }

            $stored[] = [
                'path' => $path,
                'name' => Str::limit(basename($file->getClientOriginalName()), 120, ''),
                'mime' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
            ];
        }

        return $stored;
    }

    /**
     * @param  list<array{path: string, name: string, mime: string, size: int}>  $stored
     */
    protected function deleteStored(array $stored): void
    {
        if ($stored) {
            Storage::disk('local')->delete(array_column($stored, 'path'));
        }
    }

    protected function newTicketNumber(): string
    {
        do {
            $number = 'SUP-'.now()->format('ymd').'-'.Str::upper(Str::random(4));
        } while (SupportTicket::query()->where('ticket_number', $number)->exists());

        return $number;
    }

    protected function notifyRequester(SupportTicket $ticket, string $event): void
    {
        if ($ticket->requester_email === '') {
            return;
        }

        DB::afterCommit(fn () => Mail::to($ticket->requester_email)->queue(new SupportTicketMail($ticket, $event)));
    }
}
