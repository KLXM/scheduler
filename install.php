<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Orm\SchemaManager;

/**
 * Das Schema entsteht vollständig aus den Entity-Attributen. scheduler hat eigene Tabellen
 * (rex_scheduler_*) und läuft neben forcal; dessen Tabellen liest nur der Importer.
 */
SchemaManager::ensure(Scheduler::ENTITIES);

