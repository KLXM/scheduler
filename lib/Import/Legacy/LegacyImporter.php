<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Import\Legacy;

use KLXM\Scheduler\I18n;
use DateTimeImmutable;
use DateTimeZone;
use KLXM\Scheduler\Domain\Calendar;
use KLXM\Scheduler\Domain\Enum\SchemaTarget;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Domain\Location;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Import\DryRunRollback;
use KLXM\Scheduler\Import\ImportReport;
use KLXM\Scheduler\Orm\Connection;
use KLXM\Scheduler\Service\ValidationException;

/**
 * Übernimmt die Daten aus forcal 6. Die alten Tabellen werden nur gelesen, nie verändert.
 *
 * Der Lauf ist wiederholbar: jeder Datensatz merkt sich seine Herkunft und wird beim nächsten
 * Lauf aktualisiert. Solange die Ziel-ID frei ist, bleiben die alten IDs erhalten.
 *
 * @internal isoliertes Modul; kann entfallen, sobald niemand mehr von forcal umzieht
 */
final class LegacyImporter
{
    public const string SOURCE = 'forcal6';

    private const array ENTRY_CORE = [
        'id', 'uid', 'start_date', 'end_date', 'start_time', 'end_time', 'category', 'venue', 'status', 'type',
        'full_time', 'repeat', 'repeat_year', 'repeat_week', 'repeat_month', 'repeat_month_week', 'repeat_day',
        'end_repeat_date', 'createdate', 'updatedate', 'createuser', 'updateuser',
    ];
    private const array CATEGORY_CORE = ['id', 'color', 'status', 'createdate', 'updatedate', 'createuser', 'updateuser'];
    private const array VENUE_CORE = ['id', 'status', 'city', 'zip', 'street', 'housenumber', 'country', 'createdate', 'updatedate', 'createuser', 'updateuser'];
    private const array TRANSLATED_CORE = ['name', 'teaser', 'text'];

    /** @var array<int, int> alte Kategorie-ID => Kalender-ID */
    private array $calendarMap = [];

    /** @var array<int, int> alte Orts-ID => Location-ID */
    private array $locationMap = [];

    /**
     * @param list<int> $clangIds vorhandene Sprach-IDs, die Start-Sprache zuerst
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $clangIds,
        private readonly string $timezone,
        private readonly ?string $definitionPath = null,
    ) {}

    public function isAvailable(): bool
    {
        return [] !== $this->connection->fetchAll('SHOW TABLES LIKE ?', [$this->legacyTableName('entries')]);
    }

    /**
     * @return array{entries: int, categories: int, venues: int}
     */
    public function sourceCounts(): array
    {
        $counts = [];
        foreach (['entries', 'categories', 'venues'] as $table) {
            $counts[$table] = $this->tableExists($table)
                ? (int) $this->connection->fetchAll('SELECT COUNT(*) AS c FROM ' . $this->legacyTable($table))[0]['c']
                : 0;
        }

        return $counts;
    }

    public function run(bool $dryRun): ImportReport
    {
        $report = new ImportReport($dryRun);
        if (!$this->isAvailable()) {
            $report->error('forcal', I18n::t('legacy_table_missing', $this->legacyTableName('entries')));

            return $report;
        }

        try {
            Scheduler::em()->transactional(function () use ($report, $dryRun): void {
                $this->importCalendars($report);
                $this->importLocations($report);
                $this->importEvents($report);
                if ($dryRun) {
                    throw new DryRunRollback();
                }
            });
        } catch (DryRunRollback) {
            // Probelauf: alles ausgeführt und zurückgerollt.
        }

        $this->importSchemas($report);

        return $report;
    }

