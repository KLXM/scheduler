# Eigene Felder

Termine, Kalender und Orte lassen sich um eigene Felder erweitern. Sie entstehen im visuellen Formbuilder unter *Felder*.

## Was fest ist

Die Felder des iCalendar-Standards sind fest eingebaut: Titel, Kurztext, Beschreibung, Zeitraum, Zeitzone, Wiederholung, Ausnahmen, Kalender, Ort, Status, Sichtbarkeit, Vertraulichkeit, Verfügbarkeit, Link, Veranstalter, Schlagwörter. So funktioniert jeder Termin mit jeder Kalender-App. Eigene Felder dürfen diese Namen nicht tragen.

## Der Formbuilder

| Bereich | Funktion |
|---|---|
| links: Palette | Struktur-Elemente und Feldtypen. Anklicken fügt am gewählten Ort ein, Ziehen legt gezielt ab. |
| Mitte: Formular | Elemente ziehen oder mit den Pfeilen verschieben, duplizieren, entfernen. Alles ist auch mit der Tastatur bedienbar. |
| rechts: Eigenschaften | Einstellungen des gewählten Elements |
| unten: Vorschau | derselbe Code wie im Editor, mit funktionierenden Tabs und Sichtbarkeitsregeln |
| Veröffentlichen | speichert eine neue Version. Vorher ändert sich im Editor nichts. |

Oben wählt man das Ziel: Termine, Kalender oder Orte.

### Struktur-Elemente

| Element | Zweck |
|---|---|
| Tab | Reiter auf oberster Ebene. Aufeinanderfolgende Tabs bilden eine Leiste. |
| Gruppe | umrahmter Block mit Überschrift |
| Spalten | Felder nebeneinander, Breite je Spalte 1 bis 12 |
| Wiederholung | beliebig viele Zeilen mit denselben Feldern, etwa Referenten. Mindest- und Höchstzahl einstellbar. |

### Feldtypen

| Typ | Schlüssel | Gespeichert als | Einstellungen |
|---|---|---|---|
| Text | `text` | string | Eingabeart (Text, E-Mail, URL, Telefon), Platzhalter, maximale Länge |
| Mehrzeiliger Text | `textarea` | string | Zeilen, Editor-Klasse, Editor-Profil |
| Zahl | `number` | int oder float | Minimum, Maximum, Schrittweite |
| Datum | `date` | `JJJJ-MM-TT` | nutzt a11y_datetime, wenn installiert |
| Uhrzeit | `time` | `HH:MM` | nutzt a11y_datetime, wenn installiert |
| Auswahlliste | `select` | string, bei Mehrfachauswahl Liste | Auswahlmöglichkeiten, SQL, Mehrfachauswahl |
| Optionsfelder | `radio` | string | Auswahlmöglichkeiten, SQL |
| Schalter | `checkbox` | bool | Text neben dem Schalter |
| Farbe | `color` | `#rrggbb` | |
| Schlagwörter | `tags` | Liste | maximale Anzahl |
| Medium | `media` | Dateiname | Dateitypen, Medienkategorie, Vorschau |
| Medienliste | `medialist` | Liste von Dateinamen | wie Medium |
| Interner Link | `link` | Artikel-ID | Start-Kategorie |
| Linkliste | `linklist` | Liste von Artikel-IDs | Start-Kategorie |
| YForm-Datensatz | `yform` | ID, bei Mehrfachauswahl Liste von IDs | YForm-Tabelle, Feld für die Anzeige, Mehrfachauswahl, Neuanlage erlauben. Nur mit dem Addon yform. |

Auswahlmöglichkeiten: eine Zeile je Eintrag in der Form `wert|Beschriftung`. Oder ein SQL-SELECT mit dem Wert in der ersten und der Beschriftung in der zweiten Spalte.

Medien- und Link-Felder nutzen die REDAXO-Widgets und sind in Wiederholungen nicht erlaubt.

### YForm-Datensätze verknüpfen

Der Typ *YForm-Datensatz* zeigt die Datensätze einer YForm-Tabelle als Auswahlliste, etwa Referenten, Mannschaften oder Räume. Mit *Neue Datensätze direkt anlegen lassen* erscheint unter der Liste ein Knopf, der das YForm-Formular im Popup öffnet. Nach dem Schließen lädt die Liste neu und wählt den neuen Datensatz aus. Der Termin bleibt dabei offen.

