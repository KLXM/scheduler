<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Recurrence;

use KLXM\Scheduler\I18n;
use DateTimeImmutable;
use DateTimeZone;
use RRule\RRule;
use Sabre\VObject\Recur\RRuleIterator;

/**
 * Wertobjekt für eine RFC-5545-Wiederholungsregel.
 */
final class Rule implements \Stringable
{
    private const array FREQUENCIES = ['SECONDLY', 'MINUTELY', 'HOURLY', 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];

    /**
     * @param array<string, string> $parts Regelbestandteile in Großschreibung, etwa ['FREQ' => 'WEEKLY']
     */
    private function __construct(
        public readonly array $parts,
    ) {}

    /**
     * @throws InvalidRuleException
     */
    public static function parse(string $rrule): self
    {
        $rrule = strtoupper(trim($rrule));
        if (str_starts_with($rrule, 'RRULE:')) {
            $rrule = substr($rrule, 6);
        }

        $parts = [];
        foreach (array_filter(explode(';', $rrule)) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            if ('' === $key || '' === $value) {
                throw new InvalidRuleException(I18n::t('rule_error_part', $part));
            }
            $parts[$key] = $value;
        }

        if (!in_array($parts['FREQ'] ?? '', self::FREQUENCIES, true)) {
            throw new InvalidRuleException(I18n::t('rule_error_freq'));
        }
        if (isset($parts['COUNT'], $parts['UNTIL'])) {
            throw new InvalidRuleException(I18n::t('rule_error_count_until'));
        }

        $rule = new self($parts);
        try {
            // Der Iterator von sabre/vobject prüft Wertebereiche und unbekannte Bestandteile.
            new RRuleIterator((string) $rule, new DateTimeImmutable('2000-01-01 00:00:00', new DateTimeZone('UTC')));
        } catch (\Throwable $e) {
            throw new InvalidRuleException(I18n::t('rule_error_invalid', $e->getMessage()), previous: $e);
        }

        return $rule;
    }

    public string $frequency {
        get => $this->parts['FREQ'];
    }

    public int $interval {
        get => max(1, (int) ($this->parts['INTERVAL'] ?? 1));
    }

    public ?int $count {
        get => isset($this->parts['COUNT']) ? (int) $this->parts['COUNT'] : null;
    }

    public bool $isInfinite {
        get => !isset($this->parts['COUNT']) && !isset($this->parts['UNTIL']);
    }

    public function until(DateTimeZone $zone): ?DateTimeImmutable
    {
        $until = $this->parts['UNTIL'] ?? null;
        if (null === $until) {
            return null;
        }

        return match (true) {
            8 === strlen($until) => new DateTimeImmutable($until . ' 23:59:59', $zone),
            str_ends_with($until, 'Z') => new DateTimeImmutable($until, new DateTimeZone('UTC'))->setTimezone($zone),
            default => new DateTimeImmutable($until, $zone),
        };
    }

    /**
     * Bringt UNTIL in die von RFC 5545 geforderte Form: DATE bei ganztägigen Terminen, sonst UTC.
     */
    public function normalizedFor(bool $allDay, DateTimeZone $zone): self
    {
        $until = $this->until($zone);
        if (null === $until) {
            return $this;
        }

        $parts = $this->parts;
        $parts['UNTIL'] = $allDay
            ? $until->format('Ymd')
            : $until->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');

        return new self($parts);
    }

    /**
     * Kopie mit gesetztem oder (bei null) entferntem Bestandteil.
     */
    public function with(string $key, ?string $value): self
    {
        $parts = $this->parts;
        if (null === $value) {
            unset($parts[strtoupper($key)]);
        } else {
            $parts[strtoupper($key)] = strtoupper($value);
        }

        return new self($parts);
    }

    /**
     * Lesbare Beschreibung, etwa "wöchentlich am Montag und Mittwoch, bis 31.12.2026".
     */
    public function toText(string $locale = 'de', ?DateTimeImmutable $start = null): string
    {
        try {
            $parts = $this->parts;
            if (null !== $start) {
                $parts['DTSTART'] = $start;
            }

            return new RRule($parts)->humanReadable([
                'locale' => $locale,
                'include_start' => false,
                'explicit_infinite' => false,
            ]);
        } catch (\Throwable) {
            return (string) $this;
        }
    }

    public function __toString(): string
    {
        $pairs = [];
        foreach ($this->parts as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return implode(';', $pairs);
    }
}
