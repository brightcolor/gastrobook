# Buchbare Saisons (wiederkehrend, mehrere Fenster)

Stand: 2026-10-07 · Status: freigegeben (Entwurf in Abstimmung mit Mathias)

## Ziel

Saisonbetriebe definieren den **buchbaren Zeitraum positiv** („wir nehmen vom
1. April bis 31. Oktober Reservierungen entgegen") statt die geschlossene Zeit
mit vielen Sperrzeiten auszuschließen. Mehrere wiederkehrende Fenster je Standort
sind möglich (z. B. Sommersaison + Weihnachtsmarkt).

Anlass: Bei Sternenwald blockierten große Vollsperren (Oktober–November, fast
ganz 2027) die Online-Buchung — ein Saisonfenster ist der richtige Weg.

## Nicht im Umfang

- Saison je Raum (Saisons gelten standortweit).
- Uhrzeiten innerhalb eines Tages (das regeln die Öffnungszeiten).
- Datenkorrektur der bestehenden Sternenwald-Sperren B6/B7 (separat).

## Datenmodell

Neue Tabelle `season_periods` (ein Eintrag = ein wiederkehrendes Fenster):

| Spalte | Typ | Bedeutung |
|---|---|---|
| id | pk | |
| tenant_id | fk | Mandant (BelongsToTenant) |
| location_id | fk | Standort |
| label | string(60) nullable | z. B. „Sommersaison" |
| start_month | tinyint 1–12 | Startmonat |
| start_day | tinyint 1–31 | Starttag |
| end_month | tinyint 1–12 | Endmonat |
| end_day | tinyint 1–31 | Endtag |
| sort_order | int default 0 | Reihenfolge in der Liste |
| timestamps | | |

Model `SeasonPeriod` (BelongsToTenant, `location()`), Relation
`Location::seasonPeriods()` (HasMany). Wiederkehrend jedes Jahr ist implizit —
es wird kein Jahr gespeichert.

## Jahreslogik (Kern)

Vergleich über `md = Monat * 100 + Tag` (z. B. 1. Apr = 401, 31. Okt = 1031).
Für ein Fenster `[s, e]` und ein Datum mit `md`:

- normal (`s <= e`, z. B. 401..1031): in Saison, wenn `md >= s && md <= e`.
- über den Jahreswechsel (`s > e`, z. B. 1201..315): in Saison, wenn
  `md >= s || md <= e`.

Der 29. Februar (`md = 229`) fällt natürlich in jedes Fenster, dessen Grenzen
ihn einschließen. Kein Sonderfall nötig.

Ein Standort ist an einem Tag buchbar, wenn er **in mindestens ein** Fenster
fällt. **Ohne Fenster gilt keine Einschränkung** (abwärtskompatibel — kein
Bestandsbetrieb ändert sich).

Kapselung in `App\Services\SeasonService`:

- `isBookable(Location $location, CarbonImmutable $localDate): bool`
  — true, wenn der Standort keine Fenster hat oder `$localDate` in eines fällt.
- `nextOpening(Location $location, CarbonImmutable $fromLocal): ?CarbonImmutable`
  — der nächste lokale Tag ab `$fromLocal`, der wieder in Saison liegt (für den
  öffentlichen Hinweis); null, wenn keine Fenster.

Die Logik wird direkt und isoliert getestet (normal, Jahreswechsel, Grenzen,
29. Feb, keine Fenster).

## Verfügbarkeit

`ReservationAvailabilityService` prüft die Saison an **beiden** vorhandenen
Stellen, direkt neben der bestehenden Blackout-Prüfung und mit dem lokalen
Startdatum:

- Slot-Liste (`…` Reason-Funktion): außerhalb der Saison → Grund
  `outside_season`.
- Buchung (`bookingBlockReason`): außerhalb der Saison → `[false,
  'outside_season', []]`.

Verhalten wie bei Sperrzeiten: Sperren gelten weiter als Ausnahmen innerhalb der
Saison; Personal kann eine Buchung außerhalb der Saison von Hand übersteuern
(`skip_availability_check`/Überbuchen), online geht sie nicht.

## Admin-Oberfläche

Neuer Abschnitt „Buchbare Saison" in den Einstellungen (neben Sperrzeiten),
alltagsnah und ohne Fachbegriffe:

- Kurzer Einleitungssatz: „Lege fest, in welchen Zeiträumen Gäste online buchen
  können. Außerhalb nimmt die Seite keine Reservierungen an."
- Formular: Bezeichnung (optional), „Von" (Tag + Monat), „Bis" (Tag + Monat),
  Knopf „Saison hinzufügen". Datumswahl als Tag/Monat (kein Jahr).
- Liste der Fenster mit „Löschen"; Hinweis, wenn noch keine Saison gesetzt ist
  („Ohne Saison ist das ganze Jahr buchbar.").

Routen `settings.seasons.store` (POST) und `settings.seasons.delete` (DELETE),
Controller `SettingsController::storeSeason` / `deleteSeason`, Recht
**`blackouts.manage`** (mitgenutzt, gleicher Bereich). Validierung: Monat 1–12,
Tag gültig zum Monat (per `checkdate` mit Schaltjahr, damit 29. Feb erlaubt ist),
Bezeichnung ≤ 60 Zeichen. Verständliche Fehlermeldungen mit nächstem Schritt.

## Öffentliche Buchung

Außerhalb der Saison zeigt die Buchungsseite statt „keine Termine" einen warmen
Hinweis mit dem nächsten Saisonstart, z. B.:

> Schön, dass du da bist! Reservierungen nehmen wir wieder ab dem **1. April**
> entgegen. Bis dahin freuen wir uns, wenn du später noch einmal vorbeischaust.

Ton nach `guest_address` (Sie/du). Umsetzung: `PublicBookingController@slots`
liefert bei leerer, saisonbedingter Antwort ein Feld `season_notice` (Text +
nächstes Datum); `booking.blade.php` zeigt es im Slot-Bereich an. Der
Datumswähler bleibt wie bisher (Vorlauf/Horizont); außerhalb der Saison erscheint
der Hinweis.

## Tests

- `SeasonServiceTest` (Unit): normal, Jahreswechsel, Grenztage, 29. Feb, keine
  Fenster, `nextOpening`.
- Slot-Endpunkt: in Saison → Slots; außerhalb → 0 Slots + `season_notice`; ohne
  Fenster → unverändert.
- Buchung: außerhalb der Saison abgelehnt; mit Fenster drin akzeptiert; Personal
  per Übersteuern trotzdem möglich.
- Admin: Saison anlegen/löschen, Validierung (ungültiger Tag/Monat), Render des
  Abschnitts.

## Abwärtskompatibilität

Ohne Saisonfenster ändert sich nichts. Das Feature ist additiv; keine bestehende
Spalte oder Route wird verändert.
