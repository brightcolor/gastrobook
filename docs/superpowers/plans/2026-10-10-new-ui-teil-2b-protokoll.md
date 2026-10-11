# Neue Oberfläche, Teil 2b: Prüfprotokoll

Plan: `docs/superpowers/plans/2026-10-10-new-ui-teil-2b-platz-buchungen.md`
Spec: `docs/superpowers/specs/2026-10-07-new-ui-admin-design.md`
Zweig `new-ui`, Stand d424445 (Plan 03b1f7c, Abgleich mit main 1.134.6 in 4da7fde). Nicht gepusht, nicht ausgerollt.

## Ablauf

Freigabe Mathias (10.10.2026, „abgleich ja agenten ja“): je Aufgabe ein Umsetzer und ein Prüfer (Sonnet 5.5), ein Gesamtprüfer (Opus 5.5). Korrekturen gingen per Nachricht an denselben Umsetzer bzw. Prüfer, auch die Korrekturen nach der Gesamtprüfung. Aufgabe 0 und 14 führte die steuernde Sitzung selbst aus, ebenso drei kleine Restpunkte der Gesamtprüfung (d424445).

| Aufgabe | Commits | Prüfung |
|---|---|---|
| 0 Abgleich mit main 1.134.6 | 4da7fde, 2d769a6 | SQLite und PostgreSQL je 1195 grün |
| 1 Formulare, Seitenleiste, Rückfrage | bccb205, c0743fe | 1 Runde (Folgeanfrage nach Zurücksetzen, Abstand der Fehlerbox) |
| 2 Speicherleiste, Zeilen, Hinweise, Bausteine | d6e182a, 11055cf, 62eb85f | 2 Runden (Verwerfen mit Zeilen während der Anfrage, alte Fehlermarkierungen, Kästchen 24 px) |
| 3 Grenzen und Prüfregeln | 8fb1c98, 12b16da | 1 Runde (Grenzen auf smallint 32767, Paare min ≤ max) |
| 4 Kapazität & Dauer | 35cbf45, 0b1d45e | freigegeben, kleine Punkte nachgezogen |
| 5 Buchungsregeln | 6d0be4a, 73be299 | 1 Runde (Salon-Satz zur Warteliste) |
| 6 Buchungsformular | 9a9ab09, 23b832c | 1 Runde (Meldungen mit nächstem Schritt, nur wirksame Auswahl) |
| 7 Buchungsseite & Widget | d220935, 306103e, 4b281b7 | freigegeben; PostgreSQL-Testfehler (29 Zeichen in varchar(9)) behoben |
| 8 Tags | c57d835, c687ff1 | freigegeben, kleine Punkte nachgezogen |
| 9 Räume & Tische | 5a07a31, 820171f, a465ed9, b6fcbcc | freigegeben; Raumgröße auf 327 m (smallint), Tarifgrenze unter Sperre |
| 10 Leistungen | 0b71c6a, 82e9d74 | 1 Runde (eine Stelle schreibt die Leistungszuordnung, Tests über beide Buchungswege) |
| 11 Mitarbeiter | c714716, 85839a0, e83fbc4 | 1 Runde (Seitenleiste mit mehreren Formularen, Hinweis bei Abwesenheit) |
| 12 Reservierungsliste, Anhänge | e8d43ef, 0e9ab75 | freigegeben, kleine Punkte nachgezogen |
| 13 Restpunkte aus 2a | 8c848ca, b41e6c1 | 1 Runde (Rollenbeschreibung passend zur Rollenvergabe aus 1.134.6) |
| Sicherheitsfix aus main | 9be3fff | Cherry-Pick von dafd80b (Namen maskiert ins HTML) |
| Sicherheitsfix aus main | 6f81db5 | Cherry-Pick von 9d6145f (Administration ändert oder entfernt keinen weiteren Inhaber); Teamseite und zwei Tests an die neue Regel angepasst |
| Gesamtprüfung | e6b2a16 … d424445 | „With fixes“ → sechs Korrekturpakete, Nachprüfung: alle Funde behoben |

## Ergebnis Step 1 und 2

