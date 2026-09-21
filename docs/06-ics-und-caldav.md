# ICS und CalDAV

| Weg | Richtung | Wofür |
|---|---|---|
| ICS-Feed | hinaus, nur lesen | Besucher abonnieren einen Kalender |
| ICS-Download | hinaus | ein einzelner Termin für den eigenen Kalender |
| ICS-Import | herein, einmalig | fremde Kalender übernehmen |
| Kalender-Abo | herein, regelmäßig | fremde Kalender spiegeln, etwa Schulferien oder Feiertage |
| CalDAV | beide Richtungen | Redaktion pflegt Termine in der Kalender-App |

## ICS-Feed zum Abonnieren

Am Kalender *Öffentlicher ICS-Feed* einschalten. Die Adresse steht danach im Kalender-Formular:

```
https://example.org/index.php?rex-api-call=scheduler_feed&calendar=schule
```

| Parameter | Bedeutung |
|---|---|
| `calendar` | Kurzname oder ID, mehrere mit Komma: `schule,verein` |
| `clang` | Sprach-ID für Titel und Beschreibung |
| `event` | statt `calendar`: ein einzelner Termin als Download |

- Mit `webcal://` statt `https://` öffnet ein Klick direkt den Abo-Dialog der Kalender-App. In PHP: `Frontend::feedUrl($calendar, webcal: true)`.
- Enthalten sind veröffentlichte Termine mit öffentlicher Vertraulichkeit im Zeitfenster aus den Einstellungen.
- Serien gehen als ein Termin mit Wiederholungsregel, Ausfällen und Einzeländerungen hinaus, inklusive Zeitzonendefinition (VTIMEZONE).
- Der Feed trägt einen ETag. Ändert sich nichts, antwortet der Server mit 304.
- Angemeldete Backend-Benutzer erreichen auch nicht öffentliche Kalender, wenn sie den Feed über `redaxo/index.php?rex-api-call=scheduler_feed&calendar=…` aufrufen.

## ICS importieren

Unter *Import* eine Datei hochladen oder eine Adresse angeben, Zielkalender wählen, zuerst *Probelauf*.

- Termine werden über ihre UID wiedererkannt. Ein erneuter Import aktualisiert, statt zu duplizieren.
- Adressen mit `webcal://` werden verstanden, Weiterleitungen verfolgt. Liegt unter der Adresse kein Kalender, sagt die Fehlermeldung das deutlich.
- Die Option *Abgleich* entfernt bei URL-Importen Termine, die in der Quelle nicht mehr vorkommen. So lässt sich ein fremder Kalender spiegeln, etwa Schulferien oder Feiertage.
- Eine UID, die schon in einem anderen Kalender existiert, wird übersprungen und im Bericht genannt.
- Regelmäßig per Cronjob oder Deployment:

```bash
php bin/console scheduler:import-ics https://example.org/ferien.ics ferien --sync
```

## Kalender abonnieren

Ein einmaliger Import bleibt stehen, wie er ist. Soll scheduler eine Adresse dauerhaft verfolgen, beim Import per URL die Option *Adresse abonnieren und regelmäßig abgleichen* einschalten und ein Intervall wählen: stündlich, alle 6 Stunden, täglich oder wöchentlich.

- Der erste Abgleich passiert sofort beim Import.
- Danach übernimmt der Cronjob-Typ **scheduler: Kalender-Abos abgleichen**. Er darf häufig laufen, etwa stündlich: Jedes Abo kennt sein eigenes Intervall.
- Mit *Abgleich* werden Termine gelöscht, die in der Quelle nicht mehr vorkommen. Ohne diese Option kommen nur neue und geänderte Termine hinzu.
- Die Liste *Abonnierte Kalender* auf der Import-Seite zeigt Zustand und Ergebnis des letzten Laufs. Dort lässt sich ein Abo sofort abgleichen, pausieren oder beenden. Beim Beenden bleiben die übernommenen Termine erhalten.
- Ein Fehler bei einem Abo, etwa eine nicht erreichbare Adresse, hält die anderen nicht auf und steht als Meldung am Abo.
- Änderungen, die jemand im Backend an einem abonnierten Termin macht, überschreibt der nächste Abgleich. Abonnierte Kalender sind zum Lesen gedacht.
- Ohne Cronjob: `php bin/console scheduler:sync-subscriptions`, mit `--all` unabhängig vom Intervall.

## CalDAV

