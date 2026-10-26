<?php

use App\Support\DateFormatter;

if (! function_exists('format_datetime')) {
    /**
     * Format a date/time in the configured display timezone (default: "25 Oct 2026, 5:30 PM IST").
     */
    function format_datetime(DateTimeInterface|string|null $date, ?string $format = null): string
    {
        return DateFormatter::format($date, $format);
    }
}

if (! function_exists('format_date')) {
    /**
     * Format a date only in the configured display timezone (default: "25 Oct 2026").
     */
    function format_date(DateTimeInterface|string|null $date, string $format = 'd M Y'): string
    {
        return DateFormatter::formatDate($date, $format);
    }
}
