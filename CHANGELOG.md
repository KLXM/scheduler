# Changelog

## 1.0.0 – in Arbeit

Erste Version. scheduler ist der eigenständige Nachfolger von forcal und läuft parallel dazu.

- Wiederholungen nach RFC 5545 (RRULE, EXDATE, RDATE, Einzelausnahmen) auf Basis von sabre/vobject, mit Zeitzonen und korrektem Verhalten über die Zeitumstellung
- Termin-Editor im Kalender-Stil mit Wiederholen-Menü, Klartext, Vorschau der nächsten Termine und Serien-Dialog
- Kalenderansicht mit Schnellanlage, Verschieben und Größenänderung per Drag
- CalDAV (lesen und schreiben, sync-collection) als Provider für das Addon `dav`, das scheduler voraussetzt und dessen sabre-Bibliotheken es mitnutzt
- ICS-Feeds mit ETag, ICS-Import aus Datei oder URL mit Abgleich über die UID
- Visueller Formbuilder für eigene Felder mit Versionierung, Umbenennen mit Werte-Umzug, JSON-Export für Deployments
- Vorkommens-Index: Bereichsabfragen, Filter und Paginierung vollständig in SQL
- Rechte: Rollenrecht je Kalender, fremde Termine bearbeiten, veröffentlichen (Freigabe-Ablauf), löschen; gültig in Backend, Kalenderansicht und CalDAV
- Frontend: drei installierbare Module (Liste, Kalender, Termin), Fragmente, öffentliche JSON-Schnittstelle, schema.org-Daten
- Umzug von forcal: wiederholbarer Import mit Probelauf, Prüfbericht, Ausgleich abweichender Wiederholungen und Code-Scanner; forcal bleibt dabei unverändert
- Je Kalender einstellbar, ob er in Kalender-Apps erscheint; die Seite „Kalender-Apps“ führt in drei Schritten zur Verbindung, App-Passwort inklusive
- Oberfläche, Meldungen, Web Components und Frontend-Ausgabe vollständig in Deutsch und Englisch; die Website folgt der REDAXO-Sprache
- Kalender-Abos: ICS-Adressen mit Intervall regelmäßig abgleichen, per Cronjob oder Konsole
- Anlegen aus dem Termin-Editor: Orte und Kalender im Dialog, YForm-Datensätze im Popup
- Nutzt `a11y_datetime_addon`, `vector_maps` und `yform`, wenn installiert
- Builder-Element `scheduler_list`, Cronjob für den Planungshorizont, Konsolenbefehle
- Dokumentation in `docs/` und als Hilfe-Seite im Backend
- Eigenes schlankes ORM mit Attribut-Mapping; das Schema entsteht aus den Entities
