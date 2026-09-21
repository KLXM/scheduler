<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Import\Legacy;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Orm\Connection;

/**
 * Sucht in Modulen und Templates nach Aufrufen der forcal-6-API und nennt das neue Gegenstück.
 * Ersetzt eine Kompatibilitätsschicht: nichts Altes läuft weiter, aber alles Alte wird gefunden.
 *
 * @internal
 */
final class CodeScanner
{
    /** @var array<string, string> Regex => Hinweis */
    private const array PATTERNS = [
        '/forCalEventsFactory/i' => 'Scheduler::occurrences()->between(...)->inCalendars(...)->get()',
        '/forCalHandler::exchangeEntries|forCalHandler::getEntries/i' => 'Scheduler::occurrences()->between($from, $to)->get()',
        '/forCalHandler::(exchangeEntry|getEntry)\b/i' => 'Scheduler::events()->find($id)',
        '/forCalHandler/i' => 'Scheduler::occurrences(), Scheduler::events()',
        '/forCalLink/i' => 'Frontend::icsUrl($event)',
        '/forcal_ical/i' => 'rex-api-call=scheduler_feed&calendar=KURZNAME',
        '/forcal_exchange/i' => 'rex-api-call=scheduler_events',
        '/forCalTaggingHelper/i' => '$event->categories, $event->custom(\'field\')',
        '/\bforCal\\\\/' => 'KLXM\\Scheduler',
        '/rex_forcal_(entries|categories|venues)|forcal_(entries|categories|venues)\b/i' => 'rex_scheduler_event, rex_scheduler_calendar, rex_scheduler_location',
        '/\bentry_(start_date|end_date|start_time|end_time|name|teaser|text)\b/' => '$occurrence->start, $occurrence->end, $occurrence->title(), $event->translation()->teaser',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * @return list<array{where: string, name: string, line: int, code: string, hint: string, url: string}>
     */
    public function scan(): array
    {
        $findings = [];
        $sources = [
            ['module', I18n::t('modules_module'), ['input' => I18n::t('scanner_input'), 'output' => I18n::t('scanner_output')], 'modules/modules', 'module_id'],
            ['template', 'Template', ['content' => I18n::t('event_content')], 'templates', 'template_id'],
        ];

        foreach ($sources as [$table, $label, $columns, $page, $idParam]) {
            foreach ($this->connection->fetchAll('SELECT * FROM ' . $this->connection->table($table)) as $row) {
                foreach ($columns as $column => $columnLabel) {
                    foreach (preg_split('/\R/', (string) ($row[$column] ?? '')) ?: [] as $index => $line) {
                        foreach (self::PATTERNS as $pattern => $hint) {
                            if (1 === preg_match($pattern, $line)) {
                                $findings[] = [
                                    'where' => $label . ' (' . $columnLabel . ')',
                                    'name' => (string) ($row['name'] ?? $row['id']),
                                    'line' => $index + 1,
                                    'code' => mb_strimwidth(trim($line), 0, 160, '…'),
                                    'hint' => $hint,
                                    'url' => \rex_url::backendPage($page, ['function' => 'edit', $idParam => $row['id']], false),
                                ];
                                break;
                            }
                        }
                    }
                }
            }
        }

        return $findings;
    }
}
