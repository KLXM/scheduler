<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Ical;

use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;

/**
 * Erzeugt VTIMEZONE-Komponenten aus den Zeitzonendaten von PHP.
 *
 * RFC 5545 verlangt zu jeder verwendeten TZID eine Definition. Ausgegeben wird je Wechsel
 * im Zeitfenster eine STANDARD- oder DAYLIGHT-Komponente.
 */
final class TimezoneBuilder
{
    public function add(VCalendar $vcalendar, string $timezone, DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        if ('UTC' === $timezone) {
            return;
        }

        try {
            $zone = new DateTimeZone($timezone);
        } catch (\Throwable) {
            return;
        }

        // Ein Jahr Vorlauf, damit der zu Beginn des Fensters gültige Versatz bekannt ist.
        $transitions = $zone->getTransitions($from->modify('-1 year')->getTimestamp(), $to->getTimestamp());
        if ([] === $transitions) {
            return;
        }

        /** @var \Sabre\VObject\Component $vtimezone */
        $vtimezone = $vcalendar->add('VTIMEZONE', ['TZID' => $timezone]);
        $vtimezone->add('X-LIC-LOCATION', $timezone);

        if (1 === count($transitions)) {
            // Zeitzone ohne Wechsel: eine einzige STANDARD-Komponente genügt.
            $offset = $this->offset($transitions[0]['offset']);
            $vtimezone->add('STANDARD', [
                'DTSTART' => '19700101T000000',
                'TZOFFSETFROM' => $offset,
                'TZOFFSETTO' => $offset,
                'TZNAME' => $transitions[0]['abbr'],
            ]);

            return;
        }

        $previousOffset = $transitions[0]['offset'];
        foreach (array_slice($transitions, 1) as $transition) {
            // DTSTART eines Wechsels ist die Wanduhrzeit vor dem Wechsel.
            $localStart = new DateTimeImmutable('@' . ($transition['ts'] + $previousOffset))->format('Ymd\THis');
            $vtimezone->add($transition['isdst'] ? 'DAYLIGHT' : 'STANDARD', [
                'DTSTART' => $localStart,
                'TZOFFSETFROM' => $this->offset($previousOffset),
                'TZOFFSETTO' => $this->offset($transition['offset']),
                'TZNAME' => $transition['abbr'],
            ]);
            $previousOffset = $transition['offset'];
        }
    }

    private function offset(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return sprintf('%s%02d%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
