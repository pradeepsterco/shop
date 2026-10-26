<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

class DateFormatter
{
    /**
     * Get the configured display timezone for the application.
     */
    public static function displayTimezone(): string
    {
        return (string) config('app.display_timezone', 'Asia/Kolkata');
    }

    /**
     * Convert any DateTimeInterface or parseable string into a CarbonImmutable
     * set to the application's display timezone.
     */
    public static function toDisplayTimezone(DateTimeInterface|string|null $date): ?CarbonImmutable
    {
        if ($date === null || $date === '') {
            return null;
        }

        if ($date instanceof DateTimeInterface) {
            return CarbonImmutable::instance($date)->setTimezone(self::displayTimezone());
        }

        return CarbonImmutable::parse($date)->setTimezone(self::displayTimezone());
    }

    /**
     * Format a date/time in the display timezone.
     * Default format: "25 Oct 2026, 5:30 PM IST"
     */
    public static function format(DateTimeInterface|string|null $date, ?string $format = null): string
    {
        $converted = self::toDisplayTimezone($date);

        if ($converted === null) {
            return '—';
        }

        $format ??= 'd M Y, g:i A T';

        return $converted->format($format);
    }

    /**
     * Format date only (e.g. "25 Oct 2026") in the display timezone.
     */
    public static function formatDate(DateTimeInterface|string|null $date, string $format = 'd M Y'): string
    {
        $converted = self::toDisplayTimezone($date);

        if ($converted === null) {
            return '—';
        }

        return $converted->format($format);
    }

    /**
     * Format time only (e.g. "5:30 PM IST") in the display timezone.
     */
    public static function formatTime(DateTimeInterface|string|null $date, string $format = 'g:i A T'): string
    {
        $converted = self::toDisplayTimezone($date);

        if ($converted === null) {
            return '—';
        }

        return $converted->format($format);
    }

    /**
     * Return [UTC start, UTC end] for "today" bounded by the display timezone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function todayRange(): array
    {
        $tz = self::displayTimezone();
        $now = CarbonImmutable::now($tz);

        return [
            $now->startOfDay()->utc(),
            $now->endOfDay()->utc(),
        ];
    }

    /**
     * Return [UTC start, UTC end] for the last N days bounded by the display timezone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function lastDaysRange(int $days): array
    {
        $tz = self::displayTimezone();
        $now = CarbonImmutable::now($tz);

        return [
            $now->subDays($days)->startOfDay()->utc(),
            $now->endOfDay()->utc(),
        ];
    }

    /**
     * Parse custom date strings (e.g. '2026-10-25') in the display timezone and return [UTC start, UTC end].
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public static function parseCustomRange(?string $from, ?string $to): array
    {
        $tz = self::displayTimezone();

        $start = filled($from) ? CarbonImmutable::parse($from, $tz)->startOfDay()->utc() : null;
        $end = filled($to) ? CarbonImmutable::parse($to, $tz)->endOfDay()->utc() : null;

        return [$start, $end];
    }
}
