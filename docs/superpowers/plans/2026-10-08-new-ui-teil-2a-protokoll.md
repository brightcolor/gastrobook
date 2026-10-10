# Neue Oberfläche, Teil 2a: Prüfprotokoll

Plan: `docs/superpowers/plans/2026-10-08-new-ui-teil-2a-bausteine-betrieb.md`
Spec: `docs/superpowers/specs/2026-10-07-new-ui-admin-design.md`
Zweig `new-ui`, Stand 59cf0c6 (Plan e1426e9). Nicht gepusht, nicht ausgerollt.

## Ablauf

Freigabe Mathias (08.10.2026): je Aufgabe ein Umsetzer und ein Prüfer (Sonnet 5.5), ein Gesamtprüfer (Opus 5.5). Korrekturen gingen per Nachricht an denselben Umsetzer bzw. Prüfer; die Korrekturwelle nach der Gesamtprüfung und Aufgabe 10 setzte die steuernde Sitzung selbst um.

| Aufgabe | Commits | Prüfung |
|---|---|---|
| 1 Vitest + jsdom | 24986ec, 68bc165 | 1 Korrekturrunde (Polyfill entfernt) |
| 2 Formulare, Speicherleiste, Suche | 32584d4 | ohne Befund |
| 3 Navigation | c8d1aad | ohne Befund |
| 4 Bausteine | 5ff068e, bdf5bbc | 1 Korrekturrunde (Escape über dem Dialog, Rückfrage für Screenreader) |
| 5 Standorte | 39c7720 | ohne Befund |
| 6 Team & Rollen | 2c240fc | ohne Befund |
| 7 Abo & Rechnungen | f4a1834 | ohne Befund |
| 8 Protokoll | 6cb9b9f | ohne Befund |
| 9 Daten, Mein Konto | 233cb11 | ohne Befund |
| Gesamtprüfung | 7287fc8 … 59cf0c6 | „With fixes“ → Korrekturwelle, Nachprüfung: alle Funde behoben |

## Ergebnis Step 1

- PHPUnit: 1107 bestanden (3605 Zusicherungen)
- Vitest: 31 bestanden (7 Dateien)
- PHPStan: 0 Fehler
- Build: ohne Fehler
- Pint: bestanden bis auf `tests/Feature/ProvisioningApiTest.php` (Datei der Kundenpanel-Sitzung, außerhalb dieses Plans)

## Browserprüfung

Lokal (127.0.0.1:8010), Demo-Daten mit zwei Standorten; zusätzliches lokales Mitglied mit der Rolle Administration.

| Seite | Befund |
|---|---|
| Standorte | Seitenleiste öffnet, Fokus im Namen, keine doppelten IDs; leerer Name → Text am Feld und Zusammenfassung in der Leiste; Escape setzt zurück und gibt den Fokus an den Öffner; 390 px bildschirmfüllend |
| Team & Rollen | Zugang-Leiste speichert die Freigabe und zeigt sie nach dem Neuladen vorausgewählt; `#einladen` öffnet die Einladung mit 8 Rollen; Rolle speichert erst per Knopf; „Entfernen“ des einzigen Inhabers → Grund in der Zeile, Escape schließt, Fokus auf „Entfernen „Olivia Owner““ |
| Suche | „export“ → Daten#export, „person einladen“ → Team & Rollen#einladen, „xyz“ → Beispiele |
| Daten | Abschnitte Export, Import, Betrieb löschen; Administration sieht den Eintrag nicht und bekommt 403 mit Erklärung |
| Protokoll | Bereichsfilter mit Hinweis; aus der Reservierung „Alle Änderungen im Protokoll“ mit Eintragsfilter und Leerzustand |
| Allgemein | Speicherleiste sperrt den Seitenkopf, Verwerfen gibt ihn frei |
| Alte Sammelseite | Reiter wechseln; Löschen weiter mit nativer Rückfrage (Bestand); nichts gelöscht |
| 390 px | Standorte, Team, Abo, Protokoll, Daten, Mein Konto ohne seitliches Scrollen |

## Gesamtprüfung und Korrekturen

