# API-Referenz

Namespace: `KLXM\Scheduler`. Einstieg ist die Klasse `Scheduler`.

## Scheduler

Statischer Einstiegspunkt.

| Methode | Rückgabe | Zweck |
|---|---|---|
| `Scheduler::occurrences()` | `OccurrenceQuery` | neue Abfrage über Vorkommen |
| `Scheduler::events()` | `EventRepository` | Termine lesen und schreiben |
| `Scheduler::calendars()` | `CalendarRepository` | Kalender |
| `Scheduler::locations()` | `Repository<Location>` | Orte |
| `Scheduler::schemas()` | `SchemaService` | eigene Felder |
| `Scheduler::settings()` | `Settings` | Einstellungen, typisiert |
| `Scheduler::expander()` | `Expander` | Vorkommen ohne Datenbank berechnen |
| `Scheduler::indexer()` | `OccurrenceIndexer` | Vorkommens-Index pflegen |
| `Scheduler::validator()` | `EventValidator` | Termin prüfen, ohne zu speichern |
| `Scheduler::em()` | `EntityManager` | ORM, auch für eigene Entities |
| `Scheduler::boot($connection, $settings, $userResolver)` | | eigene Verbindung setzen, für Tests |

## Vorkommen abfragen

### OccurrenceQuery

Alle Filter sind verkettbar und geben die Abfrage zurück.

| Methode | Wirkung |
|---|---|
| `between(DateTimeInterface\|string $from, $to)` | Vorkommen, die den Zeitraum berühren. Zeichenketten gelten in der Standard-Zeitzone. |
| `from($from)`, `until($to)` | nur eine Grenze |
| `upcoming()` | laufende und künftige Vorkommen ab jetzt |
| `inCalendars(int ...$ids)` | nur diese Kalender |
| `forEvents(int ...$ids)` | nur diese Termine |
| `atLocations(int ...$ids)` | nur diese Orte |
| `withCategory(string $tag)` | Schlagwort |
| `whereCustom(string $field, string\|int\|float\|bool $value)` | eigenes Feld des Termins. Nutzt die Indexspalte, wenn das Feld filterbar ist. |
| `search(string $term, ?int $clangId = null)` | Titel, Kurztext, Beschreibung |
| `includeUnpublished(bool $include = true)` | auch Offline-Termine und inaktive Kalender |
| `latestFirst(bool $descending = true)` | absteigend nach Beginn |
| `limit(?int $limit, int $offset = 0)` | |

| Abschluss | Rückgabe |
|---|---|
| `get()` | `list<Occurrence>`, aufsteigend nach Beginn |
| `first()` | `?Occurrence` |
| `count()` | `int` |
| `paginate(int $page = 1, int $perPage = 25)` | `Page<Occurrence>` |

Ohne `includeUnpublished()` kommen nur veröffentlichte Termine aktiver Kalender zurück. Die Vertraulichkeit (`Visibility`) wird nicht gefiltert.

### Occurrence

| Property | Typ | Bedeutung |
|---|---|---|
| `eventId`, `calendarId` | int | |
| `start`, `end` | DateTimeImmutable | in der Zeitzone des Termins. `end` ist exklusiv. |
| `startUtc`, `endUtc` | DateTimeImmutable | dasselbe in UTC |
| `lastDay` | DateTimeImmutable | letzter Kalendertag. Bei ganztägigen Terminen der Tag vor `end`. |
| `allDay` | bool | |
| `isMultiDay` | bool | erstreckt sich über mehr als einen Kalendertag |
| `timezone` | string | IANA-Name |
| `recurrenceKey` | string | Schlüssel innerhalb der Serie: `Ymd` oder `Ymd\THis` |
| `isOverride` | bool | verschobenes Einzelvorkommen |
| `event` | ?Event | der Termin dahinter, bereits geladen |
| `override` | ?EventOverride | die Einzeländerung, falls vorhanden |

