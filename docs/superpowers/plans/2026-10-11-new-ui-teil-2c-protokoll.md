# Neue Oberfläche, Teil 2c: Prüfprotokoll

Plan: `docs/superpowers/plans/2026-10-11-new-ui-teil-2c.md`
Spec: `docs/superpowers/specs/2026-10-07-new-ui-admin-design.md`
Zweig `new-ui`, Stand 7939e4d (Plan b2b73f5, Abgleich mit main 1.134.8 in aabd562). Nicht gepusht, nicht ausgerollt.

## Ablauf

Freigabe Mathias (11.10.2026, „schlank ja mit sonnet“): je Aufgabe ein Umsetzer und ein Prüfer, ein Gesamtprüfer, alle auf Sonnet 5.5. Korrekturen gingen per Nachricht an denselben Umsetzer bzw. Prüfer; die Korrekturwelle der Gesamtprüfung übernahm der Umsetzer von Aufgabe 5.

| Aufgabe | Commits | Prüfung |
|---|---|---|
| 0 Abgleich mit main 1.134.8 | aabd562 | in 2b erledigt |
| 1 Benachrichtigungen | 11d39d4 | freigegeben; Aufträge zur Erinnerung und zum Feedback lesen die neuen Grenzen |
| 2 Vorlagen, Absender & Antworten, SMS-Versand | d19f2b0, 7cd7952 | 1 Runde (SMS bleibt auf der alten Seite aus, Länge der Absenderkennung je Art) |
| 3 Zahlungsanbieter, Anzahlungen | 42d6bb8 | freigegeben; Höchstbetrag einer Anzahlung 212.622 €, weil die Gesamtsumme bei 100 Personen in eine integer-Spalte passen muss |
| 4 Newsletter, API-Schlüssel, Webhooks | e8d91f9, c3b742d | 1 Runde (Einmalanzeige per Flash, Salon-Wortlaut, SMS-Kästchen bei Fehlerstatus) |
| 5 Fehlerseiten, Passwort ändern | c3c781c, 3376ed6 | 1 Runde (Passwortwechsel beendet andere Sitzungen, `AuthenticateSession`) |
| 6 Protokoll | c969f33, 00007bd | 1 Runde (gemeinsame Bezeichnungen in `App\Support`, Modelle importieren nichts aus `App\Http`) |
| Gesamtprüfung | d4be775 … 1fd0c9d | „With fixes“ → acht Korrekturpakete, Nachprüfung: alle Befunde behoben |
| Nachprüfung, eigener Nachtrag | 7939e4d | Zugangsdaten bleiben immer aus der Sitzung, `SWAYY_DONT_FLASH` ergänzt nur |
| Browserprüfung | ecdd43d | Einstellungsabschnitte bleiben auf dem Smartphone in Bildschirmbreite |

## Gesamtprüfung: Korrekturen

| Befund | Korrektur |
|---|---|
| 403-Seite sprach Gäste mit „deine Rolle“ an (Abmeldelink, Zahlung) und führte bei gesperrtem Betrieb im Kreis | Wortlaut nach Lage (Verwaltung, öffentlicher Link, Grund aus `abort`), Wege „Zur Startseite“ und „Abmelden“; „Zur Übersicht“ nur, wenn die Übersicht erreichbar ist |
| Eingetippte Schlüssel landeten nach einer abgelehnten Eingabe ohne JavaScript im `_old_input` der Sitzung | `dontFlash` mit den Feldnamen der Zugangsdaten; Einstellung ergänzt weitere |
| Fehlendes `enabled` schaltete SMS, Stripe und PayPal wieder ein; Stripe und PayPal auf der alten Seite beim ersten Speichern aus | eine Regel `IntegrationStatus::afterSave` für alle vier; die alte Seite hakt an, solange die Verbindung nicht ausgeschaltet ist |
| Webhook-Zeiten in UTC ohne Angabe | Ortszeit des Standorts mit „Uhr“ |
| Beispiel der Antwortadresse fest im Code | aus den Einstellungen des Relays |
| Supportadresse fest, 402 auf Englisch | `swayy.support.email`, eigene Seite 402 |
| Salons lasen auf fünf Seiten „Gäste“ | „Kunde/Kunden“ auf allen Einstellungsseiten |
| Passwortregel je Einstieg verschieden (8 und fest 10) | `PasswordPolicy::rule()` an allen sechs Einstiegen, Vorgabe 10 Zeichen mit Buchstaben und Ziffern |

## Ergebnis

| Prüfung | 2b | 2c |
|---|---|---|
| PHPUnit (SQLite) | 1509 bestanden, 7 übersprungen | 1949 bestanden, 7 übersprungen (nur PostgreSQL), 9080 Zusicherungen |
| PHPUnit (PostgreSQL 17) | 1516 bestanden | 1954 bestanden, 9108 Zusicherungen, Exit-Code 0 (Stand 1fd0c9d plus Browserkorrektur; 7939e4d berührt keine Datenbank) |
| Vitest | 111 | 112 (13 Dateien) |
| PHPStan | 0 Fehler | 0 Fehler |
| Pint | bestanden | bestanden |
| Build | ohne Fehler | ohne Fehler |

## Browserprüfung

