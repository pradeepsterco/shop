<?php

namespace App\Helpdesk\Services;

use App\Helpdesk\Enums\RequesterType;
use App\Helpdesk\Enums\TicketStatus;
use App\Helpdesk\Models\SupportTicket;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Evidence for the Consumer Protection (E-Commerce) Rules, 2020 timelines: every customer complaint acknowledged
 * within 48 hours (the automatic "We have your request" email with its ticket number) and resolved within one month.
 */
class ComplaintTimelinesReport
{
    /**
     * Customer tickets received in the month containing $month.
     *
     * @return array{
     *     from: CarbonImmutable, to: CarbonImmutable, received: int, acknowledged: int,
     *     staff_replied_within_ack: int, resolved_within_deadline: int, resolved_late: int,
     *     open_past_deadline: int, open_within_deadline: int, median_first_response_hours: float|null
     * }
     */
    public function forMonth(CarbonInterface $month): array
    {
        $from = CarbonImmutable::instance($month)->startOfMonth();
        $to = $from->endOfMonth();
        $ackHours = (int) config('helpdesk.acknowledge_within_hours');
        $resolveDays = (int) config('helpdesk.resolve_within_days');

        $tickets = SupportTicket::query()
            ->where('requester_type', RequesterType::Customer->value)
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'status', 'created_at', 'first_response_at', 'resolved_at', 'closed_at']);

        $report = [
            'from' => $from,
            'to' => $to,
            'received' => $tickets->count(),
            // The acknowledgement email is queued the moment a ticket is created
            'acknowledged' => $tickets->count(),
            'staff_replied_within_ack' => 0,
            'resolved_within_deadline' => 0,
            'resolved_late' => 0,
            'open_past_deadline' => 0,
            'open_within_deadline' => 0,
            'median_first_response_hours' => null,
        ];

        $responseHours = [];
        foreach ($tickets as $ticket) {
            /** @var CarbonInterface $created */
            $created = $ticket->created_at;

            if ($ticket->first_response_at) {
                $hours = $created->diffInMinutes($ticket->first_response_at) / 60;
                $responseHours[] = $hours;
                if ($hours <= $ackHours) {
                    $report['staff_replied_within_ack']++;
                }
            }

            $settledAt = in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true)
                ? ($ticket->resolved_at ?? $ticket->closed_at)
                : null;

            if ($settledAt) {
                $report[$created->diffInDays($settledAt) <= $resolveDays ? 'resolved_within_deadline' : 'resolved_late']++;
            } else {
                $report[$created->diffInDays(now()) > $resolveDays ? 'open_past_deadline' : 'open_within_deadline']++;
            }
        }

        if ($responseHours) {
            sort($responseHours);
            $middle = intdiv(count($responseHours), 2);
            $report['median_first_response_hours'] = round(count($responseHours) % 2
                ? $responseHours[$middle]
                : ($responseHours[$middle - 1] + $responseHours[$middle]) / 2, 1);
        }

        return $report;
    }
}