`title(?int $clangId = null): string` liefert den Titel des Vorkommens: abweichender Titel der Einzeländerung, sonst Titel des Termins mit Rückfall auf die erste Sprache.

### Page

`items` (list), `total`, `page`, `perPage`, `pages`, `hasNext`.

## Termine

### Event

| Property | Typ | iCalendar | Hinweis |
|---|---|---|---|
| `id` | ?int | | nur lesbar |
| `uid` | string | UID | wird beim Speichern vergeben, falls leer |
| `calendarId` | int | | Pflicht |
| `locationId` | ?int | LOCATION, GEO | gepflegter Ort |
| `locationText` | ?string | LOCATION | freier Text, wenn kein Ort zugeordnet ist |
| `dtstart`, `dtend` | DateTimeImmutable | DTSTART, DTEND | nur lesbar, setzen über `schedule()`. `dtend` exklusiv. |
| `allDay` | bool | VALUE=DATE | nur lesbar |
| `timezone` | string | TZID | nur lesbar |
| `rrule` | ?string | RRULE | ohne Präfix. Wird beim Setzen normalisiert, beim Speichern geprüft. |
| `exdates`, `rdates` | list<string> | EXDATE, RDATE | Schlüssel `Ymd` oder `Ymd\THis` |
| `status` | EventStatus | STATUS | |
| `visibility` | Visibility | CLASS | |
| `transparency` | Transparency | TRANSP | |
| `url`, `organizer` | ?string | URL, ORGANIZER | |
| `categories` | list<string> | CATEGORIES | Schlagwörter |
| `published` | bool | | online oder offline in REDAXO |
| `custom` | array | X-SCHEDULER-* | eigene Felder |
| `translations` | array<int, EventTranslation> | SUMMARY, DESCRIPTION | nach Sprach-ID |
| `overrides` | array<string, EventOverride> | RECURRENCE-ID | nach Vorkommens-Schlüssel |
| `sequence`, `etag` | int, string | SEQUENCE | pflegt das Repository |
| `extraIcal` | ?string | | nicht modellierte Eigenschaften aus Kalender-Apps |
| `importSource`, `importRef` | ?string | | Herkunft importierter Termine |
| `createdAt`, `updatedAt`, `createdBy`, `updatedBy` | | CREATED, LAST-MODIFIED | nur lesbar |
| `isRecurring` | bool | | hat Regel oder Zusatztermine |
| `lastDay` | DateTimeImmutable | | letzter Kalendertag |
| `duration` | int | | Sekunden |

| Methode | Zweck |
|---|---|
| `new Event(?DateTimeImmutable $start = null, ?DateTimeImmutable $end = null, bool $allDay = false, string $timezone = 'Europe/Berlin')` | |
| `schedule($start, $endExklusiv, bool $allDay = false, ?string $timezone = null): static` | Zeitraum und Zeitzone gemeinsam setzen |
| `scheduleAllDay($firstDay, ?$lastDay = null, ?string $timezone = null): static` | ganztägig über inklusive Kalendertage |
| `translate(int $clangId): EventTranslation` | Übersetzung holen oder anlegen |
| `translation(?int $clangId = null, bool $fallback = true): ?EventTranslation` | lesen, mit Rückfall auf die erste Sprache |
| `title(?int $clangId = null): string` | |
| `custom(string $field, mixed $default = null): mixed` | |
| `exclude(string $key): static` | Vorkommen ausfallen lassen |
| `recurrenceKey(DateTimeImmutable $start): string` | Schlüssel eines Vorkommens bilden |

### EventTranslation

`clangId`, `title`, `teaser`, `description` (HTML), `custom` (übersetzbare eigene Felder), `plainDescription` (Kurztext oder Beschreibung ohne HTML).

### EventOverride

`recurrenceId` (ursprünglicher Beginn), `dtstart`, `dtend`, `allDay`, `status`, `locationText`, `translations` (abweichende Texte je Sprach-ID), `extraIcal`.

### Enums

