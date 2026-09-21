<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Tests\Redaxo;

use DateTimeImmutable;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Security\Access;
use KLXM\Scheduler\Security\CalendarPerm;
use PHPUnit\Framework\Attributes\Test;
use rex_sql;
use rex_user;

final class AccessTest extends RedaxoTestCase
{
    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $roleIds = [];

    protected function tearDown(): void
    {
        foreach ($this->userIds as $id) {
            rex_sql::factory()->setQuery('DELETE FROM ' . \rex::getTable('user') . ' WHERE id = ?', [$id]);
        }
        foreach ($this->roleIds as $id) {
            rex_sql::factory()->setQuery('DELETE FROM ' . \rex::getTable('user_role') . ' WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    /**
     * @param list<string> $options zusätzliche Rechte wie scheduler[publish]
     * @param list<int>|'all' $calendars
     */
    private function user(string $login, array $options, array|string $calendars): rex_user
    {
        \rex_complex_perm::register(CalendarPerm::KEY, CalendarPerm::class);

        $role = rex_sql::factory()->setTable(\rex::getTable('user_role'));
        $role->setValue('name', 'phpunit-' . $login);
        $role->setValue('perms', json_encode([
            'general' => '|scheduler[]|',
            'options' => [] === $options ? null : '|' . implode('|', $options) . '|',
            'extras' => null,
            CalendarPerm::KEY => 'all' === $calendars ? 'all' : '|' . implode('|', $calendars) . '|',
        ]));
        $role->addGlobalCreateFields('phpunit')->addGlobalUpdateFields('phpunit')->insert();
        $this->roleIds[] = (int) $role->getLastId();

        $user = rex_sql::factory()->setTable(\rex::getTable('user'));
        $user->setValue('login', $login)->setValue('name', $login)->setValue('password', 'x')->setValue('status', 1)->setValue('admin', 0)
            ->setValue('role', (string) $role->getLastId())->setValue('login_tries', 0);
        $user->addGlobalCreateFields('phpunit')->addGlobalUpdateFields('phpunit')->insert();
        $this->userIds[] = (int) $user->getLastId();

        rex_user::clearInstance((int) $user->getLastId());

        return rex_user::require((int) $user->getLastId());
    }

    private function eventBy(string $login): Event
    {
        $event = new Event(new DateTimeImmutable('2026-12-01 10:00'), new DateTimeImmutable('2026-12-01 11:00'));
        $event->calendarId = (int) $this->calendar->id;
        $event->translate(1)->title = 'Rechte-Test';
        Scheduler::events()->save($event);
        // Der Test läuft als Admin; der Ersteller wird für den Fall direkt gesetzt.
        rex_sql::factory()->setQuery('UPDATE ' . \rex::getTable('scheduler_event') . ' SET created_by = ? WHERE id = ?', [$login, (int) $event->id]);

        return Scheduler::events()->findOrFail((int) $event->id);
    }

    #[Test]
    public function editorWithoutExtraRightsMaintainsOnlyOwnEventsInAssignedCalendars(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $editor = $this->user('zzed' . $suffix, [], [(int) $this->calendar->id]);
        $own = $this->eventBy($editor->getLogin());
        $foreign = $this->eventBy('jemand-anders');

        self::assertTrue(Access::canEditCalendar((int) $this->calendar->id, $editor));
        self::assertFalse(Access::canEditCalendar(999999, $editor), 'nicht zugewiesener Kalender');
        self::assertTrue(Access::canEditEvent($own, $editor));
        self::assertFalse(Access::canEditEvent($foreign, $editor));
        self::assertFalse(Access::canDeleteEvent($own, $editor));
        self::assertFalse(Access::canPublish($editor));
    }

    #[Test]
    public function chiefEditorMayEditForeignPublishAndDelete(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $chief = $this->user('zzch' . $suffix, [Access::PERM_EDIT_FOREIGN, Access::PERM_PUBLISH, Access::PERM_DELETE], 'all');
        $foreign = $this->eventBy('jemand-anders');

        self::assertTrue(Access::canEditEvent($foreign, $chief));
        self::assertTrue(Access::canDeleteEvent($foreign, $chief));
        self::assertTrue(Access::canPublish($chief));
    }

    #[Test]
    public function userWithoutSchedulerPermissionGetsNothing(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $role = rex_sql::factory()->setTable(\rex::getTable('user_role'));
        $role->setValue('name', 'phpunit-none')->setValue('perms', json_encode(['general' => null, 'options' => null, 'extras' => null]));
        $role->addGlobalCreateFields('phpunit')->addGlobalUpdateFields('phpunit')->insert();
        $this->roleIds[] = (int) $role->getLastId();
        $sql = rex_sql::factory()->setTable(\rex::getTable('user'));
        $sql->setValue('login', 'zzno' . $suffix)->setValue('password', 'x')->setValue('status', 1)->setValue('admin', 0)->setValue('role', (string) $role->getLastId())->setValue('login_tries', 0);
        $sql->addGlobalCreateFields('phpunit')->addGlobalUpdateFields('phpunit')->insert();
        $this->userIds[] = (int) $sql->getLastId();

        $nobody = rex_user::require((int) $sql->getLastId());

        self::assertFalse(Access::canUse($nobody));
        self::assertFalse(Access::canEditCalendar((int) $this->calendar->id, $nobody));
        self::assertSame([], Access::editableCalendars($nobody));
    }
}
