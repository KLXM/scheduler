<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Import;

/**
 * Ergebnis eines Importlaufs. Wird vom Importer fortgeschrieben und am Ende angezeigt.
 */
final class ImportReport
{
    /** @var array<string, int> Zähler je Kategorie, etwa "Termine angelegt" */
    public private(set) array $counts = [];

    /** @var list<array{level: 'info'|'warning'|'error', subject: string, message: string}> */
    public private(set) array $messages = [];

    public function __construct(
        public readonly bool $dryRun,
    ) {}

    public function count(string $what, int $by = 1): void
    {
        $this->counts[$what] = ($this->counts[$what] ?? 0) + $by;
    }

    public function info(string $subject, string $message): void
    {
        $this->messages[] = ['level' => 'info', 'subject' => $subject, 'message' => $message];
    }

    public function warning(string $subject, string $message): void
    {
        $this->messages[] = ['level' => 'warning', 'subject' => $subject, 'message' => $message];
    }

    public function error(string $subject, string $message): void
    {
        $this->messages[] = ['level' => 'error', 'subject' => $subject, 'message' => $message];
    }

    public bool $hasErrors {
        get => array_any($this->messages, static fn (array $m): bool => 'error' === $m['level']);
    }
}
