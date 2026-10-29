<?php

namespace App\Mail;

use App\Returns\Models\ReturnRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Return progress for the customer or the seller.
 * Events: received (seller), qc_passed, qc_failed (both), replacement_shipped, converted_to_refund, lost_refund (customer).
 */
class ReturnUpdateMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const SUBJECTS = [
        'received' => 'A return has reached you',
        'qc_passed' => 'Return check passed',
        'qc_failed' => 'Return check did not pass',
        'replacement_shipped' => 'Your replacement is on its way',
        'converted_to_refund' => 'Your replacement became a refund',
        'lost_refund' => 'Your return was lost by the courier',
    ];

    public function __construct(
        public ReturnRequest $request,
        public string $event,
        public bool $forSeller = false,
        public string $orderNumber = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (self::SUBJECTS[$this->event] ?? 'Return update').' - '.brand_name());
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.return-update',
            with: [
                'event' => $this->event,
                'forSeller' => $this->forSeller,
                'orderNumber' => $this->orderNumber,
                'reason' => $this->request->reason->label(),
                'outcome' => $this->request->qc_outcome,
                'notes' => $this->request->qc_notes,
                'deadline' => $this->request->report_deadline_at ? format_datetime($this->request->report_deadline_at) : null,
            ],
        );
    }
}