    /**
     * Übernimmt die Felddefinitionen, sofern für das Ziel noch kein eigenes Schema existiert.
     * Läuft außerhalb der Transaktion, weil Indexspalten per ALTER TABLE entstehen.
     */
    private function importSchemas(ImportReport $report): void
    {
        if (null === $this->definitionPath) {
            return;
        }
        $generator = new LegacySchemaGenerator($this->definitionPath);
        $sources = [
            [SchemaTarget::Event, 'entries', self::ENTRY_CORE, self::TRANSLATED_CORE],
            [SchemaTarget::Calendar, 'categories', self::CATEGORY_CORE, ['name']],
            [SchemaTarget::Location, 'venues', self::VENUE_CORE, ['name']],
        ];

        foreach ($sources as [$target, $table, $core, $translatedCore]) {
            if (!$this->tableExists($table) || !Scheduler::schemas()->active($target)->isEmpty) {
                continue;
            }
            $schema = $generator->generate($target, $this->customColumns($table, $core, $translatedCore), [...Scheduler::schemas()->reservedNames($target), 'housenumber']);
            if (null === $schema) {
                continue;
            }
            $fields = count($schema->fields());
            if ($report->dryRun) {
                $report->info(I18n::t('fields'), I18n::t('legacy_fields_would', $target->value, $fields));
                continue;
            }
            try {
                Scheduler::schemas()->publish($target, $schema, note: I18n::t('legacy_fields_note'));
                $report->count(I18n::t('count_fields_created'), $fields);
            } catch (ValidationException $e) {
                $report->warning(I18n::t('fields'), I18n::t('legacy_fields_failed', $target->value, $e->getMessage()));
            }
        }
    }

    /**
     * Namen der Custom-Spalten einer Alttabelle, die mindestens einen Wert enthalten.
     *
     * @param list<string> $core
     * @param list<string> $translatedCore
     * @return list<string>
     */
    private function customColumns(string $table, array $core, array $translatedCore): array
    {
        $used = [];
        foreach ($this->connection->fetchAll('SELECT * FROM ' . $this->legacyTable($table)) as $row) {
            [, $custom, $translatedCustom] = $this->splitColumns($row, $core, $translatedCore);
            $used = [...$used, ...array_keys($custom), ...array_merge(...array_map(array_keys(...), array_values($translatedCustom)), ...[[]])];
        }

        return array_values(array_unique($used));
    }

    private function importCalendars(ImportReport $report): void
    {
        $rows = $this->tableExists('categories') ? $this->connection->fetchAll('SELECT * FROM ' . $this->legacyTable('categories') . ' ORDER BY id') : [];
        $repository = Scheduler::calendars();

        foreach ($rows as $row) {
            $legacyId = (int) $row['id'];
            $calendar = $repository->query()->whereRaw("JSON_UNQUOTE(JSON_EXTRACT({custom}, '$._legacy_id')) = ?", [(string) $legacyId])->first();
            $isNew = null === $calendar;
            $calendar ??= new Calendar();

            [$names, $custom, $translatedCustom] = $this->splitColumns($row, self::CATEGORY_CORE, ['name']);
            $calendar->name = $this->firstNonEmpty($names['name'] ?? []) ?? I18n::t('legacy_category_name', $legacyId);
            $calendar->nameTranslations = array_filter(
                $names['name'] ?? [],
                static fn (string $name): bool => '' !== $name && $name !== $calendar->name,
            );
            $calendar->color = '' !== (string) ($row['color'] ?? '') ? (string) $row['color'] : $calendar->color;
            $calendar->active = 1 === (int) ($row['status'] ?? 1);
            $calendar->timezone = $this->timezone;
            // Eine alte Custom-Beschreibung wird zur festen Kalenderbeschreibung.
            $description = $translatedCustom[$this->clangIds[0] ?? 1]['description'] ?? $custom['description'] ?? null;
            if (is_string($description) && '' !== trim($description)) {
                $calendar->description = trim(strip_tags($description));
            }
            unset($custom['description']);
            foreach ($translatedCustom as $clangId => $values) {
                unset($translatedCustom[$clangId]['description']);
            }
            $calendar->custom = ['_legacy_id' => $legacyId, ...$custom, ...$this->flattenTranslated(array_filter($translatedCustom))];

            if ($isNew && null === $repository->find($legacyId)) {
                $repository->insertWithId($calendar, $legacyId);
            } else {
                $repository->save($calendar);
            }
            $this->calendarMap[$legacyId] = (int) $calendar->id;
            $report->count(I18n::t($isNew ? 'count_calendars_created' : 'count_calendars_updated'));
        }
    }