| Enum | Werte |
|---|---|
| `Domain\Enum\EventStatus` | `Confirmed`, `Tentative`, `Cancelled` |
| `Domain\Enum\Visibility` | `Public`, `Private`, `Confidential` |
| `Domain\Enum\Transparency` | `Opaque`, `Transparent` |
| `Domain\Enum\SchemaTarget` | `Event`, `Calendar`, `Location` |
| `Recurrence\EditScope` | `This`, `Following`, `All` |

### EventRepository

Zusätzlich zu den allgemeinen Repository-Methoden:

| Methode | Zweck |
|---|---|
| `findByUid(string $uid): ?Event` | |
| `findByDavUri(int $calendarId, string $uri): ?Event` | |
| `findByImportRef(string $source, string $ref): ?Event` | |
| `save(Event $event): void` | prüft, speichert, berechnet Vorkommen. Wirft `Service\ValidationException` mit `errors` (Feld => Meldung). |
| `delete(Event $event): void` | samt Übersetzungen, Einzeländerungen und Vorkommen |
| `EventRepository::generateUid(): string` | |

```php
$event = new Event(new DateTimeImmutable('2026-10-06 16:00'), new DateTimeImmutable('2026-10-06 17:30'));
$event->calendarId = 1;
$event->rrule = 'FREQ=WEEKLY;BYDAY=TU,TH;COUNT=10';
$event->translate(1)->title = 'AG Schach';
$event->custom = ['raum' => 'Aula'];
Scheduler::events()->save($event);
```

## Kalender und Orte

### Calendar

`id`, `name`, `nameTranslations` (nach Sprach-ID), `slug`, `color`, `timezone`, `description`, `active`, `publicFeed`, `priority`, `syncToken`, `custom`. Methode `name(?int $clangId = null): string`.

`CalendarRepository`: `findBySlug(string $slug): ?Calendar`, `all(bool $onlyActive = false): list<Calendar>` (nach Reihenfolge und Name). Der Kurzname wird beim Speichern eindeutig gebildet. Ein Kalender mit Terminen lässt sich nicht löschen.

### Location

`id`, `name`, `street`, `zip`, `city`, `country`, `latitude`, `longitude`, `url`, `active`, `custom`, `label` (einzeilige Adresse), `hasGeo`.

## Repository und Query

Für alle Entities, auch eigene.

| Repository | |
|---|---|
| `find(int $id): ?T` | |
| `findOrFail(int $id): T` | wirft `Orm\OrmException` |
| `findMany(list<int> $ids): array<int, T>` | nach ID |
| `query(): Query<T>` | |
| `save(T $entity)`, `delete(T $entity)` | |
| `insertWithId(T $entity, int $id)` | mit vorgegebenem Primärschlüssel, für Importe |

| Query | |
|---|---|
| `where('prop', $wert)` oder `where('prop', '>=', $wert)` | Operatoren: `=`, `!=`, `<`, `<=`, `>`, `>=`, `LIKE`, `NOT LIKE`. `null` wird zu `IS NULL`. |
| `whereIn('prop', array $werte, bool $not = false)` | |
| `whereNull('prop')`, `whereNotNull('prop')` | |
| `whereRaw(string $sql, list $params = [])` | `{prop}` wird durch die maskierte Spalte samt Tabellenname ersetzt |
| `orderBy('prop', 'asc'\|'desc')`, `limit($n, $offset)` | |
| `get()`, `first()`, `count()`, `exists()`, `paginate($page, $perPage)` | |
| `chunkById(int $size, callable $callback)` | große Bestände blockweise |
| `delete(): int` | löscht Treffer direkt, ohne Hooks |

Bedingungen verwenden Property-Namen, Werte werden gebunden und nach Spaltentyp umgewandelt (Enums, DateTime, bool).

## Wiederholungen

### Rule

