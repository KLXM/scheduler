# Architektur und Entwicklung

## Aufbau

| Ordner | Inhalt |
|---|---|
| `lib/Scheduler.php` | Einstiegspunkt der API |
| `lib/Orm` | schlankes ORM: Attribute, Metadaten, Schema-Generator, Query, Repository |
| `lib/Domain` | Entities und Enums. Aus ihren Attributen entsteht das Datenbankschema. |
| `lib/Recurrence` | Regel-Wertobjekt, Berechnung der Vorkommen, Serien-Bearbeitung |
| `lib/Ical` | VEVENT schreiben und lesen, Zeitzonen, Feeds |
| `lib/Dav` | CalDAV-Anbindung: Provider und Kalender-Backend für das Addon `dav` |
| `lib/Field` | Formbuilder: Schema, Feldtypen, Renderer, Werteverarbeitung |
| `lib/Import` | ICS-Import, URL-Abruf, isolierte Übernahme aus forcal |
| `lib/Query` | Bereichsabfrage über den Vorkommens-Index |
| `lib/Security` | Rechte |
| `lib/Frontend` | Helfer und Modul-Installer |
| `lib/Backend`, `pages` | Backend-Oberfläche |
| `assets/js` | Web Components als ES-Module, ohne Build-Schritt und ohne jQuery |
| `fragments/scheduler`, `modules` | Frontend-Ausgabe |

## Ein Schreibweg

Editor, Kalenderansicht, ICS-Import, CalDAV, forcal-Import und eigener Code speichern alle über `Scheduler::events()->save()`. Dort passiert in einer Transaktion:

1. UID vergeben, falls leer
2. prüfen und normalisieren (`EventValidator`): Kalender, Titel, Zeitraum, RRULE, Schlüssel der Ausnahmen, URL
3. verwaiste Einzeländerungen entfernen, wenn sich die Regel geändert hat
4. Sequenz erhöhen, neuen ETag vergeben
5. Übersetzungen und Einzeländerungen speichern
6. Synchronisationszähler des Kalenders erhöhen, Änderung für CalDAV protokollieren
7. Vorkommen neu berechnen
8. Extension Point `SCHEDULER_EVENT_SAVED`

Deshalb nie direkt in `rex_scheduler_event` schreiben.

## Vorkommens-Index

`rex_scheduler_occurrence` enthält ein vorberechnetes Vorkommen je Zeile, mit Beginn und Ende in UTC und in der Zeitzone des Termins. Alle Bereichsabfragen filtern und blättern dort in SQL, Termine und Übersetzungen werden danach gesammelt nachgeladen. Es gibt keine Abfrage je Treffer.

- Einzeltermine stehen immer im Index.
- Serien werden vom Beginn bis zum Planungshorizont berechnet, endlose Serien höchstens um den Rückblick zurück. Obergrenze sind 5000 Vorkommen je Termin.
- Der Index ist jederzeit neu aufbaubar: `scheduler:reindex`.
- Die Berechnung selbst übernimmt sabre/vobject. Anzeige, Export und CalDAV sehen dadurch garantiert dieselben Vorkommen.

## Zeit und Zeitzonen

| Spalte | Bedeutung |
|---|---|
| `dtstart`, `dtend` im Termin | Wanduhrzeit in der Zeitzone des Termins (`timezone`). `dtend` ist exklusiv. |
| ganztägig | `dtstart` 00:00 des ersten Tags, `dtend` 00:00 des Tags nach dem letzten |
| `start`, `end` im Index | Wanduhrzeit in der Zeitzone des Termins |
| `start_utc`, `end_utc` im Index | UTC, Grundlage der Bereichsabfragen |
| `created_at`, `updated_at` | UTC |

Schlüssel eines Vorkommens (`recurrenceKey`, EXDATE, RDATE): `Ymd` bei ganztägigen Terminen, sonst `Ymd\THis` in der Zeitzone des Termins.

## Tabellen

| Tabelle | Inhalt |
|---|---|
| `scheduler_calendar` | Kalender |
| `scheduler_event` | Termine und Serien mit allen iCalendar-Kernfeldern, eigene Felder als JSON in `custom` |
| `scheduler_event_lang` | Titel, Kurztext, Beschreibung und übersetzbare eigene Felder je Sprache |
| `scheduler_event_override` | verschobene Einzelvorkommen einer Serie |
| `scheduler_occurrence` | Vorkommens-Index |
| `scheduler_location` | Orte |
| `scheduler_field_schema` | Versionen der Formbuilder-Schemata |
| `scheduler_dav_change` | Änderungsprotokoll für CalDAV-Clients. App-Passwörter liegen im Addon `dav`. |
| `scheduler_subscription` | abonnierte ICS-Adressen mit Intervall und Ergebnis des letzten Abgleichs |

Filterbare eigene Felder bekommen zusätzlich eine virtuelle, indexierte Spalte `cf_<feldname>` in `scheduler_event`.

## Das ORM im eigenen Projekt

