<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ticket Limits
    |--------------------------------------------------------------------------
    |
    | Most non-closed tickets one requester may have at a time, and most new
    | tickets one requester may open in a rolling 24 hours.
    |
    */
    'max_open_tickets' => 5,

    'max_new_tickets_per_day' => 10,

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Per message. Files are stored on the private "local" disk and streamed
    | through an authorised route.
    |
    */
    'max_attachments' => 3,

    'attachment_max_kb' => 5120,

    'attachment_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],

    /*
    |--------------------------------------------------------------------------
    | Content Lengths
    |--------------------------------------------------------------------------
    */
    'subject_min' => 5,

    'subject_max' => 150,

    'body_min' => 10,

    'body_max' => 5000,

    /*
    |--------------------------------------------------------------------------
    | Auto-close
    |--------------------------------------------------------------------------
    |
    | Resolved tickets with no activity for this many days are closed by
    | the helpdesk:close-resolved command.
    |
    */
    'auto_close_resolved_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Auto-resolve
    |--------------------------------------------------------------------------
    |
    | Tickets waiting on the customer (or a seller's own ticket waiting on the
    | seller) with no reply for this many days are resolved by the
    | helpdesk:resolve-unanswered command. They can still reopen it until it
    | auto-closes.
    |
    */
    'pending_auto_resolve_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Seller Replies
    |--------------------------------------------------------------------------
    |
    | Hours a seller has to answer a staff message on a seller ticket, and how
    | many hours before that deadline helpdesk:remind-sellers sends a reminder.
    |
    */
    'seller_reply_hours' => 48,

    'seller_reminder_hours_left' => 24,

    /*
    |--------------------------------------------------------------------------
    | Complaint Timelines
    |--------------------------------------------------------------------------
    |
    | Consumer Protection (E-Commerce) Rules, 2020: acknowledge within 48 hours,
    | resolve within one month. The inbox warns from warn_after_days.
    |
    */
    'acknowledge_within_hours' => 48,

    'resolve_within_days' => 30,

    'warn_after_days' => 25,
];
