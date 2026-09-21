# scheduler

Termine, Serien, Kalender und Orte für REDAXO. Mit Wiederholungen nach RFC 5545, einem Editor im Stil gängiger Kalender-Apps, CalDAV, ICS-Feeds, eigenen Feldern per Formbuilder und fertigen Frontend-Modulen.

scheduler ist der eigenständige Nachfolger von forcal. Beide Addons laufen nebeneinander, ein Import holt die Daten herüber.

| | |
|---|---|
| **Wiederholungen** | täglich bis jährlich, mehrere Wochentage, „erster Montag im Monat“, Ende nach Datum oder Anzahl, Ausfälle und verschobene Einzeltermine, jede RRULE als Text |
| **Editor** | Ganztägig-Schalter, Zeitzone, Wiederholen-Menü mit Klartext und Vorschau, Serien-Dialog „nur dieser / dieser und folgende / alle“ |
| **Kalenderansicht** | Schnellanlage per Klick, Verschieben und Verlängern per Drag, lädt nur den sichtbaren Zeitraum |
| **CalDAV** | lesen und schreiben mit Apple Kalender, Thunderbird, DAVx⁵ und anderen, über das Addon `dav` |
| **ICS** | Feeds mit ETag, Einzeltermin-Download, Import aus Datei oder URL, fremde Kalender als Abo mit regelmäßigem Abgleich |
| **Eigene Felder** | visueller Formbuilder für Termine, Kalender und Orte, versioniert, als JSON deploybar, mit Verknüpfung zu YForm-Tabellen |
| **Rechte** | je Kalender, eigene und fremde Termine, Freigabe-Ablauf, Löschen |
| **Frontend** | drei Module, anpassbare Fragmente, JSON-Schnittstelle, schema.org-Daten |
| **Leistung** | vorberechneter Vorkommens-Index, Filtern und Blättern vollständig in SQL |

## Voraussetzungen

- REDAXO ab 5.18, PHP ab 8.4 (intl, json, mbstring, xml)
- MySQL ab 5.7 oder MariaDB ab 10.2
- Addon `dav`. Es ist die Zentrale für CalDAV und bringt die sabre-Bibliotheken mit, die scheduler auch für ICS nutzt. scheduler selbst liefert nur noch die RRULE-Bibliothek aus.

Optional, wird automatisch genutzt, wenn installiert:

| Addon | Wirkung |
|---|---|
| `a11y_datetime_addon` | barrierefreier Datums- und Zeitpicker statt der nativen Browserfelder, im Editor, in der Wiederholung und in eigenen Feldern |
| `vector_maps` | Kartenpicker mit Adresssuche für die Koordinaten eines Ortes, Karte auf der Termin-Detailseite |
| `cronjob` | Cronjob-Typen für den Planungshorizont der Serien und für abonnierte Kalender |
| `yform` | Feldtyp, der Datensätze aus YForm-Tabellen auswählt und direkt neu anlegt |
| `builder` | Element *Scheduler-Termine* |

## Installation aus dem Repository

Das Repository enthält den Ordner `vendor` nicht. Zuerst das Addon [dav](https://github.com/KLXM/dav) installieren, dann scheduler nach `redaxo/src/addons/scheduler` klonen und im Addon-Ordner ausführen:

```bash
composer install --no-dev
```

## Schnellstart

1. Addon installieren.
2. **Scheduler › Kalender verwalten:** einen Kalender anlegen.
3. **Scheduler › Kalender:** in einen Tag klicken, Titel eingeben, *Anlegen*.
4. **Scheduler › Module:** die drei Module installieren. Einen Artikel mit *scheduler: Termin* als Detailseite anlegen, dann *scheduler: Terminliste* oder *scheduler: Kalender* einbauen.

Oder im eigenen Code:

```php
use KLXM\Scheduler\Scheduler;

foreach (Scheduler::occurrences()->upcoming()->inCalendars(1)->limit(10)->get() as $occurrence) {
    echo $occurrence->start->format('d.m.Y H:i'), ' ', rex_escape($occurrence->title()), '<br>';
}
```

## Dokumentation

Im Ordner [docs/](docs/) und im Backend unter **Scheduler › Hilfe**.

| Kapitel | Für wen |
|---|---|
| [1. Installation und Einrichtung](docs/01-installation.md) | Administratoren |
| [2. Termine pflegen](docs/02-termine-pflegen.md) | Redaktion |
| [3. Rechte und Freigabe](docs/03-rechte.md) | Administratoren |
| [4. Ausgabe im Frontend](docs/04-frontend.md) | Entwickler |
| [5. Eigene Felder](docs/05-eigene-felder.md) | Administratoren, Entwickler |
| [6. ICS und CalDAV](docs/06-ics-und-caldav.md) | Administratoren, Redaktion |
| [7. Umzug von forcal](docs/07-umzug-von-forcal.md) | Administratoren, Entwickler |
| [8. API-Referenz](docs/08-api-referenz.md) | Entwickler |
| [9. Architektur und Entwicklung](docs/09-entwicklung.md) | Entwickler |

## Lizenz und Credits

MIT, KLXM Crossmedia GmbH. scheduler baut auf [sabre/vobject](https://sabre.io/vobject/), [sabre/dav](https://sabre.io/dav/), [rlanvin/php-rrule](https://github.com/rlanvin/php-rrule) und [FullCalendar](https://fullcalendar.io/). Das Vorgänger-Addon forcal stammt von Joachim Dörr und Friends Of REDAXO.