    private function importLocations(ImportReport $report): void
    {
        $rows = $this->tableExists('venues') ? $this->connection->fetchAll('SELECT * FROM ' . $this->legacyTable('venues') . ' ORDER BY id') : [];
        $repository = Scheduler::locations();

        foreach ($rows as $row) {
            $legacyId = (int) $row['id'];
            $location = $repository->query()->whereRaw("JSON_UNQUOTE(JSON_EXTRACT({custom}, '$._legacy_id')) = ?", [(string) $legacyId])->first();
            $isNew = null === $location;
            $location ??= new Location();

            [$names, $custom, $translatedCustom] = $this->splitColumns($row, self::VENUE_CORE, ['name']);
            $location->name = $this->firstNonEmpty($names['name'] ?? []) ?? I18n::t('legacy_location_name', $legacyId);
            $location->street = $this->nullIfEmpty(trim(($row['street'] ?? '') . ' ' . ($row['housenumber'] ?? '')));
            $location->zip = $this->nullIfEmpty((string) ($row['zip'] ?? ''));
            $location->city = $this->nullIfEmpty((string) ($row['city'] ?? ''));
            $location->country = $this->nullIfEmpty((string) ($row['country'] ?? ''));
            $location->active = 1 === (int) ($row['status'] ?? 1);
            $location->custom = ['_legacy_id' => $legacyId, ...$custom, ...$this->flattenTranslated($translatedCustom)];

            if ($isNew && null === $repository->find($legacyId)) {
                $repository->insertWithId($location, $legacyId);
            } else {
                $repository->save($location);
            }
            $this->locationMap[$legacyId] = (int) $location->id;
            $report->count(I18n::t($isNew ? 'count_locations_created' : 'count_locations_updated'));
        }
    }

    private function importEvents(ImportReport $report): void
    {
        $repository = Scheduler::events();
        $rows = $this->connection->fetchAll('SELECT * FROM ' . $this->legacyTable('entries') . ' ORDER BY id');

        foreach ($rows as $row) {
            $legacyId = (int) $row['id'];
            $subject = I18n::t('legacy_event_subject', $legacyId);

            try {
                $event = $repository->findByImportRef(self::SOURCE, (string) $legacyId);
                $isNew = null === $event;
                $event ??= new Event(timezone: $this->timezone);

                $this->mapEvent($event, $row, $report, $subject);

                if ($isNew) {
                    $legacyUid = trim((string) ($row['uid'] ?? ''));
                    $event->uid = ('' !== $legacyUid && null === $repository->findByUid($legacyUid)) ? $legacyUid : '';
                    if (null === $repository->find($legacyId)) {
                        $repository->insertWithId($event, $legacyId);
                    } else {
                        $repository->save($event);
                        $report->warning($subject, I18n::t('legacy_id_taken', $legacyId, (int) $event->id));
                    }
                } else {
                    $repository->save($event);
                }
                $report->count(I18n::t($isNew ? 'count_events_created' : 'count_events_updated'));
            } catch (ValidationException $e) {
                $report->error($subject, $e->getMessage());
                $report->count(I18n::t('count_events_failed'));
            }
        }
    }

