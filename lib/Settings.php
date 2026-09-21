<?php

declare(strict_types=1);

namespace KLXM\Scheduler;

/**
 * Typisierter Zugriff auf die Addon-Einstellungen.
 */
final readonly class Settings
{
    public const array DEFAULTS = [
        'default_timezone' => 'Europe/Berlin',
        'horizon_back_months' => 12,
        'horizon_ahead_months' => 36,
        'max_occurrences_per_event' => 5000,
        'default_all_day' => true,
        'week_starts_on' => 1,
        'editor_class' => '',
        'editor_profile' => '',
        'feed_months_back' => 1,
        'feed_months_ahead' => 24,
    ];

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        private array $values = [],
    ) {}

    public static function fromRedaxo(): self
    {
        $values = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $values[$key] = \rex_config::get('scheduler', $key, self::DEFAULTS[$key]);
        }

        return new self($values);
    }

    public function defaultTimezone(): string
    {
        $timezone = (string) $this->get('default_timezone');

        return in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : 'Europe/Berlin';
    }

    public function horizonBackMonths(): int
    {
        return max(0, (int) $this->get('horizon_back_months'));
    }

    public function horizonAheadMonths(): int
    {
        return max(1, (int) $this->get('horizon_ahead_months'));
    }

    public function maxOccurrencesPerEvent(): int
    {
        return max(1, (int) $this->get('max_occurrences_per_event'));
    }

    public function defaultAllDay(): bool
    {
        return (bool) $this->get('default_all_day');
    }

    public function weekStartsOn(): int
    {
        return ((int) $this->get('week_starts_on')) % 7;
    }

    /** CSS-Klasse, die den gewünschten WYSIWYG-Editor aktiviert, etwa "tiny-editor". */
    public function editorClass(): string
    {
        return trim((string) $this->get('editor_class'));
    }

    public function editorProfile(): string
    {
        return trim((string) $this->get('editor_profile'));
    }

    public function feedMonthsBack(): int
    {
        return max(0, (int) $this->get('feed_months_back'));
    }

    public function feedMonthsAhead(): int
    {
        return max(1, (int) $this->get('feed_months_ahead'));
    }

    private function get(string $key): mixed
    {
        return $this->values[$key] ?? self::DEFAULTS[$key];
    }
}
