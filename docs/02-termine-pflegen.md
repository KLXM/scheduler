# Termine pflegen

Für Redakteurinnen und Redakteure.

## Kalenderansicht

| Aktion | So geht es |
|---|---|
| Termin anlegen | In einen Tag klicken oder einen Zeitraum aufziehen. Titel eingeben, Kalender wählen, *Anlegen*. *Details …* öffnet den vollständigen Editor mit vorbelegtem Zeitraum. |
| Verschieben | Termin mit der Maus auf einen anderen Tag oder eine andere Uhrzeit ziehen |
| Verlängern | am unteren Rand (Wochen- und Tagesansicht) oder am rechten Rand (Monat) ziehen |
| Bearbeiten | Termin anklicken |
| Kalender ein- und ausblenden | Häkchen über dem Kalender |
| Ansicht wechseln | Jahr, Monat, Woche, Tag, Terminübersicht |

Die Schrift auf farbigen Terminen passt sich der Kalenderfarbe an: weiß auf dunklen, dunkel auf hellen Flächen.

Auswahl, Ansicht und Datum merkt sich der Browser. Blasse Termine sind offline, durchgestrichene abgesagt. Termine, die du nicht bearbeiten darfst, lassen sich nicht ziehen.

## Der Editor

| Feld | Hinweise |
|---|---|
| Titel | Pflicht in der ersten Sprache. Bei mehreren Sprachen wechselt der Umschalter über dem Feld, ein Punkt markiert ausgefüllte Sprachen. Fehlt eine Übersetzung, zeigt die Website die erste Sprache. |
| Ganztägig | blendet Uhrzeiten und Zeitzone aus. Das Ende ist der letzte Tag des Termins. |
| Beginn und Ende | Ändert sich der Beginn, wandert das Ende mit und die Dauer bleibt. Ein Ende vor dem Beginn ist nicht möglich. |
| Zeitzone | nur bei Terminen mit Uhrzeit. Eine Serie um 18 Uhr bleibt auch über die Zeitumstellung bei 18 Uhr. |
| Wiederholen | siehe unten |
| Kalender | die Kalender, die du pflegen darfst. *Neuer Kalender* legt einen Kalender im Dialog an. Braucht das Recht, Kalender zu verwalten. |
| Ort | gepflegter Ort aus der Liste oder freier Text. *Neuer Ort* legt einen Ort im Dialog an und wählt ihn aus, ohne den Termin zu verlassen. Braucht das Recht, Orte zu verwalten. |
| Kurztext | erscheint in Listen und als Beschreibung in Kalender-Apps |
| Beschreibung | ausführlicher Text für die Detailseite |
| Sichtbarkeit | online oder offline. Ohne Freigaberecht steht hier „wartet auf Freigabe“. |
| Status | bestätigt, vorläufig oder abgesagt |
| Schlagwörter | Eingabe mit Enter oder Komma bestätigen |
| Link, Veranstalter | optionale Zusatzangaben |
| Vertraulichkeit | Nur öffentliche Termine erscheinen auf der Website und in öffentlichen Abos. |
| Verfügbarkeit | belegt oder frei, wirkt in Kalender-Apps auf die Frei/Gebucht-Anzeige |

*Speichern* kehrt zur Übersicht zurück, *Übernehmen* bleibt im Editor.

**Absagen statt löschen:** Ein abgesagter Termin bleibt sichtbar und wird gekennzeichnet. Wer den Kalender abonniert hat, bekommt die Absage mit. Ein gelöschter Termin verschwindet kommentarlos.

## Wiederholungen

Das Menü *Wiederholen* bietet Nie, Täglich, Wöchentlich, Alle 2 Wochen, Monatlich, Jährlich, *Benutzerdefiniert …* und *Als RRULE-Text …*.

Benutzerdefiniert erlaubt:

- ein Intervall, etwa alle 3 Wochen
- mehrere Wochentage, etwa Dienstag und Donnerstag. Der Wochentag des Beginns ist vorbelegt.
- monatlich am selben Tag des Monats oder am ersten, zweiten, dritten, vierten oder letzten Wochentag
- ein Ende: nie, an einem Datum oder nach einer Anzahl von Terminen

Darunter steht die Regel im Klartext, dazu die nächsten Termine zur Kontrolle.

Beispiele für *Als RRULE-Text …*, wenn die Auswahlfelder nicht reichen:

| Wunsch | Regel |
|---|---|
| letzter Tag jedes Monats | `FREQ=MONTHLY;BYMONTHDAY=-1` |
| letzter Werktag im Monat | `FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1` |
| jeden zweiten Monat am ersten und dritten Freitag | `FREQ=MONTHLY;INTERVAL=2;BYDAY=1FR,3FR` |
| jährlich am zweiten Sonntag im Mai | `FREQ=YEARLY;BYMONTH=5;BYDAY=2SU` |

Monatlich am 31. bedeutet: nur in Monaten mit 31 Tagen.

## Serientermine ändern

Beim Verschieben eines Serientermins im Kalender fragt scheduler nach:

| Auswahl | Wirkung |
|---|---|
| Nur dieser Termin | Das eine Vorkommen wird zur Ausnahme mit eigenem Zeitraum. |
| Dieser und alle folgenden | Die Serie endet davor. Ab hier läuft eine neue Serie mit den geänderten Zeiten. |
| Ganze Serie | Alle Vorkommen verschieben sich um denselben Abstand. |

Ein Klick auf ein Vorkommen öffnet den Editor der ganzen Serie. Oben stehen dann *Nur dieses Vorkommen ausfallen lassen* und *Dieses und alle folgenden entfernen*.

Der Abschnitt *Ausnahmen der Serie* im Editor listet ausgefallene und verschobene Vorkommen. Jedes lässt sich dort zurücknehmen. Ändert sich die Regel so, dass ein verschobenes Vorkommen nicht mehr zur Serie gehört, entfällt die Ausnahme beim Speichern.

## Terminliste

Ein Eintrag je Termin oder Serie, mit dem nächsten Datum. Filter: Suche in Titel und Kurztext, Kalender, Zeitraum.

- *Offline, wartet auf Freigabe* zeigt, was noch veröffentlicht werden muss.
- *Duplizieren* legt eine Offline-Kopie samt Übersetzungen und Ausnahmen an.
- Das Wiederholen-Symbol zeigt beim Überfahren die Regel im Klartext.

## Kalender-Apps

Unter *Kalender-Apps* lässt sich der eigene Kalender mit dem Smartphone oder dem Mailprogramm verbinden, siehe [ICS und CalDAV](06-ics-und-caldav.md).
