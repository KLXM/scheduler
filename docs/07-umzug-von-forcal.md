# Umzug von forcal

scheduler ist ein eigenständiges Addon und der Nachfolger von forcal. Beide laufen **nebeneinander**: scheduler hat eigene Tabellen, eigene Rechte und eine eigene API und fasst nichts von forcal an. Der Umzug geschieht in Ruhe, Seite für Seite, während die Website mit forcal weiterläuft.

## Ablauf

1. **scheduler installieren.** forcal bleibt installiert und aktiv. An der Website ändert sich nichts.
2. **Daten übernehmen.** Unter *Scheduler › Import › Übernahme aus forcal* zuerst **Probelauf**, dann **Übernehmen**. Auf der Konsole:

   ```bash
   php bin/console scheduler:import-legacy --dry-run -v
   php bin/console scheduler:import-legacy
   ```

3. **Prüfen.** Kalenderansicht und Terminliste in scheduler mit forcal vergleichen. Den Prüfbericht des Imports lesen.
4. **Ausgabe umstellen.** Module und Templates nach und nach auf scheduler umbauen, siehe unten. Die Import-Seite listet alle Stellen, die noch forcal aufrufen.
5. **Redaktion umstellen.** Rollen in scheduler einrichten, siehe **Rechte**. Ab einem Stichtag pflegt die Redaktion nur noch in scheduler.
6. **Letzter Abgleich.** Wurde bis zum Stichtag noch in forcal gepflegt, den Import ein letztes Mal starten.
7. **forcal deinstallieren**, wenn nichts mehr darauf zugreift. Erst die Deinstallation löscht die forcal-Tabellen.

Bis Schritt 7 lässt sich jederzeit abbrechen: scheduler deinstallieren, und alles ist wie vorher.

## Was der Import überträgt

| forcal | scheduler |
|---|---|
| Kategorie | Kalender, gleiche ID. Eine eigene Beschreibung wird zur Kalenderbeschreibung. |
| Ort | Ort, gleiche ID. Straße und Hausnummer stehen in einem Feld. |
| Termin | Termin, gleiche ID, solange sie frei ist |
| Name, Teaser, Text je Sprache | Übersetzungen des Termins |
| Ganztägig, oder 00:00 bis 00:00 Uhr | ganztägiger Termin |
| Ende vor oder gleich Beginn | Ende am Folgetag, wie in der forcal-Kalenderansicht |
| Status | online oder offline |
| Tagging-Feld `tags` | Schlagwörter |
| Eigene Felder aus den YAML-Definitionen | Formbuilder-Schema; die Werte ziehen um |
| Termine ohne Kategorie | Kalender „Importiert“ |
| Zeitzone | die Standard-Zeitzone, abweichend per `--timezone=` |

Die forcal-Tabellen werden nur gelesen.

### Wiederholungen

| forcal | RRULE |
|---|---|
| wöchentlich, alle n Wochen | `FREQ=WEEKLY;INTERVAL=n` |
| monatlich, alle n Monate | `FREQ=MONTHLY;INTERVAL=n;BYMONTHDAY=Tag` |
| monatlich am ersten bis letzten Wochentag | `FREQ=MONTHLY;BYDAY=1MO` bis `-1SU` |
| jährlich, alle n Jahre | `FREQ=YEARLY;INTERVAL=n` |
| Enddatum der Wiederholung | `UNTIL` |
| Wiederholung ohne Enddatum | Einzeltermin, denn forcal zeigt nur den ersten |

forcal rechnet an einigen Stellen anders als der Standard. Monatlich ab dem 31. Januar ergibt dort den 3. März, 3. April und so weiter. Der Import vergleicht deshalb jede Serie Tag für Tag mit der forcal-Berechnung und gleicht Unterschiede über Zusatztermine und Ausnahmen aus. **Nach dem Import erscheinen exakt dieselben Tage wie in forcal.** Der Prüfbericht nennt jede betroffene Serie. Wer die Eigenheit nicht behalten will, öffnet die Serie und stellt die Regel neu ein.

### Wiederholbar

Jeder übernommene Datensatz merkt sich seine Herkunft. Ein weiterer Lauf aktualisiert aus forcal und legt nichts doppelt an. Das macht die Übergangszeit einfach: Solange die Redaktion noch in forcal pflegt, holt ein erneuter Import den aktuellen Stand.

Dabei gilt: Kernangaben wie Zeitraum, Titel und Wiederholung werden auf den forcal-Stand gesetzt. Werte in eigenen Feldern, die nur in scheduler gepflegt wurden, bleiben erhalten. Termine, die in scheduler neu angelegt wurden, berührt der Import nicht. In forcal gelöschte Termine bleiben in scheduler stehen.

### Nicht automatisch

- **Rechte.** forcal vergibt sie je Benutzer, scheduler je Rolle.
- **Gespeicherte Filter** der forcal-Terminliste.

## Code umstellen

| forcal | scheduler |
|---|---|
| `forCalEventsFactory::create()->from()->to()->inCategories()->get()` | `Scheduler::occurrences()->between()->inCalendars()->get()` |
| `forCalHandler::exchangeEntries($start, $end, …)` | `Scheduler::occurrences()->between($start, $end)->get()` |
| `forCalHandler::exchangeEntry($id)` | `Scheduler::events()->find($id)` |
| `$entry->entry_start_date`, `entry_start_time` | `$occurrence->start` |
| `$entry->entry_end_date`, `entry_end_time` | `$occurrence->end`, ganztägig `$occurrence->lastDay` |
| `$entry->entry_name` | `$occurrence->title()` |
| `$entry->entry_teaser`, `entry_text` | `$event->translation()?->teaser`, `->description` |
| `$entry->category_name`, `category_color` | `Scheduler::calendars()->find($occurrence->calendarId)` |
| `$entry->venue_name` | `Scheduler::locations()->find($event->locationId)?->name` |
| eigene Felder `entries_bild` | `$event->custom('bild')` |
| `forCalLink` für Google, Outlook, ICS | `Frontend::icsUrl($event)` |
| `rex-api-call=forcal_ical&category=1` | `rex-api-call=scheduler_feed&calendar=kurzname` |
| `rex-api-call=forcal_exchange` | `rex-api-call=scheduler_events` |
| Builder-Element `forcal_list` | Builder-Element `scheduler_list` |

Oft ist es am schnellsten, das alte Ausgabemodul durch *scheduler: Terminliste* zu ersetzen und nur das Fragment anzupassen. Weil die IDs erhalten bleiben, funktionieren Detail-Links mit der Termin-ID weiter.

Abonnenten eines forcal-iCal-Feeds müssen die neue Adresse abonnieren. Solange forcal installiert ist, läuft der alte Feed weiter.
