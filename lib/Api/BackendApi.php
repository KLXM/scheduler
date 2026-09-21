<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Api;

use KLXM\Scheduler\I18n;
use DateTimeImmutable;
use DateTimeZone;
use KLXM\Scheduler\Backend\CustomFields;
use KLXM\Scheduler\Backend\Geo;
use KLXM\Scheduler\Color;
use KLXM\Scheduler\Domain\Calendar;
use KLXM\Scheduler\Domain\Enum\SchemaTarget;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Domain\Location;
use KLXM\Scheduler\Domain\Occurrence;
use KLXM\Scheduler\Field\FormRenderer;
use KLXM\Scheduler\Field\Schema;
use KLXM\Scheduler\Field\Type\YformType;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Recurrence\EditScope;
use KLXM\Scheduler\Recurrence\InvalidRuleException;
use KLXM\Scheduler\Recurrence\Rule;
use KLXM\Scheduler\Recurrence\SeriesEditor;
use KLXM\Scheduler\Security\Access;
use KLXM\Scheduler\Service\ValidationException;
use rex_api_function;
use rex_clang;
use rex_response;
use rex_url;

/**
 * JSON-Schnittstelle für die Backend-Oberfläche (Kalenderansicht, Editor, Formbuilder).
 * Nur mit Backend-Session erreichbar; schreibende Aktionen sind CSRF-geschützt.
 */
final class BackendApi extends rex_api_function
{
    public const string NAME = 'scheduler';

    private const array READ_ACTIONS = ['occurrences', 'rrulePreview', 'fieldUsage', 'schemaPreview', 'yformOptions'];

    protected $published = false;

    public function execute(): never
    {
        rex_response::cleanOutputBuffers();
        $action = rex_request('action', 'string');

        try {
            if (!Access::canUse()) {
                throw new ApiException(I18n::t('api_forbidden'), 403);
            }

            $payload = $this->payload();
            $result = match ($action) {
                'occurrences' => $this->occurrences(),
                'rrulePreview' => $this->rrulePreview($payload),
                'fieldUsage' => ['count' => Scheduler::schemas()->usage(
                    SchemaTarget::tryFrom(rex_request('target', 'string')) ?? SchemaTarget::Event,
                    rex_request('field', 'string'),
                )],
                'schemaPreview' => $this->schemaPreview($payload),
                'quickCreate' => $this->quickCreate($payload),
                'createLocation' => $this->createLocation($payload),
                'createCalendar' => $this->createCalendar($payload),
                'yformOptions' => $this->yformOptions(),
                'move' => $this->move($payload),
                'remove' => $this->remove($payload),
                default => throw new ApiException(I18n::t('api_unknown_action', $action), 400),
            };
            rex_response::sendJson($result);
        } catch (ApiException $e) {
            rex_response::setStatus((string) $e->getCode());
            rex_response::sendJson(['error' => $e->getMessage()]);
        } catch (ValidationException $e) {
            rex_response::setStatus('422');
            rex_response::sendJson(['error' => $e->getMessage(), 'fields' => $e->errors]);
        }
        exit;
    }

    protected function requiresCsrfProtection(): bool
    {
        return !in_array(rex_request('action', 'string'), self::READ_ACTIONS, true);
    }