```php
use KLXM\Scheduler\Recurrence\Rule;

$rule = Rule::parse('FREQ=WEEKLY;INTERVAL=2;BYDAY=TU');   // wirft InvalidRuleException
$rule->frequency;  $rule->interval;  $rule->count;  $rule->isInfinite;  $rule->parts;
$rule->until($zone);                     // ?DateTimeImmutable
$rule->with('COUNT', '5');               // neue Regel; null entfernt den Bestandteil
$rule->normalizedFor($allDay, $zone);    // UNTIL als Datum oder UTC, wie RFC 5545 verlangt
$rule->toText('de', $start);             // "Alle 2 Wochen am Dienstag"
(string) $rule;
```

### Expander

| Methode | Zweck |
|---|---|
| `expand(Event $event, $from, $to, int $max = 5000): list<ExpandedOccurrence>` | Vorkommen im Fenster, ohne Datenbank |
| `next(Event $event, $from, int $limit = 5): list<ExpandedOccurrence>` | die nächsten Vorkommen, auch für ungespeicherte Termine |

`ExpandedOccurrence`: `start`, `end`, `allDay`, `recurrenceKey`, `isOverride`.

### SeriesEditor

Arbeitet nur auf den Objekten, gespeichert wird vom Aufrufer.

| Methode | Zweck |
|---|---|
| `move(Event $event, string $key, $start, $end, bool $allDay, EditScope $scope): ?Event` | verschieben. Bei `Following` kommt der abgespaltene neue Termin zurück und muss ebenfalls gespeichert werden. |
| `remove(Event $event, string $key, EditScope $scope): bool` | `true` heißt: Der Aufrufer soll den ganzen Termin löschen. |
| `split(Event $event, string $key): Event` | Serie vor dem Vorkommen beenden, Rest als neuen Termin liefern |
| `duplicate(Event $event): Event` | unabhängige, ungespeicherte Kopie |

```php
$editor = new SeriesEditor();
$tail = $editor->move($event, '20261020T160000', $newStart, $newEnd, false, EditScope::Following);
Scheduler::events()->save($event);
if (null !== $tail) {
    Scheduler::events()->save($tail);
}
```

## Frontend-Helfer

`KLXM\Scheduler\Frontend\Frontend`

| Methode | Zweck |
|---|---|
| `detailUrl(Occurrence $o, int $articleId, ?int $clangId = null, bool $forHtml = true): string` | Link zur Detailseite mit `event` und bei Serien `date` |
| `occurrenceFromRequest(): ?Occurrence` | Vorkommen zu `?event=&date=`, sonst das nächste, sonst das letzte |
| `formatRange(Occurrence $o, ?string $locale = null): string` | lesbarer Zeitraum |
| `icsUrl(Event $event): string` | Termin als .ics |
| `feedUrl(Calendar $calendar, bool $webcal = false): string` | Abo-Adresse |
| `jsonLd(Occurrence $o, ?Location $location = null, ?string $url = null): array` | schema.org/Event |
| `stylesheet(): string` | `<link>` auf das mitgelieferte CSS, einmal je Seite |

`Frontend\ModuleInstaller`: `modules()`, `install(string $key): int`.

## Rechte

`KLXM\Scheduler\Security\Access`, alle Methoden statisch, optional mit `rex_user`.

| Methode | |
|---|---|
| `canUse(): bool` | hat `scheduler[]` |
| `canEditCalendar(int $calendarId): bool` | |
| `canEditEvent(Event $event): bool` | Kalender erlaubt und eigener Termin oder `scheduler[edit_foreign]` |
| `canDeleteEvent(Event $event): bool` | zusätzlich `scheduler[delete]` |
| `canPublish(): bool` | `scheduler[publish]` |
| `editableCalendars(): list<Calendar>`, `editableCalendarIds(): list<int>` | |

`canManageLocations(): bool` und `canManageCalendars(): bool` prüfen `scheduler[locations]` und `scheduler[calendars]`.

Konstanten: `Access::PERM_EDIT_FOREIGN`, `PERM_PUBLISH`, `PERM_DELETE`. Rollenrecht: `Security\CalendarPerm::KEY` (`scheduler_calendars`).