| Prüfung | 2a | 2b |
|---|---|---|
| PHPUnit (SQLite) | 1107 | 1509 bestanden, 7 übersprungen (nur PostgreSQL), 6454 Zusicherungen |
| PHPUnit (PostgreSQL 17) | – | 1516 bestanden, 6493 Zusicherungen, Exit-Code 0 |
| Vitest | 31 (7 Dateien) | 111 (13 Dateien) |
| PHPStan | 0 Fehler | 0 Fehler |
| Pint | bestanden | bestanden |
| Build | ohne Fehler | ohne Fehler |

## Browserprüfung

Lokal (127.0.0.1:8010), Demo-Daten mit zwei Standorten, Anmeldung als Inhaber aus dem Seeder. Testdaten (zwei Tags, ein Tisch, eine Leistung, eine Person, eine Abwesenheit, Raummaße) über die Oberfläche angelegt und wieder entfernt; der Betriebstyp stand für die Salon-Seiten kurz auf Salon und danach wieder auf Restaurant. Der gelöschte Testtisch bleibt als weich gelöschte Zeile in der lokalen Datenbank.

| Seite | Befund |
|---|---|
| Seitliches Scrollen | 390 px: Kapazität, Buchungsregeln, Buchungsformular, Buchungsseite, Tags, Räume & Tische, Reservierungen, Team, Standorte, Abo ohne seitliches Scrollen; 768 und 1280 px stichprobenartig ebenso |
| Kapazität & Dauer | Tischzeit hinzufügen → „1 Einstellung geändert“, Punkt an der Zeile, Fokus im ersten Feld (Schlüssel `n1`); zwei Zeilen „1–4“ und „3–6“ → Text am Feld „Ab Personen“ der zweiten Zeile, Fokus dort, Zusammenfassung oben; „Verwerfen“ stellt Zeilen, Leiste und Fehler zurück; „Nach Personen“ blendet „Höchstens gleichzeitig“ ein, „Verwerfen“ wieder aus |
| Buchungsregeln | Stornofrist 120 → „entspricht 2 Std.“; kleinste Gruppe 5, größte 3 → „… mindestens 5 eintragen.“ am Feld, Fokus dort |
| Buchungsformular | E-Mail und Telefon „Ausgeblendet“ → Hinweis erscheint, Zähler „2 Felder geändert“; Anlass bietet nur „Ausgeblendet“ und „Freiwillig“ |
| Buchungsseite & Widget | Knopftext mit `"`, `<b>` und `&` → maskiert im Code, „Code kopieren“ trägt denselben Text, der Code ergibt genau ein Element mit unverändertem Text |
| Tags | Anlegen schließt die Leiste, Liste lädt neu, „Neu“ ist danach leer; Umbenennen auf vorhandenen Namen → Grund am Feld, `aria-invalid`, Fokus im Feld; Hintergrund mit Eingaben → Hinweis, Leiste bleibt; Löschen über die Rückfrage in der Zeile, Fokus auf „Tag löschen“ |
| Räume & Tische | Raum mit Tischen ohne Löschknopf; neuer Tisch steht im Tischplan oben links (50/50); „Maße speichern“ hält die Leiste offen und meldet den Erfolg; Tisch löschen per Tastatur über die Rückfrage |
| Leistungen (Salon) | Preis 49.90 → 4990 Cent, Liste „30 Min. · 49,90 €“; Löschfrage nennt die Folgen |
| Mitarbeiter (Salon) | Zeitfenster 18:00–09:00 → „Das Zeitfenster endet vor seinem Beginn. …“ an der Zeile, Fokus im Endfeld; Abwesenheit gespeichert, Seitenleiste öffnet nach dem Neuladen über `#person-1` wieder, Abwesenheit entfernt; Löschfrage nennt den Vermerk „entfernt“ im Terminplan |
| Reservierungen | 390 px: Zeilen zweizeilig mit Kurzzeile „Mi., 07.10. 12:00–14:00 · 2 Pers. · I1 · Online“, „Alle auswählen“ über der Liste; Standort ohne Reservierungen → „Noch keine Reservierungen.“ mit „+ Neu“ |
| Suche | „Stornofrist“ → Buchungsregeln#fristen, „Tischkombination“ → Räume & Tische#kombinationen, „Neuer Tag“ → Tags#tag-neu, „Tischzeit“ → Kapazität & Dauer#tischzeiten |
| Alte Sammelseite | Buchungsregeln speichern („Buchungsregeln gespeichert.“), Tisch „Kombinierbar“ aus und wieder ein, beides wirkt |
| Konsole | keine Skriptfehler; nur die 422 der absichtlichen Fehlerproben |