    /**
     * @return list<array<string, mixed>> Ereignisobjekte im Format von FullCalendar
     */
    private function occurrences(): array
    {
        $query = Scheduler::occurrences()
            ->includeUnpublished()
            ->between(new DateTimeImmutable(rex_request('start', 'string')), new DateTimeImmutable(rex_request('end', 'string')));

        $calendarIds = array_filter(array_map(intval(...), rex_request('calendars', 'array', [])));
        if ([] !== $calendarIds) {
            $query->inCalendars(...$calendarIds);
        }

        $calendars = [];
        foreach (Scheduler::calendars()->all() as $calendar) {
            $calendars[(int) $calendar->id] = $calendar;
        }
        $clangId = rex_clang::getCurrentId();

        return array_map(static function (Occurrence $occurrence) use ($calendars, $clangId): array {
            $calendar = $calendars[$occurrence->calendarId] ?? null;
            $event = $occurrence->event;
            $editable = null !== $event && Access::canEditEvent($event);

            return [
                'id' => $occurrence->eventId . ':' . $occurrence->recurrenceKey,
                'title' => $occurrence->title($clangId),
                'start' => $occurrence->start->format($occurrence->allDay ? 'Y-m-d' : 'Y-m-d\TH:i:s'),
                'end' => $occurrence->end->format($occurrence->allDay ? 'Y-m-d' : 'Y-m-d\TH:i:s'),
                'allDay' => $occurrence->allDay,
                'backgroundColor' => $calendar?->color,
                'borderColor' => $calendar?->color,
                'textColor' => Color::textOn($calendar?->color),
                'editable' => $editable,
                'url' => rex_url::backendPage('scheduler/events', ['func' => 'edit', 'id' => $occurrence->eventId, 'return' => 'calendar'], false),
                'extendedProps' => [
                    'eventId' => $occurrence->eventId,
                    'key' => $occurrence->recurrenceKey,
                    'recurring' => (bool) $event?->isRecurring,
                    'override' => $occurrence->isOverride,
                    'published' => (bool) $event?->published,
                    'own' => 0 === strcasecmp((string) $event?->createdBy, (string) Access::user()?->getLogin()),
                    'cancelled' => 'CANCELLED' === $event?->status->value,
                    'calendar' => $calendar?->name,
                    'timezone' => $occurrence->timezone,
                ],
            ];
        }, $query->get());
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function rrulePreview(array $payload): array
    {
        $rrule = trim((string) ($payload['rrule'] ?? ''));
        if ('' === $rrule) {
            return ['text' => '', 'next' => []];
        }

        try {
            $rule = Rule::parse($rrule);
        } catch (InvalidRuleException $e) {
            return ['error' => $e->getMessage()];
        }

        $timezone = $this->timezone((string) ($payload['timezone'] ?? ''));
        $allDay = (bool) ($payload['allDay'] ?? false);
        $start = new DateTimeImmutable((string) ($payload['start'] ?? 'today'), new DateTimeZone($timezone));
        $event = new Event($start, $start->modify($allDay ? '+1 day' : '+1 hour'), $allDay, $timezone);
        $event->rrule = (string) $rule;

        $next = Scheduler::expander()->next($event, $start->modify('-1 second'), 6);
        $formatter = new \IntlDateFormatter(\rex_i18n::getLocale(), \IntlDateFormatter::FULL, $allDay ? \IntlDateFormatter::NONE : \IntlDateFormatter::SHORT, $timezone);

        return [
            'text' => $rule->toText(substr(\rex_i18n::getLocale(), 0, 2), $start),
            'next' => array_map(static fn ($occurrence): string => (string) $formatter->format($occurrence->start), $next),
            'infinite' => $rule->isInfinite,
        ];
    }

    /**
     * Rendert ein noch unveröffentlichtes Schema mit demselben Renderer wie der Editor.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function schemaPreview(array $payload): array
    {
        if (true !== Access::user()?->isAdmin()) {
            throw new ApiException(I18n::t('api_forbidden'), 403);
        }
        $schema = Schema::fromArray(is_array($payload['definition'] ?? null) ? $payload['definition'] : []);

        return ['html' => new FormRenderer(Scheduler::schemas()->types, CustomFields::clangs(), 'preview')->render($schema, [])];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function quickCreate(array $payload): array
    {
        $calendar = Scheduler::calendars()->find((int) ($payload['calendarId'] ?? 0)) ?? throw new ApiException(I18n::t('calendar_not_found'), 404);
        $this->assertEditable((int) $calendar->id);

        [$start, $end, $allDay] = $this->period($payload, $calendar->timezone);
        $event = new Event($start, $end, $allDay, $calendar->timezone);
        $event->calendarId = (int) $calendar->id;
        $event->translate(rex_clang::getCurrentId())->title = trim((string) ($payload['title'] ?? ''));
        $event->published = Access::canPublish();
        Scheduler::events()->save($event);

        return ['id' => $event->id];
    }

    /**
     * Legt aus dem Termin-Editor heraus einen Ort an, ohne den Termin zu verlassen.
     *
     * @param array<string, mixed> $payload
     * @return array{id: int, label: string}
     */
    private function createLocation(array $payload): array
    {
        if (!Access::canManageLocations()) {
            throw new ApiException(I18n::t('api_location_denied'), 403);
        }
        $text = static fn (string $key): ?string => '' === trim((string) ($payload[$key] ?? '')) ? null : trim((string) $payload[$key]);

        $location = new Location();
        $location->name = (string) $text('name');
        if ('' === $location->name) {
            throw new ValidationException(['name' => I18n::t('location_name_required')]);
        }
        $location->street = $text('street');
        $location->zip = $text('zip');
        $location->city = $text('city');
        $location->country = $text('country');
        [$location->latitude, $location->longitude, $geoError] = Geo::parse((string) ($payload['coordinates'] ?? ''));
        if (null !== $geoError) {
            throw new ValidationException(['coordinates' => $geoError]);
        }
        Scheduler::locations()->save($location);

        return ['id' => (int) $location->id, 'label' => $location->label];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{id: int, label: string}
     */
    private function createCalendar(array $payload): array
    {
        if (!Access::canManageCalendars()) {
            throw new ApiException(I18n::t('api_calendar_denied'), 403);
        }

        $calendar = new Calendar();
        $calendar->name = trim((string) ($payload['name'] ?? ''));
        $color = (string) ($payload['color'] ?? '');
        $calendar->color = 1 === preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : $calendar->color;
        $calendar->timezone = $this->timezone((string) ($payload['timezone'] ?? ''));
        Scheduler::calendars()->save($calendar);

        return ['id' => (int) $calendar->id, 'label' => $calendar->name];
    }

    /**
     * Aktuelle Auswahlmöglichkeiten eines YForm-Felds, etwa nachdem im Popup ein Datensatz angelegt wurde.
     *
     * @return array{options: list<array{id: string, label: string}>}
     */
    private function yformOptions(): array
    {
        $choices = YformType::choicesFor(rex_request('table', 'string'), rex_request('label_field', 'string'));

        return ['options' => array_map(static fn (string $id, string $label): array => ['id' => $id, 'label' => $label], array_map(strval(...), array_keys($choices)), array_values($choices))];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function move(array $payload): array
    {
        $event = $this->event($payload);
        [$start, $end, $allDay] = $this->period($payload, $event->timezone);

        $tail = new SeriesEditor()->move($event, (string) ($payload['key'] ?? ''), $start, $end, $allDay, $this->scope($payload));
        Scheduler::em()->transactional(static function () use ($event, $tail): void {
            Scheduler::events()->save($event);
            if (null !== $tail) {
                Scheduler::events()->save($tail);
            }
        });

        return ['id' => $event->id, 'newId' => $tail?->id];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function remove(array $payload): array
    {
        $event = $this->event($payload);
        $deleteAll = new SeriesEditor()->remove($event, (string) ($payload['key'] ?? ''), $this->scope($payload));
        if ($deleteAll && !Access::canDeleteEvent($event)) {
            throw new ApiException(I18n::t('event_delete_denied'), 403);
        }
        $deleteAll ? Scheduler::events()->delete($event) : Scheduler::events()->save($event);

        return ['deleted' => $deleteAll];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(array $payload): Event
    {
        $event = Scheduler::events()->find((int) ($payload['eventId'] ?? 0)) ?? throw new ApiException(I18n::t('event_not_found'), 404);
        if (!Access::canEditEvent($event)) {
            throw new ApiException(I18n::t('api_event_denied'), 403);
        }

        return $event;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function scope(array $payload): EditScope
    {
        return EditScope::tryFrom((string) ($payload['scope'] ?? '')) ?? EditScope::This;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{DateTimeImmutable, DateTimeImmutable, bool}
     */
    private function period(array $payload, string $timezone): array
    {
        $zone = new DateTimeZone($timezone);
        $allDay = (bool) ($payload['allDay'] ?? false);
        try {
            $start = new DateTimeImmutable((string) ($payload['start'] ?? ''), $zone);
            $end = '' !== (string) ($payload['end'] ?? '') ? new DateTimeImmutable((string) $payload['end'], $zone) : $start->modify($allDay ? '+1 day' : '+1 hour');
        } catch (\Throwable) {
            throw new ApiException(I18n::t('error_period_invalid'), 400);
        }

        return [$start, $end, $allDay];
    }

    private function timezone(string $timezone): string
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : Scheduler::settings()->defaultTimezone();
    }

    private function assertEditable(int $calendarId): void
    {
        if (!Access::canEditCalendar($calendarId)) {
            throw new ApiException(I18n::t('event_calendar_denied'), 403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $body = (string) file_get_contents('php://input');
        $data = '' !== $body && json_validate($body) ? json_decode($body, true) : [];

        return is_array($data) ? $data : [];
    }
}
