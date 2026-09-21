<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Import\Legacy;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;

/**
 * Bildet die Wiederholungslogik von forcal 6 nach: nur für den Import, um die alten
 * Vorkommen mit der neuen RRULE vergleichen zu können.
 *
 * forcal 6 addierte ein DateInterval auf das Startdatum. Das weicht von RFC 5545 ab, etwa
 * beim Monatsüberlauf (31.01. + 1 Monat = 03.03.).
 *
 * @internal
 */
final class LegacyRecurrence
{
    private const array DAYS = [
        'sun' => 'sunday', 'mon' => 'monday', 'tue' => 'tuesday', 'wed' => 'wednesday',
        'thu' => 'thursday', 'fri' => 'friday', 'sat' => 'saturday',
    ];

    private const array RRULE_DAYS = [
        'sun' => 'SU', 'mon' => 'MO', 'tue' => 'TU', 'wed' => 'WE', 'thu' => 'TH', 'fri' => 'FR', 'sat' => 'SA',
    ];

    private const array RRULE_WEEKS = ['first' => '1', 'second' => '2', 'third' => '3', 'fourth' => '4', 'last' => '-1'];

    /**
     * @param array<string, scalar|null> $entry Zeile aus rex_forcal_entries
     */
    public static function isSeries(array $entry): bool
    {
        return 'repeat' === ($entry['type'] ?? null) && !empty($entry['end_repeat_date']) && '0000-00-00' !== $entry['end_repeat_date'];
    }

    /**
     * Startdaten aller Vorkommen, wie forcal 6 sie berechnet hat.
     *
     * @param array<string, scalar|null> $entry
     * @return list<string> Y-m-d
     */
    public static function dates(array $entry): array
    {
        $start = new DateTimeImmutable((string) $entry['start_date']);
        $end = new DateTimeImmutable((string) $entry['end_repeat_date'])->modify('+1 day');

        $interval = match ($entry['repeat'] ?? '') {
            'weekly' => new DateInterval('P' . (max(1, (int) $entry['repeat_week']) * 7) . 'D'),
            'monthly' => new DateInterval('P' . max(1, (int) $entry['repeat_month']) . 'M'),
            'yearly' => new DateInterval('P' . max(1, (int) $entry['repeat_year']) . 'Y'),
            'monthly-week' => DateInterval::createFromDateString(sprintf(
                '%s %s of next month',
                (string) ($entry['repeat_month_week'] ?? 'first'),
                self::DAYS[(string) ($entry['repeat_day'] ?? 'mon')] ?? 'monday',
            )),
            default => new DateInterval('P1D'),
        };

        $dates = [];
        foreach (new DatePeriod($start, $interval, $end) as $date) {
            $dates[] = $date->format('Y-m-d');
            if (count($dates) >= 10000) {
                break;
            }
        }

        return $dates;
    }

    /**
     * RFC-5545-Regel, die der alten Einstellung am nächsten kommt. UNTIL als Datum; der
     * EventValidator bringt es beim Speichern in die endgültige Form.
     *
     * @param array<string, scalar|null> $entry
     */
    public static function rrule(array $entry): string
    {
        $start = new DateTimeImmutable((string) $entry['start_date']);
        $until = new DateTimeImmutable((string) $entry['end_repeat_date'])->format('Ymd');

        $parts = match ($entry['repeat'] ?? '') {
            'weekly' => ['FREQ=WEEKLY', self::interval((int) $entry['repeat_week'])],
            'monthly' => ['FREQ=MONTHLY', self::interval((int) $entry['repeat_month']), 'BYMONTHDAY=' . $start->format('j')],
            'yearly' => ['FREQ=YEARLY', self::interval((int) $entry['repeat_year'])],
            'monthly-week' => [
                'FREQ=MONTHLY',
                'BYDAY=' . (self::RRULE_WEEKS[(string) ($entry['repeat_month_week'] ?? 'first')] ?? '1')
                    . (self::RRULE_DAYS[(string) ($entry['repeat_day'] ?? 'mon')] ?? 'MO'),
            ],
            default => ['FREQ=DAILY'],
        };
        $parts[] = 'UNTIL=' . $until;

        return implode(';', array_filter($parts));
    }

    private static function interval(int $value): string
    {
        return $value > 1 ? 'INTERVAL=' . $value : '';
    }
}
