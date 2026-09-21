<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Repository;

use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Domain\Calendar;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Orm\Repository;
use KLXM\Scheduler\Service\ValidationException;

/**
 * @extends Repository<Calendar>
 */
final class CalendarRepository extends Repository
{
    public function findBySlug(string $slug): ?Calendar
    {
        return $this->query()->where('slug', $slug)->first();
    }

    /**
     * @return list<Calendar>
     */
    public function all(bool $onlyActive = false): array
    {
        $query = $this->query()->orderBy('priority')->orderBy('name');

        return ($onlyActive ? $query->where('active', true) : $query)->get();
    }

    protected function beforeSave(object $entity, bool $isNew): void
    {
        $errors = [];
        if ('' === trim($entity->name)) {
            $errors['name'] = I18n::t('error_calendar_name');
        }
        if (!in_array($entity->timezone, \DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = I18n::t('error_timezone');
        }
        if ([] !== $errors) {
            throw new ValidationException($errors);
        }

        $entity->slug = $this->uniqueSlug('' !== $entity->slug ? $entity->slug : $entity->name, $entity->id);
    }

    protected function beforeDelete(object $entity): void
    {
        if ($this->manager->repository(Event::class)->query()->where('calendarId', (int) $entity->id)->exists()) {
            throw new ValidationException(['calendar' => I18n::t('calendar_delete_blocked')]);
        }
    }

    private function uniqueSlug(string $source, ?int $ownId): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($source, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue']))), '-');
        $base = '' === $base ? 'kalender' : substr($base, 0, 80);

        $slug = $base;
        for ($i = 2; ; ++$i) {
            $existing = $this->findBySlug($slug);
            if (null === $existing || $existing->id === $ownId) {
                return $slug;
            }
            $slug = $base . '-' . $i;
        }
    }
}
