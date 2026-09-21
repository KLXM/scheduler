<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Service;

use KLXM\Scheduler\I18n;
use DateTimeZone;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Recurrence\InvalidRuleException;
use KLXM\Scheduler\Recurrence\Rule;

/**
 * Prüft und normalisiert einen Termin vor dem Speichern. Gilt für jeden Schreibweg:
 * Editor, ICS-Import, CalDAV und Altdaten-Import.
 */
final class EventValidator
{
    private const string KEY_PATTERN = '/^\d{8}(T\d{6})?$/';

    /**
     * @throws ValidationException
     */
    public function validate(Event $event): void
    {
        $errors = [];

        if ($event->calendarId <= 0) {
            $errors['calendarId'] = I18n::t('error_needs_calendar');
        }

        $hasTitle = array_any($event->translations, static fn ($t): bool => '' !== trim($t->title));
        if (!$hasTitle) {
            $errors['title'] = I18n::t('error_needs_title');
        }

        if ($event->dtend < $event->dtstart) {
            $errors['dtend'] = I18n::t('error_end_before_start');
        }

        if (null !== $event->rrule) {
            try {
                $event->rrule = (string) Rule::parse($event->rrule)->normalizedFor($event->allDay, new DateTimeZone($event->timezone));
            } catch (InvalidRuleException $e) {
                $errors['rrule'] = $e->getMessage();
            }
        }

        foreach (['exdates' => $event->exdates, 'rdates' => $event->rdates] as $field => $keys) {
            foreach ($keys as $key) {
                if (1 !== preg_match(self::KEY_PATTERN, $key)) {
                    $errors[$field] = I18n::t('error_occurrence_key', $key);
                }
            }
        }

        if (null !== $event->url && '' !== $event->url && false === filter_var($event->url, FILTER_VALIDATE_URL)) {
            $errors['url'] = I18n::t('invalid_url');
        }

        if ([] !== $errors) {
            throw new ValidationException($errors);
        }
    }
}
