<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Query;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use KLXM\Scheduler\Domain\Calendar;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Domain\EventTranslation;
use KLXM\Scheduler\Domain\Occurrence;
use KLXM\Scheduler\Orm\EntityManager;
use KLXM\Scheduler\Orm\Hydrator;
use KLXM\Scheduler\Orm\MetadataFactory;
use KLXM\Scheduler\Orm\Page;

/**
 * Bereichsabfrage über die vorberechneten Vorkommen. Filtert und paginiert vollständig in SQL;
 * Termine, Übersetzungen und Ausnahmen werden anschließend gesammelt nachgeladen.
 *
 *     Scheduler::occurrences()->upcoming()->inCalendars(1, 2)->limit(10)->get();
 */
final class OccurrenceQuery
{
    /** @var list<string> */
    private array $conditions = [];

    /** @var list<scalar|null> */
    private array $params = [];

    private bool $onlyPublished = true;
    private string $direction = 'ASC';
    private ?int $limit = null;
    private int $offset = 0;

    public function __construct(
        private readonly EntityManager $manager,
        private readonly string $defaultTimezone = 'Europe/Berlin',
        /** @var (\Closure(string): ?string)|null liefert zu einem Custom Field die indexierte Spalte, falls vorhanden */
        private readonly ?\Closure $indexColumn = null,
    ) {}

    /**
     * Vorkommen, die den Zeitraum berühren. Zeichenketten werden in der Standardzeitzone gelesen.
     *
     * @return $this
     */
    public function between(DateTimeInterface|string $from, DateTimeInterface|string $to): static
    {
        return $this->from($from)->until($to);
    }

    /**
     * @return $this
     */
    public function from(DateTimeInterface|string $from): static
    {
        // Termine ohne Dauer haben Beginn gleich Ende und zählen ab ihrem Beginn.
        $this->conditions[] = '(o.`end_utc` > ? OR (o.`end_utc` = o.`start_utc` AND o.`start_utc` >= ?))';
        $value = $this->utc($from);
        array_push($this->params, $value, $value);

        return $this;
    }

    /**
     * @return $this
     */
    public function until(DateTimeInterface|string $to): static
    {
        $this->conditions[] = 'o.`start_utc` < ?';
        $this->params[] = $this->utc($to);

        return $this;
    }

    /**
     * Laufende und künftige Vorkommen ab jetzt.
     *
     * @return $this
     */
    public function upcoming(): static
    {
        return $this->from(new DateTimeImmutable('now'));
    }

    /**
     * @return $this
     */
    public function inCalendars(int ...$calendarIds): static
    {
        return $this->in('o.`calendar_id`', $calendarIds);
    }

    /**
     * @return $this
     */
    public function forEvents(int ...$eventIds): static
    {
        return $this->in('o.`event_id`', $eventIds);
    }

    /**
     * @return $this
     */
    public function atLocations(int ...$locationIds): static
    {
        return $this->in('e.`location_id`', $locationIds);
    }

    /**
     * @return $this
     */
    public function withCategory(string $category): static
    {
        $this->conditions[] = 'JSON_CONTAINS(e.`categories`, JSON_QUOTE(?))';
        $this->params[] = $category;

        return $this;
    }

    /**
     * Filter auf ein Custom Field des Termins.
     *
     * @return $this
     */
    public function whereCustom(string $field, string|int|float|bool $value): static
    {
        if (1 !== preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
            throw new \InvalidArgumentException(sprintf('Ungültiger Feldname "%s".', $field));
        }
        $column = null !== $this->indexColumn ? ($this->indexColumn)($field) : null;
        $this->conditions[] = null !== $column
            ? 'e.' . $this->manager->connection->quoteIdentifier($column) . ' = ?'
            : "JSON_UNQUOTE(JSON_EXTRACT(e.`custom`, '$." . $field . "')) = ?";
        $this->params[] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        return $this;
    }

