<?php

namespace App\Mail;

use App\Orders\Enums\RefundStatus;
use App\Orders\Models\CustomerRefund;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Refund paid or failed, to the customer or to refund managers. Payout details are only ever shown masked.
 */
class RefundStatusMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public CustomerRefund $refund,
        public bool $forAdmin = false,
    ) {}

    public function envelope(): Envelope
    {
        $orderNumber = $this->refund->order->order_number;
        $subject = $this->refund->status === RefundStatus::Paid
            ? "Refund paid for order {$orderNumber}"
            : "Refund failed for order {$orderNumber}";

        return new Envelope(subject: $subject.' - '.brand_name());
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.refund-status',
            with: [
                'forAdmin' => $this->forAdmin,
                'isPaid' => $this->refund->status === RefundStatus::Paid,
                'orderNumber' => $this->refund->order->order_number,
                'amount' => number_format($this->refund->amount_paise / 100, 2),
                'utr' => $this->refund->utr,
                'reason' => $this->refund->failure_reason,
                'destination' => $this->refund->payoutDestination(full: false),
            ],
        );
    }
}