| Fund | Behoben in |
|---|---|
| Rollenauswahl speicherte sofort; eine zweite Änderung während der Anfrage ging verloren | 259908d (Knopf „Speichern“) |
| Logo-Upload im Standort-Drawer schloss die Leiste und verwarf Profileingaben | 7287fc8 (Hauptformular, data-dirty, Neuladen wartet) |
| Protokoll zeigte englische Feldnamen und Rollen-Codes | f4c3cd1 |
| `?area[]=…` im Protokoll → Serverfehler; unmögliche Kalendertage unter PostgreSQL | bc3bf63, 59cf0c6 |
| Selbst-Einladung ohne Text, Tarifgrenze mit Schritt, den die Rolle nicht gehen kann | 6efeba2 |
| Logo-Vorschau kaputt bei inaktiven Standorten | a504780 |
| Dateigrößen gerundet („1 MB“ für 1500 KB) | 0882372, 59cf0c6 |
| Einstellungsmenü baute eine zweite Navigation | 900d67b |
| Fragment `#%` legte Seitenleisten lahm; Fußzeilen-Knöpfe ohne Beschäftigt-Zustand | 7287fc8 |
| Mein Konto ohne Weg zu „Daten“, Import-Meldung ohne nächsten Schritt | cacafbb |

## Entscheidungen

- Ruling: Fix-Runden per Nachricht an denselben Umsetzer, Nachprüfungen per Nachricht an denselben Prüfer — jeder Neustart eines Agenten braucht Freigabe — kostet bei Irrtum: leichte Befangenheit der Prüfer
- Ruling: Korrekturwelle und Aufgabe 10 durch die steuernde Sitzung — Freigabe deckte keine weiteren Agenten — kostet bei Irrtum: Korrekturen ohne frische Augen (Nachprüfung durch den Gesamtprüfer erfolgte)
- Ruling: `.env.example`-Reihenfolge der Einträge aus Aufgabe 8/9 frei — reine Doku — kostet bei Irrtum: nichts
- Ruling: „berührter Code ohne Inline-Handler“ gilt in `reservations/show.blade.php` nur für „Verlauf“ — Rest gehört zu 2b — kostet bei Irrtum: Inline-Handler bleiben bis 2b
- Ruling: Polyfill für `CSS.escape` entfernt — jsdom 30 bringt es mit — kostet bei Irrtum: ältere jsdom-Versionen bräuchten ihn
- Ruling: `escape()`-Testhelfer mit `cancelable: true` — echte Tastenereignisse sind abbrechbar — kostet bei Irrtum: nichts
- Ruling: Escape im Bestätigungsdialog mit `preventDefault`; `x-inline-confirm` mit Frage-ID, `role="group"`, `aria-describedby` und Prop `subject` — WCAG 2.2 AA — kostet bei Irrtum: wenige Zeilen
- Ruling: Commit-Trailer „Claude Sonnet 5.5“ in f4a1834 bleibt — korrekt zugeordnet — kostet bei Irrtum: nichts
- Ruling: Rolle per Knopf statt Sofortspeichern — kein Wettlauf, Tastatur sicher — kostet bei Irrtum: ein Klick mehr
- Ruling: Seitenleiste schließt nur nach dem Hauptformular, geänderte Formulare halten das Neuladen auf — kostet bei Irrtum: Logo-Vorschau erst nach dem Neuladen aktuell
- Ruling: Feld `type` im Protokoll heißt „Art“ — auch Marketing-Kampagnen protokollieren `type` — kostet bei Irrtum: nichts
- Ruling: Navigation je Anfrage über ein Attribut der Anfrage statt eines Container-Singletons — Tests schicken mehrere Anfragen je App — kostet bei Irrtum: nichts
- Ruling: Selbst-Einladung bleibt 403 (Sicherheitstest), jetzt mit Text — kostet bei Irrtum: nichts
- Ruling: Rest-Minor Kalendertage und Abrunden sofort behoben, ohne weitere Nachprüfung — drei Zeilen mit Test — kostet bei Irrtum: nichts
- Ruling: zweiter Klick auf „Speichern“ während laufender Anfrage → 2b — kostet bei Irrtum: seltene Abweichung bis zum Neuladen

## Offen für Plan 2b