Nicht abschließend prüfbar: Ob der Fokus beim Öffnen einer Seitenleiste über die Adresse beim Laden im ersten Feld sitzt. Das Testfenster hatte nach dem Laden keinen Systemfokus (`document.hasFocus()` falsch), der Code ruft `focus()` auf, und der jsdom-Test ist grün. Beim Öffnen per Klick sitzt der Fokus im ersten Feld.

## Gesamtprüfung und Korrekturen

| Fund | Behoben in |
|---|---|
| Tisch ließ sich löschen, während Gäste daran sitzen; Prüfung ohne Sperre | e6b2a16 (`end_at`, Zeilensperre, „kommende oder laufende“) |
| Tarifgrenze sperrte die Betriebszeile `FOR UPDATE` und hielt alle Schreibzugriffe des Betriebs auf | e6b2a16 (`FOR NO KEY UPDATE`) |
| Raum während des Anlegens eines Tisches gelöscht → Serverfehler | e6b2a16 (Raum unter Sperre neu lesen, 422 mit Grund) |
| Kombination verlor still Tische, die nicht mehr kombinierbar sind | e6b2a16 |
| Hinweis auf gebuchte Termine bei Abwesenheit verschwand mit dem Neuladen | 4a73389 (dauerhaft in der Liste, eine Abfrage) |
| Terminplan zeigte Termine pausierter oder entfernter Personen nicht | 088dbbb |
| Abwesenheit ohne Sperre der Person | 4a73389 |
| Leerzustände der Reservierungsliste, Allergietext am Smartphone, „+ Neu“ für Lesezugriff | 6cf0cde |
| Verborgenes ungültiges Feld: „Speichern“ ohne sichtbare Wirkung; Rückfrage nach Erfolg weiter klickbar; alte Fehler nach Wiederöffnen der Leiste | 714c3d7 |
| Laravel-Standardmeldungen auf Kapazität und Buchungsregeln; Gruppengrößen über der Grenze der Buchungswege (100) | 391d3e8, d424445 |
| Suchbegriffe für Restaurantabschnitte führten Salons ins Leere | 8afb57d (`keywords_restaurant`) |
| Meldung für ein leeres Pflichtfeld schickte auf der alten Seite zum Neuladen; Kombinationsmitglieder ohne vorab geladenen Raum | d424445 |

Nachprüfung: kein neuer Sperrkreis (Buchung: Tag → Person → Leistungen; Löschen: nur die eigene Zeile; Tarifgrenze: Betrieb → Raum), alle Funde behoben.

## Entscheidungen

| Entscheidung | Grund |
|---|---|
| Grenzen auf Spalten mit `unsignedSmallInteger` enden bei 32767, Raumgröße bei 327 m | PostgreSQL kennt kein unsigned; der Plan sah 100000 bzw. 2000 m vor |
| Salons sehen auf „Buchungsregeln“ keine Gruppengrößen und keine Walk-ins, auf „Buchungsformular“ nur die Anmerkung, auf „Buchungsseite“ keinen Tischplan-Schalter | im Code geprüft: Salonbuchung kennt keine Personenwahl, Walk-ins und Tischplan brauchen Tische |
| „Pflichtfeld“ nur für E-Mail und Telefon; Anlass, Allergien und Anmerkung bieten „Ausgeblendet“ und „Freiwillig“ | die Buchungsseite erzwingt nur E-Mail und Telefon |
| Buchungsweg liest Person und Leistungen `FOR SHARE`; Löschen sperrt die Zeile `FOR UPDATE` und zählt `end_at >= now()` | sonst entstehen Termine an gelöschten Leistungen oder Personen |
| Fremde IDs in Anfragen (Tische einer Kombination, Personen einer Leistung, Leistungen einer Person) → 422 mit Grund | Fehler bleiben sichtbar, nichts verschwindet still |
| Gruppengrößen der Tischzeiten und online höchstens 100 | alle Buchungswege nehmen höchstens 100 Personen an |
| Seitenleiste mit mehreren Formularen schließt nach dem Speichern nur ohne ungespeicherte Eingaben in den anderen | Eingaben gehen nie still verloren |