```php
$id = $event->custom('referent');
$referent = $id ? rex_yform_manager_dataset::get((int) $id, 'rex_referenten') : null;

foreach ($event->custom('mannschaften', []) as $id) {
    $team = rex_yform_manager_dataset::get((int) $id, 'rex_mannschaften');
}
```

Es gelten die Rechte von YForm: Das Popup sieht nur, wer die Tabelle bearbeiten darf. Angezeigt werden höchstens 2000 Datensätze.

### Optionen je Feld

| Option | Wirkung |
|---|---|
| Feldname | Schlüssel, unter dem der Wert gespeichert wird. Kleinbuchstaben, Ziffern, Unterstriche. Wird aus der Beschriftung vorgeschlagen. |
| Pflichtfeld | bei übersetzbaren Feldern gilt die Pflicht in der ersten Sprache |
| Übersetzbar | eigener Wert je Sprache |
| Filterbar | legt eine indexierte Datenbankspalte an, damit `whereCustom()` auch bei vielen Terminen schnell bleibt. Nicht mit „übersetzbar“ kombinierbar. |
| In Kalender-Abos ausgeben | erscheint im ICS-Feed als `X-SCHEDULER-FELDNAME`. Standard ist aus, weil Feeds oft öffentlich sind. Nur bei Terminen. |
| Sichtbarkeit | Feld, Gruppe oder Tab nur zeigen, wenn ein anderes Feld einen bestimmten Wert hat, ausgefüllt oder leer ist. Ausgeblendete Pflichtfelder werden nicht geprüft und nicht gespeichert. Bei Schaltern gilt 1 für an und 0 für aus. |

## Umbenennen, Entfernen, Versionen

- **Umbenennen:** Beim Veröffentlichen ziehen die gespeicherten Werte auf den neuen Namen um.
- **Entfernen:** Der Builder nennt vorher, in wie vielen Datensätzen Werte stehen. Die Werte bleiben in der Datenbank und tauchen wieder auf, wenn ein Feld gleichen Namens zurückkommt.
- **Versionen:** Jede Veröffentlichung ist eine Version. Unter *Versionen und Austausch* lässt sich eine frühere als neue Version wiederherstellen.

## Werte lesen

```php
$event->custom('raum');                                 // einfaches Feld
$event->custom('plaetze', 0);                           // mit Vorgabe
$event->custom('referenten', []);                       // Wiederholung: Liste von Arrays
$event->translation($clangId)?->custom['bildtext'];     // übersetzbares Feld
$calendar->custom['ansprechpartner'] ?? null;
$location->custom['barrierefrei'] ?? false;
```

Übersetzbare Felder von Kalendern und Orten liegen unter `$calendar->custom['_translations'][$clangId]['feld']`.

## Deployment

1. Unter *Felder* auf *Als JSON exportieren*, je Ziel eine Datei.
2. Ins Repository legen, etwa `project/scheduler/scheduler-fields-event.json`.
3. Beim Deployment: `php bin/console scheduler:schema-sync project/scheduler/*.json`

Der Befehl legt nur dann eine neue Version an, wenn sich die Datei vom aktiven Schema unterscheidet.

## Eigene Feldtypen

```php
use KLXM\Scheduler\Field\FieldContext;
use KLXM\Scheduler\Field\FieldNode;
use KLXM\Scheduler\Field\Type\AbstractType;
use KLXM\Scheduler\Scheduler;

final class RatingType extends AbstractType
{
    public function key(): string { return 'rating'; }
    public function label(): string { return 'Bewertung'; }
    public function icon(): string { return 'fa-star'; }

    public function options(): array
    {
        return [['name' => 'max', 'label' => 'Höchstwert', 'type' => 'number']];
    }

    public function render(FieldContext $context): string
    {
        return sprintf('<input type="range" min="1" max="%d" %s value="%d">', (int) $context->node->option('max', 5), $context->baseAttributes(), (int) $context->value);
    }

    public function normalize(mixed $raw, FieldNode $node): mixed
    {
        return is_numeric($raw) ? (int) $raw : null;
    }
}

// boot.php des Projekt-Addons
Scheduler::schemas()->types->register(new RatingType());
```

`AbstractType` bringt sinnvolle Vorgaben mit. Überschreibbar: `options()`, `normalize()`, `validate()`, `isEmpty()`, `toText()` (Ausgabe im ICS-Feed), `allowedInRepeater()`. Das vollständige Interface steht in der [API-Referenz](08-api-referenz.md#eigene-felder).