Mit CalDAV erscheinen die scheduler-Kalender direkt in der Kalender-App. Änderungen gleichen sich in beide Richtungen ab.

Das Addon `dav` stellt den Server, die Anmeldung und die App-Passwörter bereit, scheduler hängt dort seine Kalender ein.

### Einrichten

1. Je Kalender entscheiden, ob er in Kalender-Apps erscheint: *Scheduler › Kalender › bearbeiten › In Kalender-Apps anbieten*. Neue Kalender sind freigegeben. Ausschalten lohnt sich für Kalender, die nur der Website dienen, etwa abonnierte Feiertage.
2. Die Seite *Scheduler › Kalender-Apps* öffnen. Sie zeigt, welche Kalender das eigene Konto in der App sieht, und führt in drei Schritten durch die Verbindung: Gerät benennen und Zugriff wählen (*nur lesen* oder *lesen und schreiben*), App-Passwort erzeugen, Zugangsdaten kopieren. Das Passwort ist nur direkt nach dem Erzeugen sichtbar. Darunter stehen die eigenen Geräte mit letzter Nutzung und dem Knopf zum Widerrufen.
3. In der App ein CalDAV-Konto mit den angezeigten Daten anlegen:

| Angabe | Wert |
|---|---|
| Server | `https://example.org/dav/` |
| Benutzername | der REDAXO-Login |
| Passwort | das App-Passwort, nicht das REDAXO-Passwort |

| App | Weg |
|---|---|
| Apple Kalender (macOS, iOS) | Account hinzufügen › Anderer CalDAV-Account › Manuell oder Erweitert. Apple verlangt HTTPS mit gültigem Zertifikat. |
| Thunderbird | Neuer Kalender › Im Netzwerk › Adresse und Benutzername |
| Android | DAVx⁵ mit „URL und Benutzername“ |
| Outlook | mit dem kostenlosen CalDav Synchronizer |

Viele Apps finden den Server über die Domain allein, weil `/.well-known/caldav` weiterleitet. Ein App-Passwort je Gerät ist sinnvoll: Geht ein Gerät verloren, widerruft man nur dieses.

### Was abgeglichen wird

| Inhalt | Verhalten |
|---|---|
| Titel, Zeitraum, Zeitzone, Wiederholung, Ausfälle, Einzeländerungen, Ort, Status, Schlagwörter, Link | vollständig in beide Richtungen |
| Erinnerungen, Teilnehmer und andere Angaben, die scheduler nicht kennt | bleiben erhalten und gehen unverändert zurück |
| Beschreibung | kommt als reiner Text. Formatierter Text aus dem Backend bleibt erhalten, solange sich der reine Text nicht ändert. |
| Sprache | Titel und Beschreibung beziehen sich auf die erste Sprache |
| Ort | Die App kennt nur Text. Stimmt er mit einem gepflegten Ort überein, bleibt die Zuordnung. |
| Eigene Felder | werden nicht abgeglichen |

### Rechte

Anmelden darf sich, wer das Recht `dav[]` hat. Sichtbar sind die Kalender, die der Benutzer laut Rolle pflegen darf und bei denen *In Kalender-Apps anbieten* eingeschaltet ist. Es gelten dieselben Regeln wie im Backend, siehe [Rechte und Freigabe](03-rechte.md). Kalender lassen sich über CalDAV weder anlegen noch löschen, Name und Farbe gehören dem Backend.

### Fehlersuche

| Symptom | Ursache und Abhilfe |
|---|---|
| 404 unter `/dav/` | Die Anfrage kommt nicht bei REDAXO an. Mit yrewrite ist das der Fall. Ohne Umschreibung funktioniert `https://example.org/index.php/dav/`. |
| 401 trotz richtigem Passwort | Ist der Benutzer aktiv, hat er `dav[]` und `scheduler[]`? Manche Server entfernen den Authorization-Header. Abhilfe in der `.htaccess`: `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` |
| Kalender fehlt in der App | Der Rolle ist der Kalender nicht zugewiesen, oder *In Kalender-Apps anbieten* ist beim Kalender ausgeschaltet. |
| Apple lehnt ab | kein HTTPS oder ungültiges Zertifikat |
| Änderungen aus der App erscheinen nicht auf der Website | Dem Benutzer fehlt `scheduler[publish]`, der Termin ist offline und wartet auf Freigabe. |

Im Debug-Modus von REDAXO zeigt der Browser unter `/dav/` eine Übersicht des Servers.