## Offen für Mathias

| Punkt | Wo |
|---|---|
| E-Mail-Felder ohne Längengrenze gegen `varchar(255)` (öffentliche Buchung, Events, Gäste, Warteliste, API, Kundenportal, SaaS) → Datenbankfehler bei sehr langen Adressen; Grenzen 200/255 uneinheitlich | main, eine Einstellung für alle E-Mail-Felder |
| `popup.js` setzt Knopftext und Titel per `innerHTML`; die öffentlichen Skripte nennen den iframe auch für Salons „Tisch reservieren“ | main |
| Restaurant-Buchungsweg sperrt die gewählten Tische nicht; eine Buchung kann in ein schmales Fenster auf einen gerade gelöschten Tisch fallen | Folgeaufgabe |
| Raum löschen entfernt per Kaskade weich gelöschte Tische samt Reservierungszuordnung | Entscheidung zum Fremdschlüssel |
| Nach dem Löschen einer Leistung zeigen vergangene Termine sie nicht mehr (`withTrashed()` an den Beziehungen?) | Folgeaufgabe |
| Tarifgrenzen für Standorte, Nutzer und Events: derselbe Wettlauf wie bei Tischen; `createWithinLimit` ist allgemein nutzbar. `AccountImportService` legt Tische ohne Grenzprüfung an | Folgeaufgabe |
| Warteliste für Salons (Angebote brauchen einen Tisch); „Walk-ins“ in der Hauptnavigation für Salons | Entscheidung |
| Anlass, Allergien, Anmerkung als Pflichtfeld auf der Buchungsseite erzwingen? | Entscheidung |

## Offen für 2c und Teil 3

| Punkt | Wohin |
|---|---|
| Protokoll: Bereiche für auth/support/provisioning, Rohschlüssel bei fehlendem Kürzel, Werte zu `type` als Code, Freitextsuche; Rohwerte `hidden/optional/required` und „Notiz“ bei Feldregeln | 2c |
| Grenzen für Erinnerung, Feedback und Erstattung in `config/swayy.php` | 2c (Benachrichtigungen, Anzahlungen) |
| `SettingsController::updateBookingRules`, Sammelseite samt Reitern „Räume & Tags“ und „Website-Widget“; dort feste `max="200"`, `maxlength="40"`, Inline-Handler | Teil 3 (Abbau) |
| Einrichtungsassistent: Schritt „Buchungsregeln“ sendet keine Tischzeiten und leert sie bei erneutem Durchlauf; feste `min`/`max` im Assistenten | Teil 3 oder eigene Aufgabe |
| Suchbegriffe „Tisch sperren“ und „Weniger Gäste“ (Ausnahmen) nach `keywords_restaurant` | Teil 3 (Ausnahmen) |
| Handbuch: `03-online-buchungen/01-buchungsseite-widget.md`, `03-online-buchungen/02-zeiten-und-regeln.md`, `04-salon/01-leistungen-mitarbeiter-terminplan.md`, `02-alltag/03-tischplan.md` | Teil 3 |
| „Sperren…“ am Tisch in Räume & Tische und im Tischplan | Teil 3 (Ausnahmen) |
| Kleinere Punkte der Aufgabenprüfungen (Liste im Ledger): Sortierung mit Umlauten, doppelte Hilfsfunktionen (`inOrder`, Farbprüfung), Datumsfeld des Terminplans mit `onchange`, Hinweis bei verborgenem Feld nur über die Speicherleiste | Aufräumen vor dem Zusammenführen |
| Fokus beim Öffnen einer Seitenleiste über die Adresse beim Laden in einem echten Fenster nachprüfen | Abnahme |