## ICS

| Klasse | Methode | Zweck |
|---|---|---|
| `Ical\FeedBuilder` | `forCalendars(list<Calendar>, int $clangId, $from, $to, bool $publicOnly = true, ?string $name = null): string` | ICS-Text für Kalender |
| | `forEvent(Event $event, int $clangId): string` | ICS-Text für einen Termin |
| `Ical\EventSerializer` | `createCalendar(?string $name, ?string $timezone): VCalendar`, `addEvent(VCalendar, Event, int $clangId, ?Location, array $customProperties = [])` | VEVENT erzeugen |
| `Ical\EventParser` | `parse(string $ics): list<ParsedEvent>`, `apply(ParsedEvent, Event, int $clangId, string $defaultTimezone, ?string $currentLocationLabel = null)` | VEVENT auf Termin übertragen |
| `Ical\TimezoneBuilder` | `add(VCalendar, string $timezone, $from, $to)` | VTIMEZONE |
| `Import\IcsFetcher` | `fetch(string $url): string` | lädt mit Weiterleitungen, wirft `Import\FetchException` |
| `Import\SubscriptionService` | `all(): list<Subscription>`, `sync(Subscription): ?ImportReport`, `syncDue(bool $force = false): array{synced, failed}`, `repository()` | abonnierte Kalender abgleichen |
| `Import\IcsImporter` | `import(string $ics, Calendar $calendar, int $clangId, bool $dryRun = false, ?string $source = null, bool $removeMissing = false): ImportReport` | |

`ImportReport`: `counts` (Bezeichnung => Anzahl), `messages` (Stufe, Betreff, Meldung), `dryRun`, `hasErrors`.

```php
$ics = new IcsFetcher()->fetch('webcal://example.org/ferien.ics');
$report = new IcsImporter()->import($ics, $calendar, rex_clang::getStartId(), source: 'ferien', removeMissing: true);
```

## Eigene Felder

`Scheduler::schemas()` liefert den `Field\SchemaService`.

| Methode | Zweck |
|---|---|
| `active(SchemaTarget $target): Schema` | aktives Schema |
| `publish(SchemaTarget $target, Schema $schema, array $renames = [], ?string $note = null): FieldSchema` | neue Version. `$renames`: alter Name => neuer Name. |
| `history(SchemaTarget $target, int $limit = 20): list<FieldSchema>` | |
| `usage(SchemaTarget $target, string $field): int` | Datensätze mit Wert |
| `indexColumn(SchemaTarget $target, string $field): ?string` | Spalte eines filterbaren Felds |
| `exportJson(SchemaTarget $target): string`, `importJson(string $json, ?string $note = null): bool` | |
| `reservedNames(SchemaTarget $target): list<string>` | Namen der festen Felder |
| `types` | `TypeRegistry` mit `register(FieldType)`, `has()`, `get()`, `all()` |

`Field\Schema`: `fromArray(array)`, `toArray()`, `fields()` (Name => `FieldNode`), `conditions()`, `validate(TypeRegistry, array $reserved): list<string>`, `isEmpty`.

Interface `Field\FieldType`:

| Methode | Zweck |
|---|---|
| `key(): string`, `label(): string`, `icon(): string` | Identität und Darstellung im Builder |
| `options(): array` | Einstellungen im Eigenschaften-Panel: `name`, `label`, `type` (`text`, `number`, `textarea`, `checkbox`, `select`), optional `choices`, `help` |
| `allowedInRepeater(): bool` | |
| `render(FieldContext $context): string` | Eingabeelement ohne Label. Der Kontext liefert `node`, `inputName`, `inputId`, `value`, `baseAttributes()`. |
| `normalize(mixed $raw, FieldNode $node): mixed` | Request-Wert in gespeicherte Form |
| `validate(mixed $value, FieldNode $node): ?string` | Fehlermeldung oder `null` |
| `isEmpty(mixed $value): bool` | |
| `toText(mixed $value, FieldNode $node): string` | für den ICS-Feed |