```php
use KLXM\Scheduler\Orm\Attribute\{Column, Id, Index, Table};
use KLXM\Scheduler\Orm\SchemaManager;
use KLXM\Scheduler\Scheduler;

#[Table('project_signup')]
#[Index('event', ['event_id'])]
class Signup
{
    #[Id, Column] public private(set) ?int $id = null;
    #[Column] public int $eventId = 0;
    #[Column] public string $name = '';
    #[Column] public ?DateTimeImmutable $createdAt = null;   // in UTC gespeichert
    /** @var array<string, mixed> */
    #[Column] public array $extra = [];                      // JSON
}

SchemaManager::ensure([Signup::class]);                      // install.php
$signups = Scheduler::em()->repository(Signup::class);
$signups->query()->where('eventId', 42)->orderBy('createdAt', 'desc')->get();
```

Spaltennamen entstehen in snake_case aus den Properties, Typen aus den PHP-Typen. Enums, `bool`, `float`, `array` und `DateTimeImmutable` werden umgewandelt. `SchemaManager` legt an und ergänzt, entfernt aber nie Spalten.

## Oberfläche

Die Backend-Seiten rendern vollständiges HTML auf dem Server. Web Components werten es auf: `scheduler-period`, `scheduler-recurrence`, `scheduler-calendar`, `scheduler-field-builder`, `scheduler-tabs`, `scheduler-lang-field`, `scheduler-repeater`, `scheduler-form`, `scheduler-tags`. Farben hängen an Custom Properties und folgen dem hellen und dunklen REDAXO-Theme.

Zusammenspiel mit `a11y_datetime_addon`: Der Picker legt je Feld ein sichtbares Ersatzfeld an, das die Klasse `a11y_datetime` erbt. scheduler markiert diese Ersatzfelder nach jedem `rex:ready`, damit ein späterer Durchlauf sie nicht selbst zum Picker macht, und setzt Werte, Mindestdatum und Sperrzustand über die Picker-Instanz (`assets/js/util.js`).

## Sprachen

Alle Texte stehen in `lang/de_de.lang` und `lang/en_gb.lang`, im Code gibt es keine festen Texte. Eine weitere Sprache ist eine weitere Datei mit denselben Schlüsseln.

| Aufruf | Zweck |
|---|---|
| `I18n::t('event_saved')` | Text in der Sprache des Backend-Benutzers, Platzhalter `{0}`, `{1}` |
| `I18n::e('event_saved')` | dasselbe, für HTML maskiert |
| `I18n::front('front_back')` | Text für die Website. Die Sprache folgt dem Code der REDAXO-Sprache (`en` wählt `en_gb`). |
| `t('cancel')` aus `assets/js/util.js` | Text in den Web Components. Schlüssel mit dem Präfix `scheduler_js_` gehen als `rex.scheduler_i18n` an den Browser. |

Die Schlüssel tragen in den Dateien das Präfix `scheduler_`, im Code steht es nicht. Wochentage und Datumsformate kommen aus `Intl`. Texte lassen sich wie in REDAXO üblich im Projekt überschreiben. Ausnahmen des ORM richten sich an Entwickler und sind nicht übersetzt.

## Tests und Qualität

```bash
composer install
vendor/bin/phpunit --testsuite unit                                   # ohne REDAXO
SCHEDULER_REDAXO_BOOT=/pfad/boot.php vendor/bin/phpunit               # mit Datenbank
```

Die Boot-Datei lädt den REDAXO-Core und setzt einen Administrator als Benutzer. Die Datenbanktests arbeiten in eigenen Kalendern und räumen hinter sich auf.

Für rexstan liegt `phpstan.neon.dist` bei. Sie blendet nur die magischen Properties von sabre/vobject und die untypisierten Arrays der sabre/dav-Interfaces aus.

## Stolpersteine

- Klassen mit Property Hooks können in PHP 8.4 nicht `readonly` sein.
- `ALTER TABLE` beendet in MySQL und MariaDB jede Transaktion. Indexspalten für filterbare Felder entstehen deshalb außerhalb.
- API-Aufrufe über `/index.php?rex-api-call=…` bekommen von yrewrite vorab den Status 404. Die öffentlichen APIs setzen ihn ausdrücklich auf 200.
- `rex_getUrl()` maskiert `&` für HTML. In JSON und Redirects `Frontend::detailUrl(..., forHtml: false)` verwenden.
- `rex_socket` folgt Weiterleitungen nicht von selbst, `IcsFetcher` schon.
- forcal 6 lädt seine `forcal.css` auf allen Backend-Seiten und setzt dort die Schriftfarbe von FullCalendar-Terminen mit `!important`. Die Farbregeln von scheduler hängen deshalb an der ID `#scheduler-calendar-view`.
- Ein modaler `<dialog>` liegt auf einer eigenen obersten Ebene. Overlays anderer Addons, etwa der Kartenpicker von vector_maps, wandern deshalb in den Dialog, solange er offen ist (`ask()` in `assets/js/util.js`).
- `php bin/console assets:sync` gleicht in beide Richtungen ab und kann alte veröffentlichte Dateien in den Addon-Ordner zurückholen.
