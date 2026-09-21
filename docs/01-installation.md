# Installation und Einrichtung

## Installieren

Über den REDAXO-Installer oder per Git in `redaxo/src/addons/scheduler`, dann im Addon-Verzeichnis `composer install --no-dev` und das Addon im Backend installieren. scheduler legt eigene Tabellen mit dem Präfix `rex_scheduler_` an und verändert sonst nichts.

Voraussetzungen: REDAXO ab 5.18, PHP ab 8.4 mit intl, json, mbstring und xml, MySQL ab 5.7 oder MariaDB ab 10.2.

## Addon dav

scheduler setzt das Addon `dav` voraus. Es stellt den CalDAV-Server, die Anmeldung, die App-Passwörter und die sabre-Bibliotheken (sabre/vobject, sabre/dav) für alle Addons bereit, die darauf aufbauen. So gibt es jede Bibliothek nur einmal, in einer Version.

## Optionale Addons

scheduler erkennt diese Addons selbst. Es gibt keine Einstellung dafür.

| Addon | Was sich ändert |
|---|---|
| `a11y_datetime_addon` | Datum und Uhrzeit wählt man über dessen barrierefreien Picker: im Termin-Editor, beim Enddatum einer Wiederholung und in eigenen Feldern vom Typ Datum und Uhrzeit. Ohne das Addon erscheinen die nativen Felder des Browsers. Gespeichert wird in beiden Fällen dasselbe. |
| `vector_maps` | Bei Orten öffnet ein Knopf neben den Koordinaten eine Karte mit Adresssuche. Auf der Termin-Detailseite erscheint eine Karte des Ortes. Ohne das Addon trägt man Breite und Länge von Hand ein. |
| `cronjob` | stellt die Cronjob-Typen *scheduler: Serientermine vorausberechnen* und *scheduler: Kalender-Abos abgleichen* bereit |
| `yform` | Feldtyp *YForm-Datensatz* für eigene Felder |
| `builder` | stellt das Element *Scheduler-Termine* bereit |

Empfehlung: `a11y_datetime_addon` installieren. Die nativen Datumsfelder sind vor allem in Safari auf dem Desktop unkomfortabel.

## Erste Schritte

1. **Kalender anlegen** unter *Kalender verwalten*: Name, Farbe, Zeitzone. Ein Kalender bündelt Termine, hat eigene Rechte und entspricht einem Kalender in der Kalender-App.
2. **Orte anlegen** unter *Orte*, falls Termine an wiederkehrenden Adressen stattfinden. Alternativ bekommt ein Termin einen freien Ortstext.
3. **Rollen einrichten**, siehe [Rechte und Freigabe](03-rechte.md).
4. **Module installieren** unter *Module*, siehe [Ausgabe im Frontend](04-frontend.md).
5. **Cronjob anlegen**, siehe unten.

## Begriffe

| Begriff | Bedeutung |
|---|---|
| Kalender | Sammlung von Terminen mit Farbe, Zeitzone und eigenen Rechten |
| Termin | ein einzelnes Ereignis oder eine Serie mit Wiederholungsregel |
| Vorkommen | ein konkretes Datum eines Termins. Eine wöchentliche Serie ist ein Termin mit vielen Vorkommen. |
| Ausnahme | ein Vorkommen, das ausfällt oder verschoben wurde |
| Schlagwörter | freie Stichworte am Termin, in iCalendar die Eigenschaft CATEGORIES |

## Einstellungen

| Einstellung | Wirkung | Standard |
|---|---|---|
| Standard-Zeitzone | Vorgabe für neue Kalender, Bezug für Zeitangaben ohne Zeitzone | Europe/Berlin |
| Neue Termine ganztägig | Vorbelegung des Ganztägig-Schalters | an |
| Wochenbeginn | erste Spalte der Kalenderansichten | Montag |
| Editor-Klasse | CSS-Klasse, die einen WYSIWYG-Editor für die Beschreibung aktiviert, etwa `tiny-editor` oder `cke5-editor` | leer |
| Editor-Profil | Profil des Editors (`data-profile`) | leer |
| Planungshorizont | so weit im Voraus werden Serien berechnet | 36 Monate |
| Rückblick endloser Serien | so weit zurück werden endlose Serien höchstens berechnet | 12 Monate |
| Kalender-Abos ab und bis | Zeitfenster der ICS-Feeds | 1 Monat zurück, 24 voraus |

*Vorkommen neu berechnen* baut den Index aller Termine neu auf. Nötig nach einer Änderung des Planungshorizonts.

## Cronjob

Serien ohne Ende werden bis zum Planungshorizont vorausberechnet. Damit der Horizont mitwandert, im Cronjob-Addon einen Job vom Typ **scheduler: Serientermine vorausberechnen** anlegen, einmal täglich. Er rechnet nur Serien nach, deren berechneter Zeitraum weniger als den halben Horizont vorausreicht.

Ohne Cronjob: `php bin/console scheduler:reindex --extend`.

Wer fremde Kalender abonniert, legt zusätzlich einen Job vom Typ **scheduler: Kalender-Abos abgleichen** an, etwa stündlich. Siehe [ICS und CalDAV](06-ics-und-caldav.md).

## Deinstallieren

Die Deinstallation löscht alle Tabellen `rex_scheduler_*` und damit alle Termine. Vorher ein Backup anlegen oder die Kalender als ICS exportieren.