    /**
     * @param array<string, scalar|null> $row
     */
    private function mapEvent(Event $event, array $row, ImportReport $report, string $subject): void
    {
        $zone = new DateTimeZone($this->timezone);
        $startTime = (string) ($row['start_time'] ?? '00:00:00');
        $endTime = (string) ($row['end_time'] ?? '00:00:00');
        // forcal 6 hat Termine von 00:00 bis 00:00 auch ohne Ganztags-Haken ganztägig dargestellt.
        $allDay = !empty($row['full_time']) || ('00:00:00' === $startTime && '00:00:00' === $endTime);

        if ($allDay) {
            $event->scheduleAllDay(new DateTimeImmutable((string) $row['start_date'], $zone), new DateTimeImmutable((string) $row['end_date'], $zone), $this->timezone);
        } else {
            $start = new DateTimeImmutable($row['start_date'] . ' ' . $startTime, $zone);
            $end = new DateTimeImmutable($row['end_date'] . ' ' . $endTime, $zone);
            if ($end <= $start) {
                // Wie in der alten Kalenderansicht: Ende vor oder gleich Beginn heißt Ende am Folgetag.
                $end = $end->modify('+1 day');
            }
            $event->schedule($start, $end, false, $this->timezone);
        }

        $event->importSource = self::SOURCE;
        $event->importRef = (string) $row['id'];
        $event->published = 1 === (int) ($row['status'] ?? 1);
        $event->calendarId = $this->calendarFor((int) ($row['category'] ?? 0), $report);
        $venue = (int) ($row['venue'] ?? 0);
        $event->locationId = $this->locationMap[$venue] ?? null;
        if ($venue > 0 && null === $event->locationId) {
            $report->warning($subject, I18n::t('legacy_venue_gone', $venue));
        }

        [$translated, $custom, $translatedCustom] = $this->splitColumns($row, self::ENTRY_CORE, self::TRANSLATED_CORE);
        // Das Tagging-Feld von forcal 6 entspricht den Schlagwörtern (iCalendar: CATEGORIES).
        if (is_array($custom['tags'] ?? null)) {
            $event->categories = array_values(array_unique(array_filter(array_map(
                static fn (mixed $tag): string => trim((string) (is_array($tag) ? ($tag['text'] ?? $tag['name'] ?? $tag['label'] ?? '') : $tag)),
                $custom['tags'],
            ), static fn (string $tag): bool => '' !== $tag)));
        }
        unset($custom['tags']);

        // Werte, die nach einem früheren Import in scheduler gepflegt wurden, bleiben erhalten.
        $event->custom = [...$event->custom, ...$custom];
        foreach ($this->clangIds as $clangId) {
            $title = trim($translated['name'][$clangId] ?? '');
            $hasContent = '' !== $title || [] !== ($translatedCustom[$clangId] ?? []);
            if (!$hasContent && isset($event->translations[$clangId])) {
                unset($event->translations[$clangId]);
            }
            if (!$hasContent) {
                continue;
            }
            $translation = $event->translate($clangId);
            $translation->title = $title;
            $translation->teaser = $this->nullIfEmpty($translated['teaser'][$clangId] ?? '');
            $translation->description = $this->nullIfEmpty($translated['text'][$clangId] ?? '');
            $translation->custom = $translatedCustom[$clangId] ?? [];
        }
        if ([] === $event->translations || '' === $event->title()) {
            $event->translate($this->clangIds[0] ?? 1)->title = I18n::t('legacy_untitled', (string) $row['id']);
            $report->warning($subject, I18n::t('legacy_untitled_note', (string) $row['id']));
        }

        $this->mapRecurrence($event, $row, $report, $subject);
    }

    /**
     * Übersetzt die alte Wiederholung in eine RRULE und gleicht Abweichungen über RDATE und EXDATE aus,
     * damit nach dem Import exakt dieselben Tage erscheinen wie vorher.
     *
     * @param array<string, scalar|null> $row
     */
    private function mapRecurrence(Event $event, array $row, ImportReport $report, string $subject): void
    {
        $event->rrule = null;
        $event->exdates = [];
        $event->rdates = [];

        if ('repeat' === ($row['type'] ?? null) && !LegacyRecurrence::isSeries($row)) {
            $report->info($subject, I18n::t('legacy_no_end'));
        }
        if (!LegacyRecurrence::isSeries($row)) {
            return;
        }

        $event->rrule = LegacyRecurrence::rrule($row);
        $legacyDates = LegacyRecurrence::dates($row);

        // Verglichen wird die reine Regel; in scheduler gepflegte Einzelausnahmen bleiben außen vor.
        $plain = clone $event;
        $plain->overrides = [];
        $from = $event->dtstart->modify('-1 day');
        $to = new DateTimeImmutable($row['end_repeat_date'] . ' 23:59:59', new DateTimeZone($this->timezone))->modify('+2 days');
        $newDates = array_map(
            static fn ($occurrence): string => $occurrence->start->format('Y-m-d'),
            Scheduler::expander()->expand($plain, $from, $to, 10000),
        );

        $missing = array_values(array_diff($legacyDates, $newDates));
        $surplus = array_values(array_diff($newDates, $legacyDates));
        if ([] === $missing && [] === $surplus) {
            $report->count(I18n::t('count_series_identical'));

            return;
        }

        $time = $event->allDay ? '' : 'T' . $event->dtstart->format('His');
        $toKey = static fn (string $date): string => str_replace('-', '', $date) . $time;
        $event->rdates = array_map($toKey, $missing);
        $event->exdates = array_map($toKey, $surplus);

        $report->count(I18n::t('count_series_adjusted'));
        $report->warning($subject, I18n::t('legacy_series_adjusted', count($missing), count($surplus)));
    }

