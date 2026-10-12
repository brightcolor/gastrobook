# Neue Oberfläche, Teil 3: Prüfprotokoll

Plan: `docs/superpowers/plans/2026-10-12-new-ui-teil-3.md`
Spec: `docs/superpowers/specs/2026-10-07-new-ui-admin-design.md`
Zweig `new-ui`, Stand 164d5fb plus dieses Protokoll (Plan 2975af6, Abgleich mit main 1.134.8 seit aabd562). Nicht gepusht, nicht ausgerollt.

## Ablauf

Freigabe Mathias (11.10.2026, „schlank ja mit sonnet“): je Aufgabe ein Umsetzer und ein Prüfer, ein Gesamtprüfer, alle auf Sonnet 5.5. Korrekturen gingen per Nachricht an denselben Umsetzer bzw. Prüfer; die Korrekturwelle der Gesamtprüfung übernahm der Umsetzer von Aufgabe 3. Der erste Umsetzer von Aufgabe 5 brach ohne Änderung ab (API nicht erreichbar); der Neustart lief nach neuer Freigabe („ja mach“).

| Aufgabe | Commits | Prüfung |
|---|---|---|
| 0 Abgleich mit main 1.134.8 | aabd562 | main unverändert bei 86b1f47 |
| 1 Öffnungszeiten | 9950e57, 093dc67, c5c5c53 | 1 Runde (Überschneidung auf der echten Wochenachse, So → Mo) |
| 2 Ausnahmen, Backend | 8ac9531, 41ab507 | 1 Runde („Geschlossen“ und „Andere Öffnungszeiten“ je Datum exklusiv, Satz mit den Zeiten des Tages, deutsche 404, Standortsperre `for no key update`) |
| 3 Ausnahmen, Seite und „Sperren…“ | 1833a2a, d629bf0 | 1 Runde (inaktive Räume und Tische bleiben beim Bearbeiten, „Sperren…“ an jedem Tisch, `?tisch` nach Gebrauch entfernt) |
| 4 Abbau der Sammelseite, Rechte je Seite, Assistent | 4859b50, bc26cc2, cac5fff | freigegeben, Nachbesserung (Reste der alten Seite, Absenderschalter, Kästchen „aktiv“ getestet) |
| 5 Handbuch, Changelog, Aufräumen | b85e567, 1af532a, ed2b9d7 | 1 Runde (veraltete Menünamen im Handbuch, „Mandanten“ auf der Seite 402, `ext-intl`) |
| Gesamtprüfung | 48d9130 … 441a319 | „With fixes“ → acht Korrekturpakete, Nachprüfung: „Ready to merge: Yes“ |
| Aufräumen | 164d5fb | Startkonfiguration des Worktrees entfernt |

## Gesamtprüfung: Korrekturen

| Befund | Korrektur |
|---|---|
| Alte Sondertage mit Mittag und Abend (zwei Zeilen eines Datums) ließen sich nicht bearbeiten: jede Zeile meldete die andere als Konflikt | Zeilen ohne Gruppe eines Standorts und Datums sind ein Eintrag; Bearbeiten ersetzt alle, Löschen entfernt alle |
| Im Modus „Nach Personen“ bestätigte die Seite Raum- und Tischsperren, Buchungen blieben möglich | Karte und Raumauswahl ausgeblendet, „Sperren…“ in Räume & Tische und im Tischplan nur bei Tischvergabe, Server antwortet 422 mit Grund und Weg |
| `step="300"` fest in den Uhrzeitfeldern | Einstellung `SWAYY_OPENING_HOURS_TIME_STEP_MINUTES` (Vorgabe 5, 1–60); gespeicherte Zeiten außerhalb des Rasters bekommen Minutenschritte |
| Gleiche Öffnungs- und Schließzeit abgelehnt (die alte Seite nahm sie an, die Buchung liest sie als 24 Stunden) | gilt wieder als 24 Stunden, Hinweis „Rund um die Uhr geöffnet“, Überschneidung auf der Wochenachse geprüft |
| Live-Region „So wirkt es“ als `<p>` mit `aria-labelledby` | `role="status"` |
| Zwei Arten der Standortsperre | `Location::lockRow()` für Öffnungszeiten und Ausnahmen |
| Satz einer reinen Sperre endete mit „letzte Uhrzeit“; Löschfrage sagte im Salon „Buchungen“ | Satz ohne Uhrzeit, „Termine“ im Salon |
| Changelog ohne Hinweise für Betreiber | Block „Hinweise zum Update“ |

## Ergebnis

| Prüfung | 2c | 3 |
|---|---|---|
| PHPUnit (SQLite) | 1949 bestanden, 7 übersprungen | 2276 bestanden, 9 übersprungen (nur PostgreSQL), 11250 Zusicherungen |
| PHPUnit (PostgreSQL 17) | 1954 bestanden | 2285 bestanden, 11299 Zusicherungen, Exit-Code 0 (Stand 441a319) |
| Vitest | 112 (13 Dateien) | 191 (17 Dateien) |
| PHPStan | 0 Fehler | 0 Fehler |
| Pint | bestanden | bestanden |
| Build | ohne Fehler | ohne Fehler |

## Browserprüfung

Lokal (127.0.0.1:8010), Demo-Daten, Anmeldung als Inhaber aus dem Seeder. Lokale Datenbank vorher gesichert und migriert (Kundenpanel, `exception_group`). Testdaten über die Oberfläche bzw. als alte Zeilen angelegt und über die Oberfläche wieder entfernt.

