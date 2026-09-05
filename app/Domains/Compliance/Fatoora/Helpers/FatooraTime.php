<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Helpers;

use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;

/**
 * ZATCA Time Helper.
 *
 * All ZATCA timestamps must be in UTC.
 * This helper ensures consistent timezone handling.
 *
 * This addresses:
 * - Timezone issues (invoice time vs clearance time)
 * - Daylight savings bugs
 * - Sequence reconciliation
 */
final class FatooraTime
{
    /**
     * ZATCA timezone (always UTC).
     */
    private const ZATCA_TIMEZONE = 'UTC';

    /**
     * Saudi Arabia timezone (for display purposes).
     */
    private const SAUDI_TIMEZONE = 'Asia/Riyadh';

    /**
     * ISO 8601 format with timezone.
     */
    private const ISO_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * Date only format.
     */
    private const DATE_FORMAT = 'Y-m-d';

    /**
     * Time only format.
     */
    private const TIME_FORMAT = 'H:i:s';

    /**
     * Get current UTC timestamp.
     */
    public static function now(): DateTimeImmutable
    {
        // Honour a frozen clock when one is set.
        //
        // This read the system clock directly, so travel() and freezeTime()
        // did not reach it and nothing that depends on a ZATCA timestamp could
        // be asserted on. The signature carries a SigningTime to the second,
        // which meant a test could not tell a document signed once from one
        // signed twice inside the same second — and the difference between
        // those two is the whole of whether the archive matches what the
        // authority received.
        //
        // Nothing changes outside tests: hasTestNow() is false in production.
        if (Carbon::hasTestNow()) {
            return Carbon::getTestNow()
                ->copy()
                ->setTimezone(self::ZATCA_TIMEZONE)
                ->toDateTimeImmutable();
        }

        return new DateTimeImmutable('now', new DateTimeZone(self::ZATCA_TIMEZONE));
    }

    /**
     * Get current timestamp formatted for ZATCA XML.
     */
    public static function nowFormatted(): string
    {
        return self::now()->format(self::ISO_FORMAT);
    }

    /**
     * Get current date for ZATCA XML (UTC).
     */
    public static function today(): string
    {
        return self::now()->format(self::DATE_FORMAT);
    }

    /**
     * Get current time for ZATCA XML (UTC).
     */
    public static function currentTime(): string
    {
        return self::now()->format(self::TIME_FORMAT);
    }

    /**
     * Convert any DateTime to UTC.
     */
    public static function toUtc(\DateTimeInterface $dateTime): DateTimeImmutable
    {
        if ($dateTime instanceof DateTimeImmutable) {
            return $dateTime->setTimezone(new DateTimeZone(self::ZATCA_TIMEZONE));
        }

        return DateTimeImmutable::createFromInterface($dateTime)
            ->setTimezone(new DateTimeZone(self::ZATCA_TIMEZONE));
    }

    /**
     * Format DateTime for ZATCA XML (always UTC).
     */
    public static function format(\DateTimeInterface $dateTime): string
    {
        return self::toUtc($dateTime)->format(self::ISO_FORMAT);
    }

    /**
     * Format date only (for issue_date, supply_date).
     */
    public static function formatDate(\DateTimeInterface $dateTime): string
    {
        return self::toUtc($dateTime)->format(self::DATE_FORMAT);
    }

    /**
     * Format time only.
     */
    public static function formatTime(\DateTimeInterface $dateTime): string
    {
        return self::toUtc($dateTime)->format(self::TIME_FORMAT);
    }

    /**
     * Parse ZATCA timestamp (assumes UTC).
     */
    public static function parse(string $timestamp): DateTimeImmutable
    {
        // Try ISO 8601 format first
        $dt = DateTimeImmutable::createFromFormat(
            self::ISO_FORMAT,
            $timestamp,
            new DateTimeZone(self::ZATCA_TIMEZONE)
        );

        if ($dt !== false) {
            return $dt;
        }

        // Try with timezone offset
        $dt = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:sP',
            $timestamp
        );

        if ($dt !== false) {
            return self::toUtc($dt);
        }

        // Fallback to standard parsing
        return new DateTimeImmutable($timestamp, new DateTimeZone(self::ZATCA_TIMEZONE));
    }

    /**
     * Convert to Saudi Arabia time (for display).
     */
    public static function toSaudiTime(\DateTimeInterface $dateTime): DateTimeImmutable
    {
        return self::toUtc($dateTime)->setTimezone(new DateTimeZone(self::SAUDI_TIMEZONE));
    }

    /**
     * Get Unix timestamp in milliseconds.
     */
    public static function timestampMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /**
     * Check if timestamp is within allowed window.
     */
    public static function isWithinWindow(
        \DateTimeInterface $timestamp,
        int $windowMinutes = 5
    ): bool {
        $now = self::now();
        $diff = abs($now->getTimestamp() - $timestamp->getTimestamp());

        return $diff <= ($windowMinutes * 60);
    }

    /**
     * Get certificate expiry date in UTC.
     */
    public static function fromUnixTimestamp(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.$timestamp))
            ->setTimezone(new DateTimeZone(self::ZATCA_TIMEZONE));
    }

    /**
     * Calculate days until date.
     */
    public static function daysUntil(\DateTimeInterface $date): int
    {
        $now = self::now();
        $target = self::toUtc($date);

        $diff = $now->diff($target);

        return $diff->invert ? -$diff->days : $diff->days;
    }

    /**
     * Check if date is in the past.
     */
    public static function isPast(\DateTimeInterface $date): bool
    {
        return self::toUtc($date) < self::now();
    }

    /**
     * Check if date is in the future.
     */
    public static function isFuture(\DateTimeInterface $date): bool
    {
        return self::toUtc($date) > self::now();
    }

    /**
     * Get start of day in UTC.
     */
    public static function startOfDay(?\DateTimeInterface $date = null): DateTimeImmutable
    {
        $date = $date ? self::toUtc($date) : self::now();

        return $date->setTime(0, 0, 0, 0);
    }

    /**
     * Get end of day in UTC.
     */
    public static function endOfDay(?\DateTimeInterface $date = null): DateTimeImmutable
    {
        $date = $date ? self::toUtc($date) : self::now();

        return $date->setTime(23, 59, 59, 999999);
    }

    /**
     * Add seconds to timestamp.
     */
    public static function addSeconds(int $seconds, ?\DateTimeInterface $from = null): DateTimeImmutable
    {
        $from = $from ? self::toUtc($from) : self::now();

        return $from->modify("+{$seconds} seconds");
    }

    /**
     * Subtract seconds from timestamp.
     */
    public static function subSeconds(int $seconds, ?\DateTimeInterface $from = null): DateTimeImmutable
    {
        $from = $from ? self::toUtc($from) : self::now();

        return $from->modify("-{$seconds} seconds");
    }
}
