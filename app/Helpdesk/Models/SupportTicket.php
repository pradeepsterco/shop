<?php

namespace App\Helpdesk\Models;

use App\Helpdesk\Enums\RequesterType;
use App\Helpdesk\Enums\TicketCategory;
use App\Helpdesk\Enums\TicketPriority;
use App\Helpdesk\Enums\TicketStatus;
use App\Models\Admin;
use App\Models\SellerProfile;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $ticket_number
 * @property RequesterType $requester_type
 * @property int|null $user_id
 * @property int|null $seller_profile_id
 * @property string $requester_name
 * @property string $requester_email
 * @property TicketCategory $category
 * @property string $subject
 * @property TicketStatus $status
 * @property TicketPriority $priority
 * @property string|null $order_id
 * @property string|null $order_number
 * @property string|null $seller_order_id
 * @property string|null $linked_ticket_id
 * @property string|null $attention
 * @property bool $requester_unread
 * @property CarbonInterface|null $requester_activity_at
 * @property int|null $assigned_admin_id
 * @property CarbonInterface|null $last_message_at
 * @property CarbonInterface|null $reply_due_at
 * @property CarbonInterface|null $reminded_at
 * @property CarbonInterface|null $first_response_at
 * @property CarbonInterface|null $resolved_at
 * @property CarbonInterface|null $closed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User|null $user
 * @property-read SellerProfile|null $sellerProfile
 * @property-read Admin|null $assignee
 * @property-read SupportTicket|null $linkedTicket
 * @property-read Collection<int, SupportTicketMessage> $messages
 */
class SupportTicket extends Model
{
    use HasUlids;

    protected $table = 'support_tickets';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requester_type' => RequesterType::class,
            'category' => TicketCategory::class,
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'last_message_at' => 'datetime',
            'reply_due_at' => 'datetime',
            'requester_unread' => 'boolean',
            'requester_activity_at' => 'datetime',
            'reminded_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<SellerProfile, $this>
     */
    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }

    /**
     * The other side of a customer/seller pair.
     *
     * @return BelongsTo<SupportTicket, $this>
     */
    public function linkedTicket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'linked_ticket_id');
    }

    /**
     * A seller ticket whose reply deadline has passed while waiting on the seller.
     */
    public function isSellerOverdue(): bool
    {
        return $this->requester_type === RequesterType::Seller
            && $this->status === TicketStatus::Pending
            && $this->reply_due_at !== null
            && $this->reply_due_at->isPast();
    }

    /**
     * @return HasMany<SupportTicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'support_ticket_id')->orderBy('id');
    }

    /**
     * @param  Builder<SupportTicket>  $query
     */
    public function scopeNotClosed(Builder $query): void
    {
        $query->where('status', '!=', TicketStatus::Closed->value);
    }

    /**
     * One inbox row per case: seller tickets opened from a customer ticket are shown inside that customer's row.
     *
     * @param  Builder<SupportTicket>  $query
     */
    public function scopeCaseRows(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('requester_type', '!=', RequesterType::Seller->value)
            ->orWhereNull('linked_ticket_id'));
    }

    /**
     * Tickets with customer or seller activity this staff member has not opened since.
     *
     * @param  Builder<SupportTicket>  $query
     */
    public function scopeUnreadByStaff(Builder $query, int $adminId): void
    {
        $query->whereNotNull('requester_activity_at')
            ->whereNotExists(fn ($reads) => $reads
                ->selectRaw('1')
                ->from('support_ticket_reads')
                ->whereColumn('support_ticket_reads.support_ticket_id', 'support_tickets.id')
                ->where('support_ticket_reads.admin_id', $adminId)
                ->whereColumn('support_ticket_reads.read_at', '>=', 'support_tickets.requester_activity_at'));
    }

    /**
     * Staff badge: unread, still open, and mine or nobody's.
     *
     * @param  Builder<SupportTicket>  $query
     */
    public function scopeStaffBadge(Builder $query, int $adminId): void
    {
        $query->caseRows()
            ->notClosed()
            ->unreadByStaff($adminId)
            ->where(fn (Builder $q) => $q->whereNull('assigned_admin_id')->orWhere('assigned_admin_id', $adminId));
    }

    /**
     * A seller ticket that is the seller side of a customer's case.
     */
    public function isSellerSideOfCase(): bool
    {
        return $this->requester_type === RequesterType::Seller && $this->linked_ticket_id !== null;
    }

    /**
     * Not yet resolved or closed: still counts against the one-month resolution commitment.
     *
     * @param  Builder<SupportTicket>  $query
     */
    public function scopeUnresolved(Builder $query): void
    {
        $query->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value]);
    }

    /**
     * When a resolved ticket closes by itself if nobody reopens it.
     */
    public function autoClosesAt(): ?CarbonInterface
    {
        if ($this->status !== TicketStatus::Resolved) {
            return null;
        }

        $from = $this->resolved_at && $this->last_message_at
            ? ($this->resolved_at->greaterThan($this->last_message_at) ? $this->resolved_at : $this->last_message_at)
            : ($this->resolved_at ?? $this->last_message_at);

        return $from?->copy()->addDays((int) config('helpdesk.auto_close_resolved_days'));
    }

    /**
     * "overdue" past helpdesk.resolve_within_days, "warning" from helpdesk.warn_after_days, else null.
     */
    public function resolutionDeadlineState(): ?string
    {
        if (in_array($this->status, [TicketStatus::Resolved, TicketStatus::Closed], true) || ! $this->created_at) {
            return null;
        }

        $age = $this->created_at->diffInDays(now());

        return match (true) {
            $age >= (int) config('helpdesk.resolve_within_days') => 'overdue',
            $age >= (int) config('helpdesk.warn_after_days') => 'warning',
            default => null,
        };
    }

    public function isOwnedBy(int $userId): bool
    {
        return $this->user_id !== null && (int) $this->user_id === $userId;
    }

    /**
     * Where the requester reads this ticket: the storefront account or the seller panel.
     */
    public function requesterUrl(): string
    {
        return $this->requester_type === RequesterType::Seller
            ? route('seller.support.show', ['ticket' => $this->ticket_number])
            : route('support.show', ['ticket' => $this->ticket_number]);
    }
}