    /**
     * Volltextsuche in Titel, Teaser und Beschreibung.
     *
     * @return $this
     */
    public function search(string $term, ?int $clangId = null): static
    {
        $term = trim($term);
        if ('' === $term) {
            return $this;
        }

        $like = '%' . addcslashes($term, '%_\\') . '%';
        $sql = 'EXISTS (SELECT 1 FROM ' . $this->table(EventTranslation::class) . ' t WHERE t.`event_id` = e.`id`'
            . (null !== $clangId ? ' AND t.`clang_id` = ?' : '')
            . ' AND (t.`title` LIKE ? OR t.`teaser` LIKE ? OR t.`description` LIKE ?))';
        $this->conditions[] = $sql;
        if (null !== $clangId) {
            $this->params[] = $clangId;
        }
        array_push($this->params, $like, $like, $like);

        return $this;
    }

    /**
     * Bezieht auch Offline-Termine und inaktive Kalender ein (Backend).
     *
     * @return $this
     */
    public function includeUnpublished(bool $include = true): static
    {
        $this->onlyPublished = !$include;

        return $this;
    }

    /**
     * @return $this
     */
    public function latestFirst(bool $descending = true): static
    {
        $this->direction = $descending ? 'DESC' : 'ASC';

        return $this;
    }

    /**
     * @return $this
     */
    public function limit(?int $limit, int $offset = 0): static
    {
        $this->limit = null === $limit ? null : max(0, $limit);
        $this->offset = max(0, $offset);

        return $this;
    }

    /**
     * @return list<Occurrence>
     */
    public function get(): array
    {
        $sql = 'SELECT o.* ' . $this->fromSql()
            . ' ORDER BY o.`start_utc` ' . $this->direction . ', o.`id` ' . $this->direction;
        if (null !== $this->limit) {
            $sql .= ' LIMIT ' . $this->limit . ' OFFSET ' . $this->offset;
        }

        $metadata = MetadataFactory::for(Occurrence::class);
        $occurrences = array_map(
            static fn (array $row): Occurrence => Hydrator::hydrate($metadata, $row),
            $this->manager->connection->fetchAll($sql, $this->params),
        );

        $events = $this->manager->repository(Event::class)->findMany(array_map(static fn (Occurrence $o): int => $o->eventId, $occurrences));
        foreach ($occurrences as $occurrence) {
            $occurrence->event = $events[$occurrence->eventId] ?? null;
            $occurrence->override = $occurrence->isOverride ? ($occurrence->event?->overrides[$occurrence->recurrenceKey] ?? null) : null;
        }

        return $occurrences;
    }

    public function first(): ?Occurrence
    {
        return (clone $this)->limit(1, $this->offset)->get()[0] ?? null;
    }

    public function count(): int
    {
        return (int) ($this->manager->connection->fetchAll('SELECT COUNT(*) AS c ' . $this->fromSql(), $this->params)[0]['c'] ?? 0);
    }

    /**
     * @return Page<Occurrence>
     */
    public function paginate(int $page = 1, int $perPage = 25): Page
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        return new Page((clone $this)->limit($perPage, ($page - 1) * $perPage)->get(), $this->count(), $page, $perPage);
    }

    private function fromSql(): string
    {
        $conditions = $this->conditions;
        if ($this->onlyPublished) {
            $conditions[] = 'e.`published` = 1';
            $conditions[] = 'c.`active` = 1';
        }

        return 'FROM ' . $this->table(Occurrence::class) . ' o'
            . ' INNER JOIN ' . $this->table(Event::class) . ' e ON e.`id` = o.`event_id`'
            . ' INNER JOIN ' . $this->table(Calendar::class) . ' c ON c.`id` = o.`calendar_id`'
            . ([] === $conditions ? '' : ' WHERE ' . implode(' AND ', $conditions));
    }

    /**
     * @param list<int> $ids
     * @return $this
     */
    private function in(string $column, array $ids): static
    {
        if ([] === $ids) {
            return $this;
        }
        $this->conditions[] = $column . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
        array_push($this->params, ...$ids);

        return $this;
    }

    /**
     * @param class-string $entity
     */
    private function table(string $entity): string
    {
        return $this->manager->connection->table(MetadataFactory::for($entity)->table);
    }

    private function utc(DateTimeInterface|string $value): string
    {
        $dateTime = is_string($value)
            ? new DateTimeImmutable($value, new DateTimeZone($this->defaultTimezone))
            : DateTimeImmutable::createFromInterface($value);

        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
