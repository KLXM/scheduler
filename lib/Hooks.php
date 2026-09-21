<?php

declare(strict_types=1);

namespace KLXM\Scheduler;

/**
 * Leitet Domänenereignisse an REDAXO Extension Points weiter, sofern REDAXO läuft.
 */
final class Hooks
{
    public const string EVENT_SAVED = 'SCHEDULER_EVENT_SAVED';
    public const string EVENT_DELETED = 'SCHEDULER_EVENT_DELETED';

    /**
     * @param array<string, mixed> $params
     */
    public static function dispatch(string $name, mixed $subject, array $params = []): void
    {
        if (class_exists(\rex_extension::class, false)) {
            \rex_extension::registerPoint(new \rex_extension_point($name, $subject, $params));
        }
    }
}
