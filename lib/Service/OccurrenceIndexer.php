<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Service;

use DateTimeImmutable;
use DateTimeZone;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Domain\Occurrence;
use KLXM\Scheduler\Orm\EntityManager;
use KLXM\Scheduler\Orm\MetadataFactory;
use KLXM\Scheduler\Recurrence\Expander;
use KLXM\Scheduler\Settings;

/**
 * Pflegt die Tabelle der vorberechneten Vorkommen.
 *
 * Einzeltermine stehen immer im Index. Serien werden bis zum Planungshorizont berechnet;
 * ein Cronjob schiebt den Horizont rollierend weiter.
 */
final class OccurrenceIndexer
{
    private const int INSERT_CHUNK = 500;

    public function __construct(
        private readonly EntityManager $manager,
        private readonly Settings $settings,
        private readonly Expander $expander = new Expander(),
    ) {}

    /**
     * @return int Anzahl geschriebener Vorkommen
     */
    public function reindex(Event $event, ?DateTimeImmutable $now = null): int
    {
        $eventId = $event->id ?? throw new \LogicException('Nur gespeicherte Termine lassen sich indexieren.');
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $connection = $this->manager->connection;
        $table = $connection->table(MetadataFactory::for(Occurrence::class)->table);

        [$from, $to] = $this->window($event, $now);
        $occurrences = $this->expander->expand($event, $from, $to, $this->settings->maxOccurrencesPerEvent());

        return $this->manager->transactional(function () use ($connection, $table, $event, $eventId, $occurrences, $to): int {
            $connection->execute('DELETE FROM ' . $table . ' WHERE `event_id` = ?', [$eventId]);

            $utc = new DateTimeZone('UTC');
            foreach (array_chunk($occurrences, self::INSERT_CHUNK) as $chunk) {
                $params = [];
                foreach ($chunk as $occurrence) {
                    array_push(
                        $params,
                        $eventId,
                        $event->calendarId,
                        $event->timezone,
                        $occurrence->start->setTimezone($utc)->format('Y-m-d H:i:s'),
                        $occurrence->end->setTimezone($utc)->format('Y-m-d H:i:s'),
                        $occurrence->start->format('Y-m-d H:i:s'),
                        $occurrence->end->format('Y-m-d H:i:s'),
                        $occurrence->allDay ? 1 : 0,
                        $occurrence->recurrenceKey,
                        $occurrence->isOverride ? 1 : 0,
                    );
                }
                $connection->execute(
                    'INSERT INTO ' . $table . ' (`event_id`, `calendar_id`, `timezone`, `start_utc`, `end_utc`, `start`, `end`, `all_day`, `recurrence_key`, `is_override`) VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')),
                    $params,
                );
            }

            // Direkt per SQL, damit das Setzen des Horizonts kein erneutes Speichern mit Reindex auslöst.
            $connection->execute(
                'UPDATE ' . $connection->table(MetadataFactory::for(Event::class)->table) . ' SET `indexed_until` = ? WHERE `id` = ?',
                [$event->isRecurring ? $to->setTimezone($utc)->format('Y-m-d H:i:s') : null, $eventId],
            );

            return count($occurrences);
        });
    }

    /**
     * @return array{events: int, occurrences: int}
     */
    public function reindexAll(): array
    {
        $result = ['events' => 0, 'occurrences' => 0];
        $this->manager->repository(Event::class)->query()->chunkById(200, function (array $events) use (&$result): void {
            foreach ($events as $event) {
                ++$result['events'];
                $result['occurrences'] += $this->reindex($event);
            }
        });

        return $result;
    }

    /**
     * Berechnet Serien neu, deren Index weniger als den halben Planungshorizont vorausreicht.
     * Gedacht für einen regelmäßigen Cronjob.
     *
     * @return array{events: int, occurrences: int}
     */
    public function extendHorizon(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $threshold = $now->modify('+' . intdiv($this->settings->horizonAheadMonths(), 2) . ' months');

        $result = ['events' => 0, 'occurrences' => 0];
        $this->manager->repository(Event::class)->query()
            ->whereNotNull('indexedUntil')
            ->where('indexedUntil', '<', $threshold)
            ->chunkById(200, function (array $events) use (&$result, $now): void {
                foreach ($events as $event) {
                    ++$result['events'];
                    $result['occurrences'] += $this->reindex($event, $now);
                }
            });

        return $result;
    }

    public function remove(int $eventId): void
    {
        $this->manager->connection->execute(
            'DELETE FROM ' . $this->manager->connection->table(MetadataFactory::for(Occurrence::class)->table) . ' WHERE `event_id` = ?',
            [$eventId],
        );
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function window(Event $event, DateTimeImmutable $now): array
    {
        if (!$event->isRecurring) {
            return [$event->dtstart->modify('-1 day'), $event->dtend->modify('+1 day')];
        }

        $from = $event->dtstart->modify('-1 day');
        $back = $now->modify('-' . $this->settings->horizonBackMonths() . ' months');
        // Sehr alte, endlose Serien werden nicht bis zu ihrem Beginn zurück berechnet.
        if ($from < $back && null !== $event->rrule && !str_contains($event->rrule, 'COUNT=')) {
            $from = $back;
        }

        return [$from, $now->modify('+' . $this->settings->horizonAheadMonths() . ' months')];
    }
}
