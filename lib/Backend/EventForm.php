<?php

declare(strict_types=1);

namespace KLXM\Scheduler\Backend;

use DateTimeImmutable;
use DateTimeZone;
use KLXM\Scheduler\Domain\Enum\EventStatus;
use KLXM\Scheduler\Domain\Enum\SchemaTarget;
use KLXM\Scheduler\Domain\Enum\Transparency;
use KLXM\Scheduler\Domain\Enum\Visibility;
use KLXM\Scheduler\Domain\Event;
use KLXM\Scheduler\Field\FormRenderer;
use KLXM\Scheduler\Field\ValueProcessor;
use KLXM\Scheduler\I18n;
use KLXM\Scheduler\Scheduler;
use KLXM\Scheduler\Recurrence\Rule;
use KLXM\Scheduler\Security\Access;

/**
 * Editor für einen Termin: überträgt den Request auf die Entity und rendert das Formular.
 *
 * @internal
 */
final class EventForm
{
    /** @var array<string, string> Feldname => Meldung */
    public array $errors = [];

    /**
     * @param array<int, string> $clangs Sprach-ID => Name, Start-Sprache zuerst
     */
    public function __construct(
        public readonly Event $event,
        private readonly array $clangs,
    ) {}

    /**
     * Überträgt die gesendeten Werte auf den Termin.
     */
    public function bind(): void
    {
        $event = $this->event;
        $clangIds = array_keys($this->clangs);

        $titles = rex_post('title', 'array', []);
        $teasers = rex_post('teaser', 'array', []);
        $descriptions = rex_post('description', 'array', []);
        foreach ($clangIds as $clangId) {
            $title = trim((string) ($titles[$clangId] ?? ''));
            $teaser = trim((string) ($teasers[$clangId] ?? ''));
            $description = trim((string) ($descriptions[$clangId] ?? ''));
            if ('' === $title && '' === $teaser && '' === $description && !isset($event->translations[$clangId])) {
                continue;
            }
            $translation = $event->translate($clangId);
            $translation->title = $title;
            $translation->teaser = '' === $teaser ? null : $teaser;
            $translation->description = '' === $description ? null : $description;
        }

        $calendarId = rex_post('calendar_id', 'int');
        if (!Access::canEditCalendar($calendarId)) {
            $this->errors['calendarId'] = I18n::t('event_calendar_denied');
        } else {
            $event->calendarId = $calendarId;
        }
        if (Access::canPublish()) {
            $event->published = rex_post('published', 'bool');
        } elseif (null === $event->id) {
            $event->published = false; // wartet auf Freigabe
        }

        $this->bindPeriod();
        $event->rrule = rex_post('rrule', 'string');

        $locationId = rex_post('location_id', 'int');
        $event->locationId = $locationId > 0 ? $locationId : null;
        $locationText = trim(rex_post('location_text', 'string'));
        $event->locationText = (null === $event->locationId && '' !== $locationText) ? $locationText : null;

        $event->status = EventStatus::tryFrom(rex_post('status', 'string')) ?? EventStatus::Confirmed;
        $event->visibility = Visibility::tryFrom(rex_post('visibility', 'string')) ?? Visibility::Public;
        $event->transparency = Transparency::tryFrom(rex_post('transparency', 'string')) ?? Transparency::Opaque;
        $url = trim(rex_post('url', 'string'));
        $event->url = '' === $url ? null : $url;
        $organizer = trim(rex_post('organizer', 'string'));
        $event->organizer = '' === $organizer ? null : $organizer;

        $categories = json_decode(rex_post('categories', 'string', '[]'), true);
        $event->categories = is_array($categories) ? array_values(array_filter(array_map(static fn ($c): string => is_scalar($c) ? trim((string) $c) : '', $categories), static fn (string $c): bool => '' !== $c)) : [];

        foreach (rex_post('restore_exdates', 'array', []) as $key) {
            $event->exdates = array_values(array_diff($event->exdates, [(string) $key]));
        }
        foreach (rex_post('remove_overrides', 'array', []) as $key) {
            unset($event->overrides[(string) $key]);
        }

        $processed = new ValueProcessor(Scheduler::schemas()->types)->process(
            Scheduler::schemas()->active(SchemaTarget::Event),
            rex_post('custom', 'array', []),
            rex_post('custom_lang', 'array', []),
            $clangIds,
            $event->custom,
        );
        $event->custom = $processed->values;
        foreach ($clangIds as $clangId) {
            $values = $processed->translatedValues[$clangId] ?? [];
            if ([] !== $values || isset($event->translations[$clangId])) {
                $event->translate($clangId)->custom = $values;
            }
        }
        $this->errors += $processed->errors;
    }