Formulare außerhalb des Editors: `Field\FormRenderer::render(Schema, array $values, array $translatedValues = [], array $errors = [])` und `Field\ValueProcessor::process(Schema, array $raw, array $rawTranslated, list<int> $clangIds, array $existing = []): ProcessedValues`.

## Farben

`KLXM\Scheduler\Color::textOn(?string $background): string` liefert zu einer Kalenderfarbe die lesbare Schriftfarbe, `Color::LIGHT_TEXT` oder `Color::DARK_TEXT`. Grundlage ist die wahrgenommene Helligkeit der Fläche. Beide Kalender-Schnittstellen geben den Wert als `textColor` aus.

```php
printf('<span style="background:%s;color:%s">%s</span>', $calendar->color, Color::textOn($calendar->color), rex_escape($calendar->name));
```

## Einstellungen

`Scheduler::settings()`: `defaultTimezone()`, `defaultAllDay()`, `weekStartsOn()`, `horizonBackMonths()`, `horizonAheadMonths()`, `maxOccurrencesPerEvent()`, `editorClass()`, `editorProfile()`, `feedMonthsBack()`, `feedMonthsAhead()`.

Die Schlüssel in `rex_config` (Namespace `scheduler`) heißen wie die Methoden in snake_case, etwa `default_timezone`.

## Extension Points

| Name | Subject | Wann |
|---|---|---|
| `SCHEDULER_EVENT_SAVED` | `Event` | nach dem Speichern, auf jedem Weg |
| `SCHEDULER_EVENT_DELETED` | `Event` | nach dem Löschen |

```php
rex_extension::register('SCHEDULER_EVENT_SAVED', static function (rex_extension_point $ep): void {
    /** @var KLXM\Scheduler\Domain\Event $event */
    $event = $ep->getSubject();
});
```

## HTTP-Schnittstellen

| Aufruf | Zugang | Liefert |
|---|---|---|
| `index.php?rex-api-call=scheduler_feed&calendar=…` | öffentlich, wenn der Kalender einen öffentlichen Feed hat | `text/calendar` mit ETag |
| `index.php?rex-api-call=scheduler_feed&event=ID` | öffentlich für veröffentlichte, öffentliche Termine | `.ics` als Download |
| `index.php?rex-api-call=scheduler_events&start=&end=&calendars=&detail=&clang=` | öffentlich | JSON im FullCalendar-Format |
| `/dav/` | REDAXO-Login mit App-Passwort, über das Addon `dav` | CalDAV. scheduler registriert dort `Dav\CalendarProvider`. |
| `redaxo/index.php?rex-api-call=scheduler&action=…` | Backend-Session, schreibend mit CSRF-Token | intern für die Oberfläche: `occurrences`, `quickCreate`, `move`, `remove`, `rrulePreview`, `schemaPreview`, `fieldUsage` |

Antwort von `scheduler_events` je Eintrag: `id` (Termin-ID und Vorkommens-Schlüssel), `title`, `start`, `end` (exklusiv), `allDay`, `color`, `url` (mit `detail`), `classNames` (`scheduler-is-cancelled`), `extendedProps.calendar`, `.teaser`, `.location`.

## Konsole

| Befehl | Zweck |
|---|---|
| `scheduler:reindex [--extend]` | Vorkommen neu berechnen, mit `--extend` nur Serien mit zu kurzem Horizont |
| `scheduler:import-ics <quelle> <kalender> [--sync] [--dry-run] [--clang=]` | ICS-Datei oder URL importieren. Kalender als ID oder Kurzname. |
| `scheduler:import-legacy [--dry-run] [--timezone=] [-v]` | Übernahme aus forcal |
| `scheduler:schema-sync <dateien…>` | exportierte Feld-Schemata einspielen |
| `scheduler:sync-subscriptions [--all]` | abonnierte ICS-Kalender abgleichen |
