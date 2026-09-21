<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm;

/**
 * Verkettbarer Abfrage-Builder. Bedingungen verwenden Property-Namen, nie rohe Spaltennamen.
 *
 * @template T of object
 */
final class Query
{
    private const array OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];

    /** @var list<string> */
    private array $conditions = [];

    /** @var list<scalar|null> */
    private array $params = [];

    /** @var list<string> */
    private array $orders = [];

    private ?int $limit = null;
    private int $offset = 0;

    /**
     * @param EntityMetadata<T> $metadata
     * @param \Closure(list<array<string, scalar|null>>): list<T> $hydrate
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityMetadata $metadata,
        private readonly \Closure $hydrate,
    ) {}

    /**
     * where('status', Status::Confirmed) oder where('start', '>=', $date).
     *
     * @return $this
     */
    public function where(string $property, mixed $operatorOrValue, mixed $value = null): static
    {
        [$operator, $value] = 3 === func_num_args() ? [strtoupper((string) $operatorOrValue), $value] : ['=', $operatorOrValue];
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new OrmException(sprintf('Operator "%s" ist nicht erlaubt.', $operator));
        }

        $column = $this->metadata->column($property);
        if (null === $value) {
            $this->conditions[] = $this->identifier($column) . ('=' === $operator ? ' IS NULL' : ' IS NOT NULL');

            return $this;
        }

        $this->conditions[] = $this->identifier($column) . ' ' . $operator . ' ?';
        $this->params[] = Converter::toDatabase($column, $value);

        return $this;
    }

    /**
     * @param array<mixed> $values
     * @return $this
     */
    public function whereIn(string $property, array $values, bool $not = false): static
    {
        if ([] === $values) {
            $this->conditions[] = $not ? '1 = 1' : '1 = 0';

            return $this;
        }

        $column = $this->metadata->column($property);
        $this->conditions[] = sprintf(
            '%s %sIN (%s)',
            $this->identifier($column),
            $not ? 'NOT ' : '',
            implode(', ', array_fill(0, count($values), '?')),
        );
        foreach ($values as $value) {
            $this->params[] = Converter::toDatabase($column, $value);
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function whereNull(string $property): static
    {
        return $this->where($property, null);
    }

    /**
     * @return $this
     */
    public function whereNotNull(string $property): static
    {
        return $this->where($property, '!=', null);
    }

    /**
     * Freie Bedingung für Sonderfälle. {property} wird durch den maskierten Spaltennamen ersetzt.
     *
     * @param list<scalar|null> $params
     * @return $this
     */
    public function whereRaw(string $sql, array $params = []): static
    {
        $this->conditions[] = '(' . preg_replace_callback(
            '/\{(\w+)\}/',
            // Mit Tabellennamen, damit die Spalte auch in Unterabfragen eindeutig die äußere Tabelle meint.
            fn (array $m): string => $this->connection->table($this->metadata->table) . '.' . $this->identifier($this->metadata->column($m[1])),
            $sql,
        ) . ')';
        array_push($this->params, ...$params);

        return $this;
    }

    /**
     * @return $this
     */
    public function orderBy(string $property, string $direction = 'asc'): static
    {
        $direction = 'desc' === strtolower($direction) ? 'DESC' : 'ASC';
        $this->orders[] = $this->identifier($this->metadata->column($property)) . ' ' . $direction;

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
     * @return list<T>
     */
    public function get(): array
    {
        $sql = 'SELECT * FROM ' . $this->connection->table($this->metadata->table) . $this->whereSql();
        if ([] !== $this->orders) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        if (null !== $this->limit) {
            $sql .= ' LIMIT ' . $this->limit . ' OFFSET ' . $this->offset;
        }

        return ($this->hydrate)($this->connection->fetchAll($sql, $this->params));
    }

    /**
     * @return T|null
     */
    public function first(): ?object
    {
        return (clone $this)->limit(1, $this->offset)->get()[0] ?? null;
    }

    public function count(): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM ' . $this->connection->table($this->metadata->table) . $this->whereSql();

        return (int) ($this->connection->fetchAll($sql, $this->params)[0]['c'] ?? 0);
    }

    public function exists(): bool
    {
        return null !== $this->first();
    }

    /**
     * @return Page<T>
     */
    public function paginate(int $page = 1, int $perPage = 25): Page
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $total = $this->count();
        $items = (clone $this)->limit($perPage, ($page - 1) * $perPage)->get();

        return new Page($items, $total, $page, $perPage);
    }

    /**
     * Verarbeitet große Tabellen blockweise entlang des Primärschlüssels.
     *
     * @param callable(list<T>): void $callback
     */
    public function chunkById(int $size, callable $callback): void
    {
        $idColumn = $this->metadata->id;
        $lastId = 0;
        do {
            $query = clone $this;
            $query->orders = [];
            $items = $query->where($idColumn->property, '>', $lastId)
                ->orderBy($idColumn->property)
                ->limit($size)
                ->get();
            if ([] === $items) {
                return;
            }
            $callback($items);
            $lastId = (int) $idColumn->reflection->getValue($items[array_key_last($items)]);
        } while (count($items) === $size);
    }

    /**
     * Löscht alle Treffer direkt in der Datenbank, ohne Entities zu laden.
     */
    public function delete(): int
    {
        return $this->connection->execute(
            'DELETE FROM ' . $this->connection->table($this->metadata->table) . $this->whereSql(),
            $this->params,
        );
    }

    private function whereSql(): string
    {
        return [] === $this->conditions ? '' : ' WHERE ' . implode(' AND ', $this->conditions);
    }

    private function identifier(ColumnMetadata $column): string
    {
        return $this->connection->quoteIdentifier($column->column);
    }
}