    private function bindPeriod(): void
    {
        $period = rex_post('period', 'array', []);
        $timezone = (string) ($period['timezone'] ?? '');
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $timezone = $this->event->timezone;
        }
        $zone = new DateTimeZone($timezone);
        $allDay = (bool) ($period['all_day'] ?? false);

        try {
            $startDate = (string) ($period['start_date'] ?? '');
            $endDate = (string) ($period['end_date'] ?? '') ?: $startDate;
            if ('' === $startDate) {
                throw new \InvalidArgumentException();
            }
            if ($allDay) {
                $this->event->scheduleAllDay(new DateTimeImmutable($startDate, $zone), new DateTimeImmutable($endDate, $zone), $timezone);

                return;
            }
            $start = new DateTimeImmutable($startDate . ' ' . ((string) ($period['start_time'] ?? '') ?: '00:00'), $zone);
            $end = new DateTimeImmutable($endDate . ' ' . ((string) ($period['end_time'] ?? '') ?: $start->format('H:i')), $zone);
            if ($end < $start) {
                $this->errors['dtend'] = I18n::t('error_end_before_start');
                $end = $start;
            }
            $this->event->schedule($start, $end, false, $timezone);
        } catch (\Throwable) {
            $this->errors['dtstart'] = I18n::t('error_period_invalid');
        }
    }

    public function render(string $apiUrl): string
    {
        $event = $this->event;
        $e = Html::e(...);
        $firstClang = array_key_first($this->clangs) ?? 1;

        // Titel und Texte je Sprache
        $titleInputs = $teaserInputs = $descriptionInputs = '';
        $settings = Scheduler::settings();
        $editorClass = trim('form-control ' . $settings->editorClass());
        foreach ($this->clangs as $clangId => $clangName) {
            $translation = $event->translations[$clangId] ?? null;
            $wrap = static fn (string $input): string => sprintf('<div data-lang="%s" data-lang-id="%d">%s</div>', $e($clangName), $clangId, $input);
            $titleInputs .= $wrap(Html::input('text', "title[$clangId]", $translation?->title, [
                'id' => "scheduler-title-$clangId", 'class' => 'form-control scheduler-title-input', 'maxlength' => 500,
                'required' => $clangId === $firstClang, 'placeholder' => I18n::t('event_new'), 'autofocus' => $clangId === $firstClang && null === $event->id,
            ]));
            $teaserInputs .= $wrap(sprintf('<textarea class="form-control" rows="2" name="teaser[%d]" id="scheduler-teaser-%d">%s</textarea>', $clangId, $clangId, $e($translation?->teaser)));
            $descriptionInputs .= $wrap(sprintf(
                '<textarea class="%s" rows="8" name="description[%d]" id="scheduler-description-%d"%s>%s</textarea>',
                $e($editorClass),
                $clangId,
                $clangId,
                '' !== $settings->editorProfile() ? ' data-profile="' . $e($settings->editorProfile()) . '"' : '',
                $e($translation?->description),
            ));
        }
        $multi = count($this->clangs) > 1;
        $lang = static fn (string $inputs): string => $multi ? '<scheduler-lang-field>' . $inputs . '</scheduler-lang-field>' : $inputs;

        $calendars = [];
        foreach (Access::editableCalendars() as $calendar) {
            $calendars[(int) $calendar->id] = $calendar->name;
        }
        $locations = [];
        foreach (Scheduler::locations()->query()->orderBy('name')->get() as $location) {
            $locations[(int) $location->id] = $location->label;
        }

        $main = Html::field(I18n::t('event_title'), $lang($titleInputs), "scheduler-title-$firstClang", error: $this->errors['title'] ?? null, required: true)
            . $this->renderPeriod()
            . Html::field(I18n::t('event_repeat'), sprintf(
                '<scheduler-recurrence api="%s"' . (Pickers::enhanced() ? ' picker="a11y"' : '') . '><input type="text" class="form-control" name="rrule" id="scheduler-rrule" value="%s" spellcheck="false"></scheduler-recurrence>',
                $e($apiUrl),
                $e($event->rrule),
            ), 'scheduler-rrule', error: $this->errors['rrule'] ?? null)
            . Html::field(I18n::t('calendar_single'), Html::select('calendar_id', $calendars, $event->calendarId, ['id' => 'scheduler-calendar', 'required' => true], [] === $calendars ? I18n::t('event_no_calendar') : null)
                . (Access::canManageCalendars() ? $this->quickCreate($apiUrl, '#scheduler-calendar', 'createCalendar', I18n::t('quick_new_calendar'), [
                    ['name' => 'name', 'label' => I18n::t('name'), 'required' => true],
                    ['name' => 'color', 'label' => I18n::t('calendar_color'), 'type' => 'color', 'value' => '#3788d8'],
                ]) : ''), 'scheduler-calendar', error: $this->errors['calendarId'] ?? null, required: true)
            . Html::field(I18n::t('event_location'), Html::select('location_id', $locations, $event->locationId, ['id' => 'scheduler-location'], I18n::t('event_location_none'))
                . (Access::canManageLocations() ? $this->quickCreate($apiUrl, '#scheduler-location', 'createLocation', I18n::t('quick_new_location'), [
                    ['name' => 'name', 'label' => I18n::t('name'), 'required' => true],
                    ['name' => 'street', 'label' => I18n::t('location_street')],
                    ['name' => 'zip', 'label' => I18n::t('location_zip')],
                    ['name' => 'city', 'label' => I18n::t('location_city')],
                    ['name' => 'coordinates', 'label' => I18n::t('location_coordinates'), 'html' => Geo::input(null, null, 'scheduler-quick-coordinates')],
                ]) : '')
                . Html::input('text', 'location_text', $event->locationText, ['placeholder' => I18n::t('event_location_text_placeholder'), 'aria-label' => I18n::t('event_location_text'), 'class' => 'form-control scheduler-location-text', 'maxlength' => 500]), 'scheduler-location');

        $content = Html::field(I18n::t('event_teaser'), $lang($teaserInputs), "scheduler-teaser-$firstClang", I18n::t('event_teaser_help'))
            . Html::field(I18n::t('description'), $lang($descriptionInputs), "scheduler-description-$firstClang");

        // Die Beschriftungen der Auswahllisten kommen aus den Enums: "enum_<name>_<wert>" in den Sprachdateien.
        $options = static function (array $cases, string $group): array {
            $result = [];
            foreach ($cases as $case) {
                $result[$case->value] = I18n::t('enum_' . $group . '_' . strtolower($case->value));
            }

            return $result;
        };
        $details = Html::field(
            I18n::t('event_published'),
            Access::canPublish()
                ? Html::toggle('published', $event->published, I18n::t('event_published_label'), 'scheduler-published')
                : '<p class="form-control-static">' . I18n::e($event->published ? 'event_published_label' : 'event_awaiting_approval') . '</p>',
            'scheduler-published',
            I18n::t(Access::canPublish() ? 'event_published_help' : 'event_publish_by_others'),
        )
            . Html::field(I18n::t('status'), Html::select('status', $options(EventStatus::cases(), 'status'), $event->status->value, ['id' => 'scheduler-status']), 'scheduler-status', I18n::t('event_status_help'))
            . Html::field(I18n::t('event_tags'), sprintf('<scheduler-tags name="categories" input-id="scheduler-categories" value="%s"></scheduler-tags>', $e(json_encode($event->categories, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))), 'scheduler-categories')
            . Html::field(I18n::t('event_url'), Html::input('url', 'url', $event->url, ['id' => 'scheduler-url', 'placeholder' => 'https://']), 'scheduler-url', error: $this->errors['url'] ?? null)
            . Html::field(I18n::t('event_organizer'), Html::input('text', 'organizer', $event->organizer, ['id' => 'scheduler-organizer', 'maxlength' => 500]), 'scheduler-organizer')
            . Html::field(I18n::t('event_visibility'), Html::select('visibility', $options(Visibility::cases(), 'visibility'), $event->visibility->value, ['id' => 'scheduler-visibility']), 'scheduler-visibility', I18n::t('event_visibility_help'))
            . Html::field(I18n::t('event_transparency'), Html::select('transparency', $options(Transparency::cases(), 'transparency'), $event->transparency->value, ['id' => 'scheduler-transparency']), 'scheduler-transparency', I18n::t('event_transparency_help'));

        $translatedCustom = array_map(static fn ($t): array => $t->custom, $event->translations);
        $custom = new FormRenderer(Scheduler::schemas()->types, $this->clangs)->render(Scheduler::schemas()->active(SchemaTarget::Event), $event->custom, $translatedCustom, $this->errors);

        $html = '<div class="scheduler-editor" data-scheduler-api="' . $e($apiUrl) . '"><div class="scheduler-editor-main">'
            . Html::section(I18n::e('event_single'), $main)
            . Html::section(I18n::e('event_content'), $content)
            . ('' !== $custom ? Html::section(I18n::e('more_details'), $custom) : '')
            . $this->renderExceptions()
            . '</div><aside class="scheduler-editor-side">' . Html::section(I18n::e('event_details'), $details) . '</aside></div>';

        return $html;
    }

    /**
     * Knopf, der einen Datensatz im Dialog anlegt und danach in der Auswahlliste auswählt.
     *
     * @param list<array<string, mixed>> $fields
     */
    private function quickCreate(string $apiUrl, string $select, string $action, string $title, array $fields): string
    {
        return sprintf(
            '<scheduler-quick-create api="%s" for="%s" action="%s" title="%s" label="%s" fields="%s"></scheduler-quick-create>',
            Html::e($apiUrl),
            Html::e($select),
            Html::e($action),
            Html::e($title),
            Html::e($title),
            Html::e(json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        );
    }

    private function renderPeriod(): string
    {
        $event = $this->event;
        $timezones = array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers());
        $date = static fn (string $name, DateTimeImmutable $value, string $label): string => Pickers::date("period[$name]", $value->format('Y-m-d'), ['data-period' => $name, 'required' => true, 'aria-label' => $label, 'id' => "scheduler-$name"]);
        $time = static fn (string $name, DateTimeImmutable $value, string $label): string => '<span data-period-time>' . Pickers::time("period[$name]", $value->format('H:i'), ['data-period' => $name, 'aria-label' => $label, 'step' => 300]) . '</span>';

        $input = '<scheduler-period class="scheduler-period">'
            . '<div class="scheduler-period-row">' . Html::toggle('period[all_day]', $event->allDay, I18n::t('event_all_day'), attributes: ['data-period' => 'all_day']) . '</div>'
            . '<div class="scheduler-period-row"><span class="scheduler-period-label">' . I18n::e('event_start') . '</span>' . $date('start_date', $event->dtstart, I18n::t('event_start_date')) . $time('start_time', $event->dtstart, I18n::t('event_start_time')) . '</div>'
            . '<div class="scheduler-period-row"><span class="scheduler-period-label">' . I18n::e('event_end') . '</span>' . $date('end_date', $event->lastDay, I18n::t('event_end_date')) . $time('end_time', $event->dtend, I18n::t('event_end_time')) . '</div>'
            . '<div class="scheduler-period-row" data-period-timezone><span class="scheduler-period-label">' . I18n::e('timezone') . '</span>' . Html::select('period[timezone]', $timezones, $event->timezone, ['data-period' => 'timezone', 'aria-label' => I18n::t('timezone')]) . '</div>'
            . '</scheduler-period>';

        return Html::field(I18n::t('event_period'), $input, 'scheduler-start_date', error: $this->errors['dtstart'] ?? $this->errors['dtend'] ?? null, required: true);
    }

    /**
     * Ausgefallene und geänderte Vorkommen einer Serie mit der Möglichkeit, sie zurückzunehmen.
     */
    private function renderExceptions(): string
    {
        $event = $this->event;
        if (!$event->isRecurring || ([] === $event->exdates && [] === $event->overrides)) {
            return '';
        }

        $zone = new DateTimeZone($event->timezone);
        $formatter = new \IntlDateFormatter(\rex_i18n::getLocale(), \IntlDateFormatter::FULL, $event->allDay ? \IntlDateFormatter::NONE : \IntlDateFormatter::SHORT, $event->timezone);
        $label = static function (string $key) use ($zone, $formatter): string {
            $date = DateTimeImmutable::createFromFormat(8 === strlen($key) ? '!Ymd' : '!Ymd\THis', $key, $zone);

            return false === $date ? $key : (string) $formatter->format($date);
        };

        $rows = '';
        foreach ($event->exdates as $key) {
            $rows .= sprintf('<li><span class="scheduler-exception-type">%s</span> %s <label class="scheduler-choice"><input type="checkbox" name="restore_exdates[]" value="%s"> %s</label></li>', I18n::e('exception_cancelled'), Html::e($label($key)), Html::e($key), I18n::e('exception_restore'));
        }
        foreach ($event->overrides as $key => $override) {
            $rows .= sprintf(
                '<li><span class="scheduler-exception-type">%s</span> %s <label class="scheduler-choice"><input type="checkbox" name="remove_overrides[]" value="%s"> %s</label></li>',
                I18n::e('exception_changed'),
                I18n::e('exception_changed_to', $label((string) $key), (string) $formatter->format($override->dtstart)),
                Html::e((string) $key),
                I18n::e('exception_discard'),
            );
        }

        $text = null !== $event->rrule ? Rule::parse($event->rrule)->toText(substr(\rex_i18n::getLocale(), 0, 2), $event->dtstart) : '';

        return Html::section(I18n::e('exceptions_title'), '<p class="help-block">' . Html::e($text) . '</p><ul class="scheduler-exceptions">' . $rows . '</ul>');
    }
}
