<?php

declare(strict_types=1);

namespace RegisTrack\Services;

use PDO;
use RuntimeException;

/**
 * Generates opaque, non-sequential tracking numbers (RT-YYYYMMDD-XXXXXX).
 * Random segments avoid leaking request volume; the database UNIQUE constraint
 * is the final guarantee, uniqueness is pre-checked inside the caller's
 * transaction to keep retries cheap.
 */
final class TrackingNumberService
{
    // Ambiguity-free alphabet: no 0/O, 1/I/L.
    private const CHARSET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const SEGMENT_LENGTH = 6;
    private const MAX_ATTEMPTS = 5;

    public static function generate(PDO $db): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = sprintf(
                'RT-%s-%s',
                gmdate('Ymd'),
                self::randomSegment()
            );

            $statement = $db->prepare('SELECT 1 FROM requests WHERE tracking_number = ? LIMIT 1');
            $statement->execute([$candidate]);
            if ($statement->fetchColumn() === false) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not generate a unique tracking number.');
    }

    private static function randomSegment(): string
    {
        $alphabetLength = strlen(self::CHARSET);
        $segment = '';
        for ($i = 0; $i < self::SEGMENT_LENGTH; $i++) {
            $segment .= self::CHARSET[random_int(0, $alphabetLength - 1)];
        }

        return $segment;
    }
}
