<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Tests\Redaxo;

use KLXM\Scheduler\Domain\Calendar;
use KLXM\Scheduler\Scheduler;
use PHPUnit\Framework\TestCase;

/**
 * Basis für Tests gegen die echte Datenbank. Jeder Test läuft in einem eigenen Kalender,
 * der samt Terminen wieder entfernt wird.
 */
abstract class RedaxoTestCase extends TestCase
{
    protected Calendar $calendar;

    protected function setUp(): void
    {
        if (!class_exists(\rex::class, false)) {
            self::markTestSkipped('Braucht ein laufendes REDAXO (SCHEDULER_REDAXO_BOOT).');
        }

        $this->calendar = new Calendar();
        $this->calendar->name = 'PHPUnit ' . bin2hex(random_bytes(4));
        Scheduler::calendars()->save($this->calendar);
    }

    protected function tearDown(): void
    {
        if (!isset($this->calendar) || null === $this->calendar->id) {
            return;
        }
        foreach (Scheduler::events()->query()->where('calendarId', $this->calendar->id)->get() as $event) {
            Scheduler::events()->delete($event);
        }
        Scheduler::calendars()->delete($this->calendar);
    }
}
