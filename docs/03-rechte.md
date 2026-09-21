# Rechte und Freigabe

scheduler nutzt das Rollensystem von REDAXO. Alle Rechte werden unter *Benutzer › Rollen* vergeben. Administratoren dürfen alles.

## Übersicht

| Recht | Bereich in der Rolle | Erlaubt |
|---|---|---|
| `scheduler[]` | Allgemein | Zugang: Kalender und Terminliste sehen, in zugewiesenen Kalendern Termine anlegen, eigene Termine bearbeiten, Kalender-Apps verbinden |
| **Kalender (scheduler)** | eigene Auswahlliste | in diesen Kalendern darf die Rolle pflegen. *Alle Kalender* schließt künftige ein. |
| `scheduler[edit_foreign]` | Optionen | auch Termine bearbeiten, die jemand anderes angelegt hat |
| `scheduler[publish]` | Optionen | Termine online und offline stellen |
| `scheduler[delete]` | Optionen | Termine löschen |
| `scheduler[calendars]` | Optionen | Seite *Kalender verwalten* |
| `scheduler[locations]` | Optionen | Seite *Orte* |

*Felder*, *Module*, *Import* und *Einstellungen* sind Administratoren vorbehalten. Die Hilfe zeigt Nicht-Administratoren nur die Kapitel für die Redaktion.

## Wie die Rechte zusammenspielen

- Ohne zugewiesenen Kalender kann eine Rolle nur lesen.
- „Eigener Termin“ heißt: Der Login steht als Ersteller am Termin.
- Wer nicht veröffentlichen darf, legt Termine **offline** an. Sie warten auf Freigabe. Bestehende Termine behalten beim Bearbeiten ihren Status.
- Wer nicht löschen darf, kann ein Vorkommen ausfallen lassen oder den Status auf *Abgesagt* setzen, aber keinen Termin entfernen.
- Gesehen werden im Backend alle Kalender. Die Pflegerechte entscheiden nur über das Bearbeiten.

## Freigabe-Ablauf

1. Die Zuarbeit hat `scheduler[]` und ihren Kalender. Sie trägt Termine ein, die offline bleiben.
2. Die Redaktion hat zusätzlich `scheduler[edit_foreign]` und `scheduler[publish]`. Sie filtert die Terminliste auf *Offline, wartet auf Freigabe*, prüft und stellt online.

## Typische Rollen

| Rolle | Rechte |
|---|---|
| Zuarbeit, etwa Abteilungen oder Lehrkräfte | `scheduler[]`, Kalender: nur der eigene |
| Redaktion | zusätzlich `scheduler[edit_foreign]`, `scheduler[publish]`, Kalender: alle |
| Chefredaktion | zusätzlich `scheduler[delete]`, `scheduler[calendars]`, `scheduler[locations]` |

## Wo die Regeln gelten

| Weg | Geltung |
|---|---|
| Editor und Terminliste | vollständig |
| Kalenderansicht: Anlegen, Verschieben, Löschen | vollständig. Schnell angelegte Termine sind ohne Freigaberecht offline. |
| CalDAV (Addon `dav`) | Anmelden darf sich, wer zusätzlich `dav[]` hat. Sichtbar sind die zugewiesenen Kalender. Neue Termine aus der App sind ohne Freigaberecht offline, fremde Termine ohne `scheduler[edit_foreign]` schreibgeschützt. Zusätzlich begrenzt jedes App-Passwort auf *nur lesen* oder *lesen und schreiben*. |
| eigener PHP-Code, Konsole, Importe | laufen ohne Benutzer und unterliegen keinen Rechten |

## Im eigenen Code prüfen

```php
use KLXM\Scheduler\Security\Access;

Access::canEditCalendar($calendarId);
Access::canEditEvent($event);
Access::canDeleteEvent($event);
Access::canPublish();
Access::editableCalendars();      // list<Calendar>
```

Ohne Angabe gilt der angemeldete Benutzer. Alle Methoden nehmen optional einen `rex_user` entgegen. Details in der [API-Referenz](08-api-referenz.md#rechte).
