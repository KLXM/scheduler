# Ausgabe im Frontend

Drei Wege, von fertig bis frei: mitgelieferte Module, Fragmente, eigene Abfragen.

## Module

Unter *Module* lassen sich drei Module installieren. Sie werden am Modul-Schlüssel wiedererkannt und lassen sich jederzeit auf das Original zurücksetzen.

| Modul | Schlüssel | Zeigt | Optionen |
|---|---|---|---|
| scheduler: Terminliste | `scheduler_list` | kommende Termine als Liste | Überschrift, Kalender, Anzahl, Zeitraum, Schlagwort, Detailseite, nach Monaten gruppieren, Kurztext, Stylesheet |
| scheduler: Kalender | `scheduler_calendar` | interaktiver Monatskalender, auf dem Smartphone als Liste | Kalender, Startansicht, Detailseite |
| scheduler: Termin | `scheduler_detail` | Detailseite mit Ort, Karte, Beschreibung, weiteren Serienterminen, ICS-Download, schema.org-Daten | Übersichtsseite, Anzahl weiterer Termine |

Einrichten: alle drei installieren, einen Artikel „Termin“ mit *scheduler: Termin* anlegen und ihn in Liste und Kalender als Detailseite wählen.

Detailseiten haben die Form `/termin/?event=42`, bei Serien zusätzlich `&date=2026-10-06`. Offline-Termine, nicht öffentliche Termine und Termine inaktiver Kalender liefern 404.

Die Module enthalten kaum Logik, sie rufen Fragmente auf. Das Aussehen ändert man in den Fragmenten. So bleiben die Module updatefähig.

## Fragmente

| Fragment | Variablen |
|---|---|
| `scheduler/list.php` | `occurrences` (list<Occurrence>), `detail_article` (int), `group_by_month` (bool), `show_teaser` (bool, Standard an), `show_location` (bool, an), `show_calendar` (bool, aus), `empty` (Text ohne Treffer) |
| `scheduler/detail.php` | `occurrence` (Occurrence), `back_url` (string), `upcoming` (int, weitere Serientermine, Standard 5), `show_map` (bool, an; braucht `vector_maps` und Koordinaten am Ort) |
| `scheduler/calendar.php` | `calendars` (IDs oder Kurznamen), `detail_article` (int), `view` (`dayGridMonth`, `listMonth`, `timeGridWeek`, `multiMonthYear`) |

```php
$fragment = new rex_fragment();
$fragment->setVar('occurrences', Scheduler::occurrences()->upcoming()->limit(5)->get(), false);
$fragment->setVar('detail_article', 12);
echo $fragment->parse('scheduler/list.php');
```

**Anpassen:** Datei in ein eigenes Addon kopieren, etwa nach `project/fragments/scheduler/list.php`. REDAXO nimmt automatisch die Kopie.

**Styling:** `scheduler-frontend.css` ist bewusst schlicht und erbt Schrift und Farben der Website. Die Akzentfarbe steuert `--scheduler-accent`, die Kalenderfarbe je Termin steht in `--scheduler-color`. Einbinden mit `echo Frontend::stylesheet();` (nur einmal je Seite), in der Terminliste abschaltbar.

## Eigene Abfragen

```php
use KLXM\Scheduler\Scheduler;

$occurrences = Scheduler::occurrences()
    ->upcoming()
    ->inCalendars(1, 3)
    ->withCategory('Fest')
    ->limit(10)
    ->get();

foreach ($occurrences as $o) {
    echo Frontend::formatRange($o), ' ', rex_escape($o->title());
}
```

Standardmäßig kommen nur veröffentlichte Termine aktiver Kalender zurück. Die Vertraulichkeit prüft die Abfrage nicht. Für öffentliche Seiten zusätzlich filtern:

```php
use KLXM\Scheduler\Domain\Enum\Visibility;

$public = array_filter($occurrences, static fn ($o) => Visibility::Public === $o->event?->visibility);
```

Alle Filter, das Vorkommen, der Termin und die Helfer stehen in der [API-Referenz](08-api-referenz.md).

### Rezepte

**Nächster Termin einer Serie**

```php
$next = Scheduler::occurrences()->forEvents(42)->upcoming()->first();
```

**Monatsarchiv mit Blättern**

```php
$page = Scheduler::occurrences()->between('2026-10-01', '2026-11-01')->paginate(rex_get('p', 'int', 1), 20);
// $page->items, $page->total, $page->pages, $page->hasNext
```

**Termine an einem Ort, neueste zuerst**

```php
Scheduler::occurrences()->atLocations(3)->until('now')->latestFirst()->limit(10)->get();
```

**Eigenes Feld ausgeben**

```php
$raum = $o->event->custom('raum');
$bildtext = $o->event->translation($clangId)?->custom['bildtext'] ?? '';
```

**Abo-Link**

```php
echo '<a href="' . Frontend::feedUrl($calendar, webcal: true) . '">Kalender abonnieren</a>';
```

## JSON-Schnittstelle

```
index.php?rex-api-call=scheduler_events&start=2026-10-01&end=2026-11-01
```

Liefert Vorkommen im Format von FullCalendar. Parameter: `calendars` (IDs oder Kurznamen, kommagetrennt), `detail` (Artikel-ID für Links), `clang`. Höchstens 400 Tage je Anfrage, zwei Minuten Cache. Enthalten sind nur veröffentlichte, öffentliche Termine aktiver Kalender.

## Sprechende URLs mit dem url-Addon

Ein Profil auf die Tabelle `rex_scheduler_event` anlegen und im Detail-Template die ID über den `UrlManager` lesen. Der Titel liegt in `rex_scheduler_event_lang` (`event_id`, `clang_id`, `title`). Dieser Weg ist beschrieben, aber nicht mitgeliefert: Die Module arbeiten mit `?event=`.

## Builder

Mit dem Addon *builder* steht das Element **Scheduler-Termine** bereit: Liste, Karten oder kompakt, nach Kalendern oder als nächste Termine einer Serie.