    private function calendarFor(int $legacyCategory, ImportReport $report): int
    {
        if (isset($this->calendarMap[$legacyCategory])) {
            return $this->calendarMap[$legacyCategory];
        }

        // Termine ohne oder mit gelöschter Kategorie landen in einem Sammelkalender.
        if (!isset($this->calendarMap[0])) {
            $calendar = Scheduler::calendars()->findBySlug('importiert') ?? new Calendar();
            if (null === $calendar->id) {
                $calendar->name = I18n::t('legacy_fallback_calendar');
                $calendar->slug = 'importiert';
                $calendar->timezone = $this->timezone;
                Scheduler::calendars()->save($calendar);
                $report->count(I18n::t('count_calendars_created'));
            }
            $this->calendarMap[0] = (int) $calendar->id;
        }

        return $this->calendarMap[0];
    }

    /**
     * Teilt eine Altzeile auf in übersetzte Kernfelder, einfache Custom Fields und übersetzte Custom Fields.
     *
     * @param array<string, scalar|null> $row
     * @param list<string> $core
     * @param list<string> $translatedCore
     * @return array{array<string, array<int, string>>, array<string, mixed>, array<int, array<string, mixed>>}
     */
    private function splitColumns(array $row, array $core, array $translatedCore): array
    {
        $translated = [];
        $custom = [];
        $translatedCustom = [];

        foreach ($row as $column => $value) {
            if (in_array($column, $core, true)) {
                continue;
            }
            if (1 === preg_match('/^(.+)_(\d+)$/', $column, $m) && in_array((int) $m[2], $this->clangIds, true)) {
                if (in_array($m[1], $translatedCore, true)) {
                    $translated[$m[1]][(int) $m[2]] = (string) $value;
                } elseif (null !== $value && '' !== $value) {
                    $translatedCustom[(int) $m[2]][$m[1]] = $this->decodeValue($value);
                }
                continue;
            }
            if (null !== $value && '' !== $value) {
                $custom[$column] = $this->decodeValue($value);
            }
        }

        return [$translated, $custom, $translatedCustom];
    }

    /**
     * @param array<int, array<string, mixed>> $translatedCustom
     * @return array<string, mixed>
     */
    private function flattenTranslated(array $translatedCustom): array
    {
        return [] === $translatedCustom ? [] : ['_translations' => $translatedCustom];
    }

    private function decodeValue(string|int|float|bool $value): mixed
    {
        if (is_string($value) && ('' !== $value) && ('[' === $value[0] || '{' === $value[0]) && json_validate($value)) {
            return json_decode($value, true);
        }
        // rex_form hat Mehrfachauswahlen als |a|b|c| gespeichert.
        if (is_string($value) && 1 === preg_match('/^\|.*\|$/', $value)) {
            return array_values(array_filter(explode('|', $value), static fn (string $part): bool => '' !== $part));
        }

        return $value;
    }

    /**
     * @param array<int, string> $values
     */
    private function firstNonEmpty(array $values): ?string
    {
        return array_find($values, static fn (string $value): bool => '' !== trim($value));
    }

    private function nullIfEmpty(string $value): ?string
    {
        return '' === trim($value) ? null : $value;
    }

    private function tableExists(string $name): bool
    {
        return [] !== $this->connection->fetchAll('SHOW TABLES LIKE ?', [$this->legacyTableName($name)]);
    }

    private function legacyTableName(string $name): string
    {
        return trim($this->connection->table('forcal_' . $name), '`');
    }

    private function legacyTable(string $name): string
    {
        return $this->connection->table('forcal_' . $name);
    }
}
