<?php

declare(strict_types=1);

namespace RegisTrack\Core;

/**
 * Central clock and time-zone conversion.
 *
 * Storage is always UTC (the PDO session is pinned to +00:00), while every
 * timestamp shown to registrar users is Philippine time. Keeping both rules in
 * one class prevents the double-conversion bug where a UTC+8 database string
 * was treated as UTC by the browser.
 */
final class Time
{
    public const STORAGE_TIMEZONE = 'UTC';
    public const DISPLAY_TIMEZONE = 'Asia/Manila';
    /** Fixed offset used in SQL CONVERT_TZ calls (the Philippines has no DST). */
    public const DISPLAY_OFFSET = '+08:00';

    private function __construct()
    {
    }

    public static function manilaZone(): \DateTimeZone
    {
        return new \DateTimeZone(self::DISPLAY_TIMEZONE);
    }

    public static function utcNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::STORAGE_TIMEZONE));
    }

    public static function manilaNow(): \DateTimeImmutable
    {
        return self::utcNow()->setTimezone(self::manilaZone());
    }

    /** Current calendar date in the Philippines (YYYY-MM-DD). */
    public static function todayManila(): string
    {
        return self::manilaNow()->format('Y-m-d');
    }

    /**
     * Convert a stored UTC timestamp to an ISO-8601 Philippine-time string.
     * Values that cannot be parsed are returned unchanged so callers never lose
     * data; null stays null.
     */
    public static function toManila(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        $utc = self::parseUtc($value);
        if ($utc === null) {
            return $value;
        }

        return $utc->setTimezone(self::manilaZone())->format('Y-m-d\TH:i:sP');
    }

    /** Philippine calendar date for a stored UTC timestamp. */
    public static function manilaDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $utc = self::parseUtc(trim($value));

        return $utc?->setTimezone(self::manilaZone())->format('Y-m-d');
    }

    /** Readable Philippine date for messages, e.g. "Oct 2, 2026". */
    public static function formatManila(?string $value, string $format = 'M j, Y'): string
    {
        if ($value === null || trim($value) === '') {
            return 'an earlier date';
        }

        $utc = self::parseUtc(trim($value));

        return $utc === null
            ? 'an earlier date'
            : $utc->setTimezone(self::manilaZone())->format($format);
    }

    /**
     * UTC boundaries for one Philippine calendar day.
     *
     * @return array{0:string,1:string} inclusive start, exclusive end
     */
    public static function manilaDayBounds(string $date): array
    {
        $start = new \DateTimeImmutable($date . ' 00:00:00', self::manilaZone());
        $end = $start->modify('+1 day');
        $utc = new \DateTimeZone(self::STORAGE_TIMEZONE);

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    private static function parseUtc(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone(self::STORAGE_TIMEZONE));
        } catch (\Exception) {
            return null;
        }
    }
}
