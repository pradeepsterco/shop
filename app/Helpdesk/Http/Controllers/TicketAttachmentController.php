<?php

namespace App\Helpdesk\Http\Controllers;

use App\Helpdesk\Models\SupportTicket;
use App\Helpdesk\Models\SupportTicketMessage;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams ticket attachments from the private disk. Admins need support.view (route middleware);
 * requesters only see attachments on their own tickets, never on internal notes.
 */
class TicketAttachmentController extends Controller
{
    public function requester(Request $http, string $ticket, int $message, int $index): StreamedResponse
    {
        $model = $this->message($ticket, $message);
        $user = $http->user();
        abort_unless($user instanceof User && $model->ticket->isOwnedBy((int) $user->id) && ! $model->is_internal, 404);

        return $this->stream($model, $index);
    }

    public function admin(string $ticket, int $message, int $index): StreamedResponse
    {
        return $this->stream($this->message($ticket, $message), $index);
    }

    protected function message(string $ticketNumber, int $messageId): SupportTicketMessage
    {
        $ticket = SupportTicket::query()->where('ticket_number', $ticketNumber)->firstOrFail();

        return $ticket->messages()->whereKey($messageId)->firstOrFail()->setRelation('ticket', $ticket);
    }

    protected function stream(SupportTicketMessage $message, int $index): StreamedResponse
    {
        $file = ($message->attachments ?? [])[$index] ?? null;
        abort_unless(is_array($file) && Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->response($file['path'], $file['name'], [
            'Content-Type' => $file['mime'],
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
