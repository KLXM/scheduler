<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Repository;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Domain\Calendar;
use KLXM\Scheduler\Domain\DavChange;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Domain\EventOverride;
use KLXM\Scheduler\Domain\EventTranslation;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Hooks;
use KLXM\Scheduler\Orm\MetadataFactory;
use KLXM\Scheduler\Orm\Repository;
use KLXM\Scheduler\Service\ValidationException;

/**
 * @extends Repository<Event>
 */
final class EventRepository extends Repository
{
    private ?int $previousCalendarId = null;

    public function findByDavUri(int $calendarId, string $uri): ?Event
    {
        return $this->query()->where('calendarId', $calendarId)->where('davUri', $uri)->first();
    }

    public function findByUid(string $uid): ?Event
    {
        return $this->query()->where('uid', $uid)->first();
    }

    public function findByImportRef(string $source, string $ref): ?Event
    {
        return $this->query()->where('importSource', $source)->where('importRef', $ref)->first();
    }

    public function save(object $entity): void
    {
        $this->manager->transactional(fn () => parent::save($entity));
        Hooks::dispatch(Hooks::EVENT_SAVED, $entity);
    }

    public function insertWithId(object $entity, int $id): void
    {
        $this->manager->transactional(fn () => parent::insertWithId($entity, $id));
        Hooks::dispatch(Hooks::EVENT_SAVED, $entity);
    }

    public function delete(object $entity): void
    {
        $this->manager->transactional(fn () => parent::delete($entity));
        Hooks::dispatch(Hooks::EVENT_DELETED, $entity);
    }

    protected function afterLoad(array $entities): void
    {
        if ([] === $entities) {
            return;
        }

        $byId = [];
        foreach ($entities as $event) {
            $byId[(int) $event->id] = $event;
        }
        $ids = array_keys($byId);

        foreach ($this->manager->repository(EventTranslation::class)->query()->whereIn('eventId', $ids)->orderBy('clangId')->get() as $translation) {
            $byId[$translation->eventId]->translations[$translation->clangId] = $translation;
        }
        foreach ($this->manager->repository(EventOverride::class)->query()->whereIn('eventId', $ids)->get() as $override) {
            $event = $byId[$override->eventId];
            $event->overrides[$event->recurrenceKey($override->recurrenceId)] = $override;
        }
    }

    protected function beforeSave(object $entity, bool $isNew): void
    {
        if ('' === $entity->uid) {
            $entity->uid = self::generateUid();
        }

        Scheduler::validator()->validate($entity);

        if (null === $this->manager->repository(Calendar::class)->find($entity->calendarId)) {
            throw new ValidationException(['calendarId' => I18n::t('error_calendar_missing')]);
        }

        $this->pruneOrphanedOverrides($entity);

        if (!$isNew) {
            ++$entity->sequence;
        }
        $entity->etag = bin2hex(random_bytes(16));
        $entity->davUri ??= preg_replace('/[^A-Za-z0-9._@-]+/', '-', $entity->uid) . '.ics';

        $this->previousCalendarId = $isNew ? null : (int) ($this->manager->connection->fetchAll(
            'SELECT `calendar_id` FROM ' . $this->manager->connection->table($this->metadata->table) . ' WHERE `id` = ?',
            [(int) $entity->id],
        )[0]['calendar_id'] ?? $entity->calendarId);
    }

    protected function afterSave(object $entity, bool $isNew): void
    {
        $eventId = (int) $entity->id;

        $translations = $this->manager->repository(EventTranslation::class);
        $keep = [];
        foreach ($entity->translations as $translation) {
            $translation->eventId = $eventId;
            $translations->save($translation);
            $keep[] = (int) $translation->id;
        }
        $translations->query()->where('eventId', $eventId)->whereIn('id', $keep, not: true)->delete();

        $overrides = $this->manager->repository(EventOverride::class);
        $keep = [];
        foreach ($entity->overrides as $override) {
            $override->eventId = $eventId;
            $override->timezone = $entity->timezone;
            $overrides->save($override);
            $keep[] = (int) $override->id;
        }
        $overrides->query()->where('eventId', $eventId)->whereIn('id', $keep, not: true)->delete();

        // Wechselt ein Termin den Kalender, verschwindet er für CalDAV-Clients aus dem alten.
        if (null !== $this->previousCalendarId && $this->previousCalendarId !== $entity->calendarId) {
            $this->recordChange($entity, DavChange::DELETED, $this->previousCalendarId);
            $this->recordChange($entity, DavChange::ADDED);
        } else {
            $this->recordChange($entity, $isNew ? DavChange::ADDED : DavChange::MODIFIED);
        }
        Scheduler::indexer()->reindex($entity);
    }

    protected function afterDelete(object $entity, int $id): void
    {
        $this->manager->repository(EventTranslation::class)->query()->where('eventId', $id)->delete();
        $this->manager->repository(EventOverride::class)->query()->where('eventId', $id)->delete();
        Scheduler::indexer()->remove($id);
        $this->recordChange($entity, DavChange::DELETED);
    }

    /**
     * Ändert sich die Regel, können Einzelausnahmen auf Vorkommen zeigen, die es nicht mehr gibt.
     * Solche Ausnahmen würden als Geistertermine weiterleben und werden deshalb entfernt.
     */
    private function pruneOrphanedOverrides(Event $event): void
    {
        if ([] === $event->overrides) {
            return;
        }
        if (!$event->isRecurring) {
            $event->overrides = [];

            return;
        }

        $plain = clone $event;
        $plain->overrides = [];
        $plain->exdates = [];
        $ids = array_map(static fn (EventOverride $override): \DateTimeImmutable => $override->recurrenceId, array_values($event->overrides));
        $valid = [];
        foreach (Scheduler::expander()->expand($plain, min($ids)->modify('-1 day'), max($ids)->modify('+1 day'), 100000) as $occurrence) {
            $valid[$occurrence->recurrenceKey] = true;
        }
        $event->overrides = array_intersect_key($event->overrides, $valid);
    }

    /**
     * Erhöht den Synchronisationszähler des Kalenders und protokolliert die Änderung für CalDAV-Clients.
     */
    private function recordChange(Event $event, int $operation, ?int $calendarId = null): void
    {
        $calendarId ??= $event->calendarId;
        $connection = $this->manager->connection;
        $calendarTable = $connection->table(MetadataFactory::for(Calendar::class)->table);
        $connection->execute('UPDATE ' . $calendarTable . ' SET `sync_token` = `sync_token` + 1 WHERE `id` = ?', [$calendarId]);
        $token = (int) ($connection->fetchAll('SELECT `sync_token` FROM ' . $calendarTable . ' WHERE `id` = ?', [$calendarId])[0]['sync_token'] ?? 1);

        $connection->execute(
            'INSERT INTO ' . $connection->table(MetadataFactory::for(DavChange::class)->table) . ' (`calendar_id`, `uri`, `sync_token`, `operation`) VALUES (?, ?, ?, ?)',
            [$calendarId, (string) $event->davUri, $token, $operation],
        );
    }

    public static function generateUid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
