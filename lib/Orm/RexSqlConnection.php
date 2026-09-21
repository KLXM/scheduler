<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm;

use rex;
use rex_sql;

final class RexSqlConnection implements Connection
{
    private ?rex_sql $last = null;

    public function __construct(
        private readonly int $db = 1,
    ) {}

    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string, scalar|null>> */
        return rex_sql::factory($this->db)->getArray($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->last = rex_sql::factory($this->db);
        $this->last->setQuery($sql, $params);

        return (int) $this->last->getRows();
    }

    public function lastInsertId(): int
    {
        return (int) ($this->last?->getLastId() ?? 0);
    }

    public function transactional(callable $callback): mixed
    {
        // Verschachtelte Aufrufe laufen in der äußeren Transaktion mit.
        return rex_sql::factory($this->db)->transactional($callback);
    }

    public function table(string $name): string
    {
        return $this->quoteIdentifier(rex::getTable($name));
    }

    public function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
