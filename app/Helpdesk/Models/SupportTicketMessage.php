<?php

namespace App\Helpdesk\Models;

use App\Helpdesk\Enums\MessageAuthor;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $support_ticket_id
 * @property MessageAuthor $author_type
 * @property int|null $user_id
 * @property int|null $admin_id
 * @property string $body
 * @property bool $is_internal
 * @property list<array{path: string, name: string, mime: string, size: int}>|null $attachments
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SupportTicket $ticket
 * @property-read User|null $user
 * @property-read Admin|null $admin
 */
class SupportTicketMessage extends Model
{
    protected $table = 'support_ticket_messages';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'author_type' => MessageAuthor::class,
            'is_internal' => 'boolean',
            'attachments' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SupportTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