| Prüfpunkt | Befund |
|---|---|
| Einstieg und alte Lesezeichen | `/admin/settings` → Allgemein (Inhaber); `#zeiten` → Öffnungszeiten, `#zahlungen` → Zahlungsanbieter, `#widget` → Buchungsseite & Widget `#popup` |
| Seitliches Scrollen | 390, 768 und 1280 px: Öffnungszeiten, Ausnahmen, Räume & Tische, Allgemein, Buchungsseite & Widget, Tischplan ohne seitliches Scrollen |
| Inline-Handler | keiner auf den geprüften Seiten |
| Ausnahmen anlegen (390 px) | Seitenleiste über die ganze Höhe, „Abbrechen“ und „Ausnahme anlegen“ unten sichtbar (40 px hoch); „Geschlossen“ am 27.12.2026 → Satz „Am 27.12.2026 kann niemand online buchen.“; Eintrag in der Liste |
| Alte Sondertage (Mittag + Abend) | zwei Zeilen ohne Gruppe → ein Eintrag „Geöffnet 11:30–14:00 und 17:00–22:00“; Vorschau ohne Konfliktmeldung; Ende 21:00 gespeichert → eine Gruppe mit beiden Fenstern |
| Modus „Nach Personen“ | Seitenleiste mit drei Karten und dem Hinweis zur Grenze für den ganzen Betrieb; Räume & Tische ohne „Sperren…“; zurück auf „Nach Tischen“: 20 Links und `data-lock-url` im Tischplan |
| Tastatur | Tab führt in die Seitenleiste, Escape schließt sie, Adresse ohne Sprungziel, Fokus auf der Überschrift |
| Löschen | Rückfrage mit Fokus auf „Ausnahme löschen“; beide Testeinträge entfernt; Protokoll mit `exception.created`, `exception.updated`, `exception.deleted` |
| Einrichtungsassistent | Demo-Betrieb hat die Einrichtung abgeschlossen (Weiterleitung auf die Übersicht); Schritt 3 deckt `OnboardingRulesTest` ab |

## Entscheidungen

| Entscheidung | Grund | Kosten bei Fehlentscheid |
|---|---|---|
| Überschneidung der Öffnungszeiten auf der Wochenachse mit Übergang So → Mo; für einen einzelnen Tag (Ausnahmen) lineare Achse | Nachtfenster gehören zum Tag, an dem sie beginnen | eine Prüfregel |
| „Geschlossen“ und „Andere Öffnungszeiten“ schließen sich je Datum aus, Grenzen und Sperren stapeln sich | ein Datum hat genau eine Öffnungszeit | Betrieb ergänzt die bestehende Ausnahme des Datums |
| Nur Felder gewählter Wirkungen werden geprüft | die Seite sendet verborgene Abschnitte mit | keine |
| Standortsperre unter PostgreSQL `for no key update` | Buchungen warten sonst bis zum Ende des Speicherns | keine |
| Assistent sendet `hours_sent=1` | gleicher Vertrag wie die Seite; eine geleerte Woche wird gespeichert | eine Zeile im Assistenten |
| Commit 1 von Aufgabe 4 allein mit roten alten Tests | Plan schreibt die Teilung vor; beide Commits gehen gemeinsam auf main | `git bisect` trifft in diesem Commit rote Tests |
| Absender: fehlendes `mail_relay_enabled` behält den gespeicherten Zustand | Regel für alle Schalter seit 2c | keine |
| Höchstzahl an Zeilen vor der Überschneidungsprüfung | eine große Anfrage band den Worker | eine Prüfregel |
| Salon-Protokoll zeigt Tisch, Raum, Tischplan, Tischsperre, Zone nur bei vorhandenen Einträgen | nach einem Wechsel der Betriebsart bleiben alte Einträge auffindbar | ein Bereich mehr oder weniger im Filter |
| Gleiche Öffnungs- und Schließzeit = 24 Stunden | Buchung und alte Seite lesen es so | ein Hinweis mehr auf der Seite |

## Offen nach dem Ausrollen

| Punkt | Wohin |
|---|---|
| Seite „Ausnahmen“ rendert je Eintrag eine Seitenleiste; mit 40 Tischen und 50 Einträgen etwa 2 MB HTML | Nacharbeit: eine gemeinsame Seitenleiste, Daten je Eintrag |
| Ausnahmen schreiben je Tag, Fenster, Raum und Tisch eine Zeile einzeln; eine Abfrage je Zeitspanne | Nacharbeit: Sammel-Insert, eine Abfrage |
| Anzahlungen: ein fehlender Schalter zählt als „aus“ (vorbestehend) | beim nächsten Anfassen an die Regel aller Schalter angleichen |
| Satz eines 24-Stunden-Fensters nennt den Folgetag nicht („zwischen 10:00 und 10:00“); Satz bei Mittag und Abend fasst zu „zwischen 11:30 und 22:00“ zusammen | Wortlaut im Satzbau |
| Suche in den Einstellungen bietet „Tisch sperren“ auch im Modus „Nach Personen“ | Suchbegriffe je Modus |
| „Geschlossen“ lässt das Nachtfenster des Vortags stehen | bestehende Regel (Fenster gehört zum Starttag); im Handbuch erwähnen |
| Kleinere Punkte der Gesamtprüfung („can wait“): Speicherleiste zählt nach Öffnen und Schließen eines Tages einen Punkt zu viel, Wochentagsnamen dreifach, Index `(location_id, ends_at)` auf `table_blocks`, Matrixtest nur für Seiten, Tag-Skript im Reservierungsdetail eingebettet | Aufräumen nach dem Ausrollen |
| Fokus beim Öffnen einer Seitenleiste über die Adresse beim Laden in einem echten Fenster | Abnahme auf dev-swayy |
