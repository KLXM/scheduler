<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Orm;

/**
 * Schmale Datenbank-Abstraktion, damit das ORM ohne laufendes REDAXO testbar bleibt.
 */
interface Connection
{
    /**
     * @param array<int|string, scalar|null> $params
     * @return list<array<string, scalar|null>>
     */
    public function fetchAll(string $sql, array $params = []): array;

    /**
     * @param array<int|string, scalar|null> $params
     * @return int Anzahl betroffener Zeilen
     */
    public function execute(string $sql, array $params = []): int;

    public function lastInsertId(): int;

    /**
     * @template R
     * @param callable(): R $callback
     * @return R
     */
    public function transactional(callable $callback): mixed;

    /** Vollständiger Tabellenname inklusive Präfix, bereits als Identifier maskiert. */
    public function table(string $name): string;

    public function quoteIdentifier(string $name): string;
}