- `forms.js`: zweiter Versand mit anderen Daten während einer laufenden Anfrage wird verworfen; Capture-Handler und „Mehr“-Blatt prüfen `defaultPrevented` nicht; `data-reset-on-error` setzt auch bei unklarer Antwort zurück
- Speicherleiste: `inert` auf dem ganzen Seitenkopf (enger fassen auf Standortwahl und Aktionen)
- Seitenleiste: offene Rückfragen darin schließen nicht mit; Drawer außerhalb des Stapels würde inert; „Neu“ zeigt ohne Neuladen die zuletzt gespeicherten Werte; Klick auf den Hintergrund verwirft ohne Hinweis
- `x-inline-confirm`: `role="group"` auf `<form>` laut ARIA in HTML nicht erlaubt (innerer Wrapper)
- Team: Fehler „mindestens ein Standort“ ohne Text am Feld; E-Mail ohne `max`-Regel; Rollenbeschreibungen Administration/Betriebsleitung ergänzen; Meldung „in der Liste ändern“ für die Betriebsleitung
- Standorte: Zeitzone ohne Pflichtmarkierung; „1000 Zeichen“ doppelt (in die Einstellungen ziehen); alte Logodatei wird vor dem Speichern der neuen gelöscht; „2 von 1 Standorten“ bei überschrittener Grenze
- Abo: „Aktiv“ doppelt angesagt; Tests für aktive Lastschrift
- Protokoll: Bereiche für auth/support/provisioning; Rohschlüssel bei fehlendem Kürzel; Werte zu `type` als Code; Freitextsuche nur per Adresse
- Daten: Fokus auf die wieder geöffnete Frage „Betrieb löschen“
- Testlücken laut Aufgabenprüfungen (Toast, Statussymbol-Formen, Knopfstufen, Navigation für Support/Lesezugang)

## Offen für Teil 3 (Zusammenführen)

- Handbuch `docs/handbuch/08-verwaltung/02-umzug-export-import.md` auf Einstellungen › Daten umstellen
- „Abrechnung“ in `SettingsController.php`, `DirectDebitController.php`, `SettingsFormErrorsTest.php` und `AuditLog::SUBJECTS['billing']` auf „Abo & Rechnungen“
- `SettingsController::uploadLogo` mit Laravel-Standardmeldungen (entfällt mit der Sammelseite)
- Node-Version festlegen (`engines` oder `.nvmrc`; jsdom 30 braucht ^22.22.2 oder ^24.15.0)
- Eintrag `gastrobook-new-ui` aus `.claude/launch.json` entfernen; Changelog-Hinweis Farbe `#0d9488` → `#0f766e`
- Zweig enthält die Merge-Commits 42f6c29 (main 1.134.2) und 1f05d2e (main 1.134.3, 09.10.2026); GitHub erlaubt für gastrobook nur Rebase-Merges. Vor dem PR den Weg festlegen (lokal auf `main` rebasen oder zusammenfassen) und Verweise auf Commits in den Protokollen nachziehen
- Versionsnummer beim Ausrollen: 1.135.0 (neue Funktionen), sofern `main` bis dahin nicht weiter ist
- Stand 10.10.2026: `main` ist bei 1.134.6 und hat sechs Commits, die `new-ui` fehlen. Zwei davon liegen hier schon als Cherry-Pick: 1dda878 = main 3f13e34 (GoCardless-Doppelprüfung, `phpunit.pgsql.xml`, CI-Job `tests-pgsql`) und c12678a = main 04df63f (`tests-pgsql` über `vendor/bin/phpunit`). Beim Rebase auf `main` fallen sie als schon angewandt weg. 154a9da (Provisioning: Datenbankfehler als 503) gibt es nur hier.
- Die Suite läuft auch gegen PostgreSQL: `vendor/bin/phpunit -c phpunit.pgsql.xml` (Anleitung im README); vor dem Zusammenführen beide Suiten laufen lassen

## Außerhalb von new-ui

- Betriebsleitung (`users.invite`, ohne `users.roles.manage`) kann eine zweite eigene Adresse als „Administration“ einladen, weil `assignableRoles` nur `tenant_owner` ausschließt. Erledigt auf `main` in 1.134.6 (142c579); kommt mit dem nächsten Abgleich von `main`. Der Commit ändert `UserManagementController.php`, den `new-ui` für Team & Rollen umgebaut hat: beim Abgleich die Prüfung `AssignableRole` in die neue Team-Seite übernehmen und `RoleAssignmentTest` laufen lassen.
- Pint-Befund in `tests/Feature/ProvisioningApiTest.php` (Kundenpanel-Sitzung). Erledigt in a863f4e.