Lokal (127.0.0.1:8010), Demo-Daten mit zwei Standorten, Anmeldung als Inhaber aus dem Seeder. Testdaten (eine Anzahlungsregel, ein API-Schlüssel) über die Oberfläche angelegt und wieder entfernt.

| Seite | Befund |
|---|---|
| Seitliches Scrollen | 375 px: API-Schlüssel scrollte seitlich (Codebeispiel weitete die Spalte), behoben in ecdd43d; alle übrigen Seiten aus 2c ohne seitliches Scrollen; 768 und 1280 px ohne Befund |
| Benachrichtigungen | Erinnerung 999 Stunden: Browser meldet die Obergrenze; ohne `max` am Feld antwortet der Server „Die Erinnerung kommt höchstens 168 Stunden vorher. Bitte höchstens 168 eintragen.“ am Feld mit `aria-invalid`; „Verwerfen“ stellt 24 wieder her; „Text der Erinnerung bearbeiten“ öffnet die Seitenleiste der Vorlage über `#vorlage-reservation_reminder` |
| Anzahlungen | Regel „ab 6 Personen, Fr und Sa, 12.50“ angelegt → Liste „12,50 € p. P. · ab 6 Personen · Fr, Sa“; Rückfrage nennt die Folgen, Fokus auf „Regel löschen“; Löschen entfernt die Regel |
| API-Schlüssel | Anlegen mit „Reservierungen lesen“ → Schlüssel einmal mit Kopierknopf; nach dem nächsten Laden nicht mehr sichtbar; Widerrufen per Rückfrage |
| Mein Konto | falsches aktuelles Passwort → „Das aktuelle Passwort stimmt nicht. Bitte erneut eingeben oder „Passwort vergessen“ nutzen.“; Hilfe „Mindestens 10 Zeichen, mit Buchstaben und Ziffern.“ |
| Protokoll | Suche „Browserprüfung“ findet Anlegen und Löschen von Regel und Schlüssel mit lesbaren Feldern und Werten |
| Fehlerseite 404 | „Diese Seite gibt es nicht“, Weg zur Startseite, Fehlercode |
| Alle Seiten aus 2c | Status 200, kein Inline-Handler, keine Ausnahme im HTML |

## Entscheidungen

| Entscheidung | Grund | Kosten bei Fehlentscheid |
|---|---|---|
| Ohne API-Tarif zeigt die Seite den Tarif-Hinweis und darunter den Leerzustand | Test der Seite verlangt den Leerzustand | eine Textzeile |
| Fehlendes `enabled` behält bei bestehenden Verbindungen den Zustand (alle vier Anbindungen); die alte Seite sendet das versteckte `0` | „Ausgeschaltet bleibt aus“, auch bei veralteten Tabs | keine |
| Einmalanzeige neuer Schlüssel und Geheimnisse per Flash | kein Klartext ohne Zeitgrenze in der Sitzung; keine Zwischenanfrage vor dem Neuladen | Schlüssel geht bei einer Zwischenanfrage verloren und muss neu angelegt werden |
| Passwortregel Vorgabe 10 Zeichen mit Buchstaben und Ziffern für alle Einstiege | kein Einstieg wird schwächer als die bisherige Registrierung | bestehende Passwörter bleiben gültig; nur neue müssen die Regel erfüllen |
| `AuthenticateSession` in der Web-Gruppe | Passwortwechsel beendet andere Sitzungen | bestehende Sitzungen lernen den Hash beim nächsten Aufruf, niemand wird beim Ausrollen abgemeldet |

## Offen für Teil 3

| Punkt | Wohin |
|---|---|
| Protokoll für Salons: Bereiche und Aktionen mit „Reservierung“, „Tisch“, „Raum“ | Teil 3, Aufgabe 5 |
| Vorlageneditor zeigt den Standardtext in der Sie-Fassung, auch bei „du“ | Teil 3, Aufgabe 5 |
| Lizenzseite im alten Stil („Sie“, eigenes Layout) | Teil 3, Aufgabe 5 |
| Feste Supportadresse in `PanelLinkController`, `admin/trial/confirm*`, `provisioning/locked`; Meldung „Admin already exists“ im Startskript bei jedem Fehler von `swayy:create-admin`; Seite 402 ohne Rückweg nach `/saas` | Teil 3, Aufgabe 5 |
| README: Tabellen der Umgebungsvariablen um alle neuen Einstellungen | Teil 3, Aufgabe 5 |
| Handbuch der Seiten aus 2c (Schnittstellen, Nachrichten, Geld) | Teil 3, Aufgabe 5 |
| Gruppe `permission:tables.manage` um die Einstellungsrouten auflösen; Sammelseite samt Inline-Handlern abbauen | Teil 3, Aufgabe 4 |
| Kleinere Punkte der Aufgabenprüfungen („minor (deferred)“ im Ledger, von der Gesamtprüfung als „kann warten“ eingestuft), etwa: Suche im Protokoll trifft gespeicherte Kürzel statt angezeigter Wörter; Beträge im Protokoll mit Punkt; Status nach fehlgeschlagenem Verbindungstest erst nach Neuladen aktuell | Aufräumen nach dem Ausrollen |
| Fokus beim Öffnen einer Seitenleiste über die Adresse beim Laden in einem echten Fenster nachprüfen (im verborgenen Browserbereich bleibt der Fokus auf `body`) | Abnahme auf dev-swayy |
