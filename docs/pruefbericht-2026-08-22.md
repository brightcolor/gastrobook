# Vollprüfung der Anwendung — 22.08.2026

Stand v1.109.0. 78 Befunde nach Zusammenfassung von Doppelmeldungen.
Die drei Blocker und 15 weitere Befunde wurden von einem zweiten, unabhängigen Prüfer gegengelesen — alle bestaetigt.


## Blocker

### 1. Umbuchen berechnet die Anzahlung nicht neu – Gast umgeht die Anzahlung komplett

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Http/Controllers/Public/PublicBookingController.php:759`

reschedule() erlaubt dem Gast über den öffentlichen Verwaltungslink, Datum, Uhrzeit UND Personenzahl zu ändern (Regeln Zeile 741-753). ReservationLifecycleService::reschedule() schreibt in Zeile 539-544 nur reservation_date, start_at, end_at und party_size – payment_amount_minor, payment_status, deposit_rule_id und payment_due_at bleiben unangetastet. Ein grep über app/ zeigt: payment_amount_minor wird ausschliesslich in create() (ReservationLifecycleService.php:197) geschrieben, nirgends sonst. PaymentRequirementService::requirementFor() (Zeile 30-33, query() Zeile 45-47) wählt die Anzahlungsregel aber genau nach Wochentag, Uhrzeit und min_party_size aus. Folge: Wer dienstags mittags zu zweit bucht (keine Regel greift, Status Confirmed, payment_status 'not_required') und danach per Änderungslink auf Samstag 20:00 mit 8 Personen umbucht, sitzt ohne einen Cent Anzahlung auf dem Tisch, für den der Betrieb Anzahlung verlangt. Weil payment_status 'not_required' bleibt, blendet manage() (Zeile 647-651) auch keinen Bezahlbutton ein – die Anzahlung ist endgültig verloren, nicht nur verschoben. Umgekehrt zahlt ein Gast, der von 8 auf 2 Personen umbucht, weiter den 8er-Betrag. Kein Test unter tests/ deckt das ab.

**Auslöser:** Reservierung ohne Anzahlungspflicht anlegen (z. B. Di 12:00, 2 Personen), dann GET /reservation/{code}/reschedule/{token} öffnen und POST mit date=<Samstag>, time=20:00, party_size=8. Reservierung steht bestätigt, payment_amount_minor bleibt null.

**Vorschlag:** In reschedule() nach der Verfügbarkeitsprüfung PaymentRequirementService::requirementFor() mit dem NEUEN Startzeitpunkt und der neuen Personenzahl erneut auswerten. Steigt der geforderte Betrag, Reservierung auf PaymentPending zurücksetzen, payment_amount_minor/payment_due_at/deposit_rule_id neu setzen und die Zahlungsaufforderung schicken; sinkt er bei bereits gezahlter Anzahlung, Differenz über RefundService anstossen. Alternativ das Umbuchen für Buchungen mit deposit_rule_id oder payment_status != 'not_required' gesperrt lassen.

### 2. Walk-in ohne "Tisch teilen" schlaegt immer fehl (Uhrzeit liegt nie auf dem Slot-Raster)

`app/Http/Controllers/Admin/WalkInController.php:106`

WalkInController::store uebergibt `'start_local' => $nowLocal` mit `$nowLocal = CarbonImmutable::now($location->timezone)` (Zeile 79), also die echte Uhrzeit inkl. Sekunden/Mikrosekunden, dazu `table_ids => [$table->id]` und `skip_availability_check => $shared`. Ohne `shared` ist skip=false, also laeuft in ReservationLifecycleService::create der manuelle Zweig (Zeile 91) in `bookingBlockReason()`. Dort wird geprueft: `$onGrid = collect($validStarts)->contains(fn ($s) => $s->equalTo($startLocal))` (ReservationAvailabilityService.php:203). `$validStarts` kommt aus TimeSlotService::slotStarts und enthaelt ausschliesslich exakte Rasterzeitpunkte (Default slot_interval_minutes = 30, Sekunden = 0). `equalTo` vergleicht den absoluten Zeitpunkt inkl. Mikrosekunden. Eine echte Uhrzeit trifft dieses Raster praktisch nie. Ergebnis: `return 'outside_opening_hours'` -> ValidationException -> WalkInController faengt sie nicht ab -> 422 bzw. Redirect mit "Zu dieser Uhrzeit haben wir leider geschlossen", obwohl der Betrieb offen ist. Belegt wird das auch durch die Testabdeckung: tests/Feature/TableSharingTest.php ist der einzige Test auf /admin/walkins und setzt in beiden Faellen `'shared' => true`, umgeht also genau diesen Zweig. Ein Test fuer den normalen Walk-in existiert nicht.

**Auslöser:** Angemeldet als tenant_admin, Standort geoeffnet (z. B. 12:00-23:00), um 19:07:23 Uhr POST /admin/walkins mit {table_id, party_size: 2} und OHNE shared. Antwort: 422 / Fehler "Zu dieser Uhrzeit haben wir leider geschlossen", keine Reservierung angelegt.

**Vorschlag:** Fuer Buchungen "ab jetzt" (Walk-in, Warteliste-Uebernahme) die Rasterpruefung nicht anwenden: entweder in create() ein Flag `on_grid_check => false` fuer source `walk_in`, oder in bookingBlockReason/checkExact statt `equalTo` pruefen, ob $startLocal innerhalb eines Oeffnungsfensters liegt (opens <= start && start+duration <= closes).

### 3. Doppelte Erstattung: lockForUpdate sperrt nichts, wenn es noch keine Zeile gibt

`app/Services/RefundService.php:112`

requestForReservation() (Zeilen 108-142) und requestForEventBooking() (Zeilen 300-334) sichern die Doppelprüfung mit `Refund::withoutGlobalScopes()->where('reservation_id', ...)->whereNotIn('status', ['rejected','failed'])->lockForUpdate()->first()`. Der Kommentar ab Zeile 104 behauptet, damit könnten „two concurrent calls … not both pass the check". Das stimmt nicht: SELECT … FOR UPDATE sperrt in PostgreSQL nur vorhandene Zeilen. Trifft die Abfrage keine Zeile (der Normalfall bei der ERSTEN Erstattung), wird nichts gesperrt, es gibt kein Prädikat-/Gap-Lock in READ COMMITTED. Beide Transaktionen bekommen null, beide laufen weiter zu Refund::create() (Zeile 124 bzw. 316). Ein Unique-Index fehlt ebenfalls: database/migrations/2026_06_12_350000_create_refunds_and_settings.php legt auf refunds nur `index(['tenant_id','status'])` an (Zeile 31), keine Eindeutigkeit auf reservation_id/event_booking_id. Der CAS in process() (Zeile 196) schützt nur EINE Refund-Zeile gegen sich selbst, nicht zwei verschiedene Zeilen gegeneinander. Bei refund_mode='auto' + refund_processing='immediate' werden beide sofort ausgeführt, der Gast bekommt seine Anzahlung zweimal zurück. RefundConcurrencyTest deckt das nicht ab - er prüft nur process() auf derselben Zeile, und SQLite serialisiert Schreibvorgänge ohnehin.

**Auslöser:** Gast klickt auf der Verwaltungsseite zweimal schnell auf „Stornieren" (PublicBookingController::cancel, Zeile 662-692) - oder der Gast storniert, während ein Mitarbeiter im Reservierungsbuch dieselbe Buchung absagt (ReservationBookController Zeile 524). ReservationLifecycleService::transition() (Zeile 255) prüft den Status nur im Speicher und schreibt ohne Bedingung, hält also beide Wege nicht auf. Ergebnis: zwei Refund-Zeilen zum selben PaymentIntent, zwei Aufrufe von $provider->refund(), doppelte Auszahlung.

**Vorschlag:** Eindeutigkeit in die Datenbank legen: partieller Unique-Index auf refunds(reservation_id) bzw. refunds(event_booking_id) für alle Status ausser 'rejected'/'failed', und den Insert in einen catch(QueryException) fassen, der die vorhandene Zeile zurückgibt. Ersatzweise die Reservierungszeile selbst sperren (`Reservation::…->lockForUpdate()->find($id)` innerhalb der Transaktion), damit ein real existierendes Objekt die Serialisierung trägt.


## Hoch

### 4. Gast-gewählter Tisch umgeht Vorlaufzeit, Vorausbuchungsgrenze, Platzlimit und Pufferzeit

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Http/Controllers/Public/PublicBookingController.php:474`

Sobald store() ein table_id mitschickt, geht create() nicht mehr über checkExact(), sondern in den Handzweig (ReservationLifecycleService.php:85-106): dort laufen nur bookingBlockReason() und busyTableIds(). bookingBlockReason() (ReservationAvailabilityService.php:187-234) prüft ausschliesslich Slot-Raster, Standort-Blackout und Raum-Blackout. Es prüft NICHT min_lead_minutes, NICHT max_advance_days und NICHT max_covers_per_slot – diese drei stecken nur in checkSlotDetailed() (Zeile 269, 273, 290-304). TimeSlotService::slotStarts() (Zeile 52-71) filtert ebenfalls nichts gegen 'jetzt'. Dazu kommt die Pufferzeit: findTables() weitet das Fenster um buffer_minutes (TableAssignmentService.php:28-32), der Handzweig ruft busyTableIds() mit dem nackten Fenster (ReservationLifecycleService.php:99) – die Wende-/Reinigungszeit zwischen zwei Buchungen fällt weg. Konkrete Folgen für den Betrieb: Buchung für eine Uhrzeit, die heute schon vorbei ist; Buchung zwei Minuten vor Ankunft trotz 60 Minuten Vorlauf; Buchung ein Jahr im Voraus trotz 90-Tage-Grenze; im Modus 'hybrid' Überschreiten von max_covers_per_slot, weil der Deckenzähler gar nicht läuft; Tisch direkt anschliessend an eine laufende Buchung ohne Puffer. Der Test ManualTableRespectsClosuresTest deckt genau diesen Zweig ab, aber nur für Blackouts und Öffnungszeiten – die Zeit- und Kapazitätsgrenzen fehlen dort.

**Auslöser:** POST /book/{tenant}/{location} mit date=<heute>, time=<ein gültiger Slot-Start, der heute schon vorbei ist>, party_size=2, table_id=<id eines aktiven, online_bookable Tisches passender Grösse>. Die Reservierung wird angelegt, obwohl checkExact dieselbe Anfrage ohne table_id mit 'lead_time' abgelehnt hätte.

**Vorschlag:** Im Handzweig von create() zusätzlich die Zeit- und Kapazitätsgrenzen prüfen: min_lead_minutes und max_advance_days wie in checkSlotDetailed (nur bei online), und im Modus person/hybrid den Deckenzähler gegen effectiveMaxCovers laufen lassen. busyTableIds dort mit dem um buffer_minutes geweiteten Fenster aufrufen, so wie findTables und floorplan() es tun.

### 5. table_id wird auch angenommen, wenn der öffentliche Tischplan abgeschaltet ist

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Http/Controllers/Public/PublicBookingController.php:423`

floorplan() ist sauber abgesichert: abort_unless($settings->public_floorplan_enabled, 404) in Zeile 317. store() dagegen nimmt table_id bedingungslos entgegen (Regel Zeile 423, Auswertung Zeile 440-459) und prüft die Einstellung nie. In booking.blade.php steht das Feld <input name="table_id"> nur innerhalb von @if($settings->public_floorplan_enabled) (Zeile 447-476) – der Betreiber, der den Tischplan bewusst ausgeschaltet lässt, geht also davon aus, dass Gäste keine Tische aussuchen. Tatsächlich kann jeder Gast per Formular-POST einen beliebigen aktiven, online buchbaren Tisch belegen: den Fenstertisch, den Tisch für die grosse Gruppe, den Tisch, den die Tischzuteilung für später freihalten würde. Die gesamte Zuteilungsstrategie aus TableAssignmentService (kleinster passender Tisch, Kombinationen, Lückenoptimierung) wird damit ausgehebelt, und die Buchung landet mit table_chosen_by_guest=true im Betrieb, obwohl der Gast diese Wahl nie hätte treffen dürfen. GuestTableChoiceTest prüft nur den Fall mit eingeschaltetem Tischplan.

**Auslöser:** Standort mit public_floorplan_enabled=false. POST /book/{tenant}/{location} mit den normalen Formularfeldern plus table_id=<beliebige aktive, online_bookable Tisch-ID des Standorts, dessen Kapazität zur Personenzahl passt>. Die Reservierung entsteht auf genau diesem Tisch.

**Vorschlag:** In store() vor der table_id-Auswertung abbrechen, wenn ! $settings->public_floorplan_enabled – entweder table_id stillschweigend verwerfen (dann greift die automatische Zuteilung) oder mit 422 ablehnen. Die Prüfung gehört an dieselbe Stelle wie die in floorplan().

### 6. Salon-Termine ohne Datumsgrenze: Buchung in der Vergangenheit und ohne Vorlaufzeit möglich

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Http/Controllers/Public/PublicBookingController.php:513`

storeSalon() validiert 'date' nur mit date_format:Y-m-d – das after_or_equal:today aus dem Restaurantpfad (Zeile 414) fehlt. Der Kommentar in Zeile 549-552 behauptet, der anschliessende bookingBlockReason()-Aufruf verhindere Buchungen 'zwei Minuten vor dem Termin', und die Fehlermeldungen in Zeile 570-571 behandeln 'lead_time' und 'too_far_ahead'. Beides trifft nicht zu: bookingBlockReason() (ReservationAvailabilityService.php:187-234) gibt nur 'outside_opening_hours' oder 'blackout' zurück, die beiden match-Zweige sind toter Code. Der zweite Prüfweg hilft auch nicht: staffAvailableAt() (SalonAvailabilityService.php:268-285), das hinter isStaffAvailableForServices() und firstAvailableStaffForServices() steht, prüft Arbeitszeitfenster, Abwesenheiten und Kollisionen – aber keine Vorlaufzeit. min_lead_minutes taucht im Salon-Code nur in staffSlots() (Zeile 194) auf, und das erzeugt bloss die ANGEZEIGTE Slotliste. Zusätzlich läuft create() für den Salon mit skip_availability_check=true (Zeile 594), umgeht also auch checkSlotDetailed. Ergebnis: Termine an einem Datum in der Vergangenheit, Termine in zwei Minuten trotz 60 Minuten Vorlauf, Termine jenseits von max_advance_days. Ein Termin mit start_at in der Vergangenheit zählt über activeStatuses weiter als belegend und verstopft den Kalender der Mitarbeiterin.

**Auslöser:** POST /book/{tenant}/{location} bei einem Salon-Mandanten mit date=2020-05-16 (ein Wochentag, an dem der Standort Öffnungszeiten hat), time=<gültiger Slot-Start>, service_ids[]=<aktive Leistung>, name/email/privacy_accepted. Der Termin wird angelegt und bestätigt. Genauso mit date=<heute> und einer Uhrzeit in 5 Minuten bei min_lead_minutes=60.

**Vorschlag:** 'date' in storeSalon() um after_or_equal:today ergänzen und die beiden fehlenden Zeitgrenzen dort prüfen, wo die Meldungen sie schon erwarten: entweder min_lead_minutes/max_advance_days in bookingBlockReason() aufnehmen (dann stimmen die match-Zweige in Zeile 570/571 auch) oder in staffAvailableAt() – dann greifen sie zugleich beim Umbuchen über canStaffTake().

### 7. Aufbewahrungsfristen laufen nur für aktive Betriebe – gesperrte und gekündigte nie

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Jobs/RunRetentionPolicies.php:19`

`Tenant::where('status', 'active')->each(...)` – der nächtliche Lauf nimmt ausschließlich Mandanten mit Status `active`. `tenants.status` kennt laut Migration (2026_06_12_000010_create_saas_tables.php:32) auch `suspended` und `cancelled`, und beide werden aktiv gesetzt: SaasTenantController.php:136 (`$tenant->update(['status' => $validated['status']])`) sowie der Trial-/Billing-Pfad. Genau diese Betriebe arbeiten nicht mehr mit den Daten, also läuft für sie `GuestPrivacyService::runRetention()` nie – die Gästeprofile bleiben mit Namen, E-Mail, Telefon, Geburtstag und Allergien unbegrenzt stehen, obwohl die Datenschutzerklärung (resources/legal/datenschutz.md:243–246) eine automatische Anonymisierung nach 36 Monaten zusagt. Ein gekündigter Betrieb, dessen Daten aus Nachweisgründen noch liegen, ist der Fall, in dem die Frist am wichtigsten wäre. Der Mandant ist auch nicht gelöscht: `destroyTenant()` (AccountController.php:183) ist ein separater, freiwilliger Vorgang.

**Auslöser:** Ein Betrieb wird wegen offener Rechnungen auf `suspended` gesetzt oder kündigt (`cancelled`). Ab diesem Tag läuft für alle seine Gäste keine Anonymisierung mehr; Profile, die morgen 36 Monate alt werden, bleiben für immer im Klartext.

**Vorschlag:** Statusfilter entfernen oder auf `whereNotIn('status', ['deleted'])` erweitern und über alle nicht gelöschten Mandanten laufen. Die Anonymisierung ist eine gesetzliche Pflicht, keine Funktion des Tarifs. Test: Mandant auf `cancelled` setzen, Job laufen lassen, Anonymisierung erwarten.

### 8. Import schreibt Fremdschlüssel der Quellinstallation ungeprüft weiter

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Services/AccountImportService.php:129`

`simple($tenant, $data, 'guests', Guest::class)` wird OHNE Relations-Karte aufgerufen. `attrs()` (Zeile 57–61) entfernt nur id/tenant_id/Zeitstempel/created_by/user_id/approved_by/requested_by/code/manage_token/token/secret – `preferred_location_id`, `preferred_room_id` und `preferred_table_id` bleiben stehen und sind in `Guest::$fillable` (app/Models/Guest.php:27) massenzuweisbar. Der Wert aus der QUELLdatenbank landet also unverändert in der Zieldatenbank. Alle drei Spalten tragen echte Fremdschlüssel (database/migrations/2026_06_12_000040_create_guest_tables.php:23–25 → locations, rooms, restaurant_tables). Zwei Ausgänge: existiert die ID im Ziel nicht (typischer Fall „Umzug auf frische Installation"), bricht PostgreSQL mit einer FK-Verletzung ab und die GANZE Transaktion (Zeile 98) rollt zurück – der Umzug schlägt fehl. Existiert sie, zeigt das Gastprofil auf Standort/Raum/Tisch eines FREMDEN Mandanten. Dieselbe Lücke an zwei weiteren Stellen: `events` (Zeile 121) mappt nur `location_id`, nicht `room_id` (Event::$fillable enthält 'room_id', FK auf rooms), und `tables()` (Zeile 239–258) mappt `location_id`/`room_id`, nicht `backup_table_id` (RestaurantTable::$fillable:22, Selbst-FK). Die Räume sind zum Zeitpunkt des Event-Imports längst gemappt (Zeile 102) – es fehlt schlicht der Eintrag. AccountImportTest deckt das nicht ab: der Test löscht die Quelle vorher (tests/Feature/AccountImportTest.php:67) und die Factory setzt die preferred_-Spalten nie.

**Auslöser:** Betrieb A hat bei einem Gast einen Lieblingstisch hinterlegt (preferred_table_id = 41) und exportiert sein Konto. Beim Einspielen in eine frische Installation existiert restaurant_tables.id 41 nicht → FK-Verletzung, kompletter Import bricht ab. Auf einer geteilten Installation existiert id 41 (gehört Betrieb B) → der Gast zeigt still auf einen fremden Tisch.

**Vorschlag:** Relations-Karte ergänzen: guests → ['preferred_location_id' => 'locations', 'preferred_room_id' => 'rooms', 'preferred_table_id' => 'tables'] (Gäste dafür nach den Tischen importieren), events → ['room_id' => 'rooms'], tables → backup_table_id in einem zweiten Durchlauf nachziehen, sobald alle Tisch-IDs bekannt sind. Zusätzlich einen Test, der für jede importierte Tabelle prüft, dass keine Spalte mit Suffix `_id` einen Wert trägt, der nicht aus der Map stammt.

### 9. Import weckt gelöschte Tische, Leistungen und Mitarbeiter wieder auf

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Services/AccountImportService.php:58`

Der Export nimmt bewusst gelöschte Datensätze mit: `withTrashed()` bei tables (AccountExportService.php:90), guests (98), events (106), services (111), staff_members (112) und reservations (168). Der Import wirft `deleted_at` in der DROP-Liste weg (Zeile 58). Ergebnis: jede weich gelöschte Zeile entsteht im Ziel als AKTIVER Datensatz neu. Betroffen sind reale Löschvorgänge – `SettingsController::deleteTable()` (Zeile 759), `ServiceController` (121) und `StaffMemberController` (115) löschen weich. Ein Tisch, den der Betrieb aus dem Raum genommen hat, steht danach wieder im Tischplan, ist `is_active`/`online_bookable` (beide Werte werden 1:1 übernommen) und wird von der automatischen Tischvergabe wieder belegt. Eine abgesetzte Leistung taucht wieder im öffentlichen Buchungsformular auf und ist buchbar; ein ausgeschiedener Mitarbeiter erscheint wieder in der Terminplanung. Der Betrieb bemerkt das erst, wenn ein Gast an einem Tisch sitzen soll, der nicht mehr existiert.

**Auslöser:** Betrieb löscht Tisch „T5" (weich, weil noch alte Reservierungen daran hängen), exportiert später sein Konto und spielt es auf dem neuen Server ein. Danach: Tischplan zeigt T5 wieder, T5 ist online buchbar, TableAssignmentService vergibt ihn.

**Vorschlag:** `deleted_at` aus der DROP-Liste nehmen und stattdessen nach dem `save()` gezielt setzen (`$model->deleted_at = $row['deleted_at']` bzw. `$model->delete()`), oder – falls Papierkorbzeilen nicht mitwandern sollen – im Import Zeilen mit gesetztem `deleted_at` überspringen. Erste Variante ist konsistenter, weil sonst alte Reservierungen ihre Tischzuordnung verlieren.

### 10. Tischnamen-Landkarte kollidiert – Reservierungen landen am falschen Tisch

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Services/AccountImportService.php:256`

`tables()` baut die Nachschlagetabelle `$this->map['table_names'][$locationId.'|'.$table->name]` (Zeile 256) und überschreibt bei gleichem Schlüssel kommentarlos. Auf `restaurant_tables` gibt es keinen Unique-Index auf (location_id, name) (database/migrations/2026_06_12_000030_create_table_tables.php) und auch keine Unique-Validierung beim Anlegen (SettingsController.php:666: `'name' => ['required','string','max:40']`). Zwei Tische mit demselben Namen in einem Raum sind also möglich – und durch `withTrashed()` im Export (AccountExportService.php:90) sogar der Normalfall: „T2" gelöscht, „T2" neu angelegt. Danach gewinnt die zuletzt eingelesene Zeile den Kartenschlüssel. `reservations()` (Zeile 322–327) und `combinations()` (Zeile 280) schlagen ausschließlich über diesen Schlüssel nach. Alle Reservierungen, die am alten T2 hingen, werden im Ziel an den neuen T2 gehängt – oder umgekehrt an die wiederauferstandene Papierkorbzeile. Doppelbelegungen und ein falscher Tischplan sind die direkte Folge; auffällig wird es erst am Abend am Tisch.

**Auslöser:** Quellsystem hat einen weich gelöschten Tisch „T2" (id 7, alte Reservierungen) und einen aktiven Tisch „T2" (id 41, aktuelle Reservierungen) im selben Raum. Export enthält beide. Import: beide werden angelegt, der zweite überschreibt den Kartenschlüssel „<loc>|T2". Sämtliche Reservierungen beider Tische werden anschließend demselben neuen Tisch zugeordnet.

**Vorschlag:** Statt über den Namen über die bereits vorhandene ID-Map verknüpfen: im Export zusätzlich `table_ids` (die Quell-IDs) je Reservierung/Kombination mitgeben und beim Import über `$this->mapped('tables', $id)` auflösen; `table_names` nur noch als Rückfallebene für ältere Dateien. Mindestens aber beim Setzen des Kartenschlüssels nicht überschreiben, wenn er schon belegt ist, und Papierkorbzeilen aus der Karte heraushalten.

### 11. Anonymisierung erreicht das Auditlog nicht – Name, Telefon und Allergien bleiben lesbar

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Services/GuestPrivacyService.php:161`

`anonymize()` räumt Reservierungs-Snapshots, Gastnotizen, Eventbuchungen, Wartelisten, Merge-Logs, Anhänge und das Versandprotokoll auf – aber `audit_logs` wird nirgends angefasst. Dort steht die vollständige Historie im Klartext: `GuestController::update()` (app/Http/Controllers/Admin/GuestController.php:144) schreibt `$old` und `$validated` in `old_values`/`new_values`, und `$validated` enthält first_name, last_name, email, phone, birthday, preferences, allergies, accessibility_notes. Dass diese Werte dort landen und angezeigt werden, belegt der Feldbeschriftungs-Katalog im Modell selbst (app/Models/AuditLog.php:47–61: 'first_name' => 'Vorname', 'email' => 'E-Mail', 'phone' => 'Telefon', 'birthday' => 'Geburtstag', 'allergies' => 'Allergien'), plus `fieldChanges()` (Zeile 148) und die Ausgabe in resources/views/admin/audit/index.blade.php:22/50. Nach der „Löschung" sieht jeder Mitarbeiter mit Auditlog-Zugriff weiterhin Vorname, Nachname, E-Mail, Telefon, Geburtstag und Allergien (Gesundheitsdaten, Art. 9) des Gastes. Die Datenschutzerklärung verspricht in §14 (resources/legal/datenschutz.md:243) genau das Gegenteil, und für das Versandprotokoll ist der Bereinigungsschritt in §9 sogar ausdrücklich zugesagt und umgesetzt (Zeile 142–158) – beim Auditlog fehlt er.

**Auslöser:** Gast ruft an, Mitarbeiter korrigiert Telefonnummer und Allergie im Gastprofil (erzeugt audit_logs-Eintrag 'guest.updated' mit alten und neuen Werten). Später verlangt derselbe Gast Löschung nach Art. 17, der Betrieb klickt „Anonymisieren". Danach Admin → Änderungsprotokoll aufrufen: der Eintrag zeigt weiterhin „Telefon: +49 … → +49 …" und „Allergien: Nüsse → Nüsse, Sellerie".

**Vorschlag:** In `anonymize()` innerhalb der Transaktion die Auditeinträge dieses Gastes mitnehmen: `AuditLog::where('entity_type', Guest::class)->where('entity_id', $guest->id)->update(['old_values' => null, 'new_values' => null])`. Die Aktion, der Zeitpunkt und der Benutzer bleiben damit nachweisbar, nur die Werte fallen weg. Zusätzlich prüfen, ob Reservierungs-Auditeinträge (guest_note, allergy_note stehen ebenfalls im Katalog) über die Reservierungen des Gastes mit erfasst werden müssen.

### 12. GoCardless-Abo wird innerhalb der Datenbanktransaktion angelegt - Rollback lässt ein bezahltes Abo ohne Gegenstück zurück

`app/Http/Controllers/Admin/DirectDebitController.php:133`

complete() ruft `$this->gocardless->createSubscription(...)` in Zeile 133 INNERHALB von `DB::transaction()` (ab Zeile 123) auf, also nach `BillingProfile::…->lockForUpdate()`. Der Aufruf ist ein externer, nicht rückholbarer Seiteneffekt: GoCardless legt ein monatlich einziehendes Abo an. Schlägt danach irgendetwas in der Transaktion fehl - `$profile->fill(...)->save()` (Zeile 140-146) oder `$tenant->update(...)` (Zeile 149), etwa wegen Verbindungsabbruch oder Deadlock -, rollt die Datenbank zurück, das Abo bei GoCardless bleibt aber bestehen. Im Profil steht dann weder gocardless_subscription_id noch gocardless_status='active'; hasActiveDirectDebit() (BillingProfile Zeile 23-27) meldet false. Der nächste Einrichtungsversuch läuft folglich durch und legt gegen dasselbe Mandat ein ZWEITES Abo an - der Kunde wird ab dann doppelt eingezogen, und das erste Abo taucht in der Anwendung nirgends auf, ist also auch nicht kündbar (cancel() in Zeile 193 kennt nur die gespeicherte ID). Nebenbei hält die Transaktion die Zeilensperre über einen HTTP-Aufruf mit 20 s Zeitlimit (GoCardlessService Zeile 39).

**Auslöser:** Datenbankfehler oder Zeitüberschreitung zwischen Zeile 133 und dem Ende der Transaktion; anschliessend startet der Kunde die Lastschrift-Einrichtung erneut, weil die Oberfläche „kein aktives Mandat" zeigt.

**Vorschlag:** Reihenfolge umdrehen: in der Transaktion nur Mandat + Absichtsmarker festschreiben, danach ausserhalb der Transaktion das Abo anlegen und die ID in einem eigenen UPDATE nachtragen. Vor dem Anlegen zusätzlich die vorhandenen Abos zum Mandat bei GoCardless abfragen (oder eine eigene Idempotenzkennung mitgeben), damit ein Wiederholungsversuch kein zweites Abo erzeugt.

### 13. Wiederholen-Knopf setzt 'failed' ohne Bedingung auf 'approved' - zweite echte Erstattung möglich

`app/Http/Controllers/Admin/RefundController.php:56`

retry() prüft in Zeile 55 `abort_unless($refund->status === 'failed', 422)` gegen den GELESENEN Status und schreibt danach in Zeile 56 bedingungslos `$refund->update(['status' => 'approved', 'error' => null])`. Genau diesen Knopf nennt der Kommentar in RefundService::process() (Zeilen 192-195) als Grund für den dortigen CAS - der CAS greift hier aber nicht, weil retry() den Zustand VOR dem CAS zurückdreht. Ablauf: A liest 'failed', B liest 'failed'; A schreibt 'approved'; A gewinnt den CAS und ist beim Anbieter unterwegs (HTTP, bis 15 s); B schreibt jetzt 'approved' und überschreibt damit das 'processing' des laufenden Vorgangs; B gewinnt seinen eigenen CAS und ruft $provider->refund() ein zweites Mal auf. Derselbe Effekt entsteht ohne zweiten Menschen, wenn ProcessScheduledRefunds/processDue() (Zeile 350) zwischen dem update() in Zeile 56 und dem CAS in Zeile 199 läuft.

**Auslöser:** Zwei Mitarbeiter (oder ein Doppelklick auf ein Formular ohne Absendesperre) drücken „Erneut versuchen" auf derselben fehlgeschlagenen Erstattung; der Anbieteraufruf dauert lange genug, dass sich die Fenster überlappen.

**Vorschlag:** Den Statuswechsel selbst als Compare-and-Swap ausführen: `Refund::whereKey($refund->id)->where('status','failed')->update(['status'=>'approved','error'=>null])` und bei 0 betroffenen Zeilen mit einer Meldung abbrechen, statt process() aufzurufen.

### 14. Tischwechsel weist Tische des aktiven Standorts einer Reservierung eines anderen Standorts zu

`app/Http/Controllers/Admin/ReservationBookController.php:656`

moveTable() filtert die übergebenen table_ids gegen den AKTIVEN Standort aus dem Mandantenkontext, nicht gegen den Standort der Reservierung:

    $location = $this->context->location();          // Zeile 655
    $tableIds = collect($validated['table_ids'])
        ->filter(fn ($id) => $location->tables()->where('id', $id)->exists())   // Zeile 658

authorizeReservation() (Zeile 772-779) erlaubt aber ausdrücklich Reservierungen FREMDER Standorte desselben Mandanten, sobald canAccessLocation() true liefert (all_locations = true ist der Normalfall für Inhaber und Betriebsleitung). Auch die Oberfläche zieht die Auswahlliste aus dem aktiven Standort: resources/views/admin/reservations/show.blade.php:106 rendert `$location->rooms()->with('tables')` mit dem $location aus show() (Zeile 258), also dem aktiven Standort.

ReservationLifecycleService::reassignTables (Zeile 371-389) prüft die Kollision anschließend gegen den Standort DER RESERVIERUNG: `$location = $reservation->location()...` und TableAssignmentService::busyTableIds (Zeile 142) fragt `$location->reservations()`. Ein Tisch aus Standort A taucht in dieser Belegtliste nie auf, die Konfliktprüfung schlägt also niemals an, und `$reservation->tables()->sync($tableIds)` schreibt die Pivotzeile ohne weitere Prüfung (es gibt keine Standort-Bedingung auf reservation_tables).

Folge: Die Reservierung von Standort B belegt Tisch "A-5" von Standort A. Für Standort A ist diese Belegung unsichtbar, weil FloorPlanController::state (Zeile 71), ReservationBookController::timelineData (Zeile 115) und busyTableIds alle nach location_id filtern — der Tisch wird in Standort A weiter als frei angeboten und ein zweites Mal vergeben. Gleichzeitig steht in der Reservierung von Standort B ein Tisch, den es dort gar nicht gibt.

**Auslöser:** Benutzer mit zwei Standorten (all_locations = true), aktiver Standort A. Ein Gastprofil öffnen (/admin/guests/{id} listet in GuestController.php:84 die Reservierungen ALLER Standorte des Mandanten), von dort die Reservierung von Standort B öffnen und im Block "Tische" einen Tisch speichern — die Auswahlliste enthält nur Tische von Standort A. Danach ist Tisch A-5 laut Datenbank durch eine Reservierung von Standort B belegt, im Tischplan und in der Online-Verfügbarkeit von Standort A aber weiterhin frei.

**Vorschlag:** In moveTable() den Standort der Reservierung verwenden statt des aktiven Kontexts (`$location = $reservation->location()->withoutGlobalScope('tenant')->first()`), und in reassignTables() zusätzlich abweisen, wenn eine der übergebenen Tisch-IDs nicht zu $reservation->location_id gehört. Die Auswahlliste in show.blade.php ebenfalls aus dem Standort der Reservierung füllen.

### 15. users.invite erlaubt es, sich selbst Zugriff auf fremde Standorte zu verschaffen

`app/Http/Controllers/Admin/UserManagementController.php:60`

invite() legt für eine bereits existierende Mailadresse die Mitgliedschaft per firstOrCreate an (Zeile 56-59) — bei vorhandener Mitgliedschaft bleibt die Rolle also unverändert, das ist richtig. Der Standortblock danach läuft aber trotzdem:

    if (! $request->boolean('all_locations', true)) {
        $locationIds = collect($validated['location_ids'] ?? [])
            ->filter(fn ($id) => $tenant->locations()->where('id', $id)->exists());
        foreach ($locationIds as $locationId) {
            DB::table('location_user')->insertOrIgnore([... 'user_id' => $user->id ...]);

Geprüft wird nur, dass die Standorte zum eigenen Mandanten gehören — nicht, ob es sich um eine neue Mitgliedschaft handelt und nicht, wessen Mitgliedschaft erweitert wird. $user stammt aus `User::where('email', ...)` (Zeile 54) und darf der Aufrufer selbst sein.

Damit hängt die Standortfreigabe faktisch an users.invite, während jede andere Änderung an einer Mitgliedschaft hinter users.roles.manage liegt (routes/web.php:434-439). Die Rolle operations_manager hat users.invite, aber ausdrücklich NICHT users.roles.manage (config/permissions.php:65). Der Docblock über assignableRoles() (Zeile 94-101) beschreibt exakt diese Bugklasse für Rollen — für Standorte fehlt die Grenze.

Die so eingetragene Zeile wirkt sofort: User::canAccessLocation() prüft `$this->allowedLocations()->where('locations.id', $location->id)->exists()` (User.php:130-132), und darüber laufen ResolveTenantContext, RequirePermission und alle authorizeReservation-Prüfungen.

**Auslöser:** Benutzer mit Rolle operations_manager, Mitgliedschaft all_locations = false, freigegeben nur für Standort A. POST /admin/users/invite mit email=<eigene Adresse>, role=<beliebig>, all_locations=0, location_ids[]=<ID von Standort B>. Die Mitgliedschaft bleibt unverändert, aber in location_user steht danach eine Zeile für Standort B. Anschließend POST /admin/switch-location mit location_id=B — der bisher gesperrte Standort ist offen.

**Vorschlag:** Den Standortblock nur ausführen, wenn die Mitgliedschaft in diesem Aufruf neu entstanden ist ($membership->wasRecentlyCreated), und das Erweitern bestehender Mitgliedschaften nach users.roles.manage verschieben. Zusätzlich abweisen, wenn $user->id === $request->user()->id — niemand sollte seine eigene Freigabe über diese Route verändern.

### 16. "Gast von der Warteliste uebernehmen" schlaegt aus demselben Grund fehl

`app/Http/Controllers/Admin/WaitlistAdminController.php:98`

Wenn zu einem Wartelisteneintrag kein offenes Angebot existiert, legt seat() eines mit `now()->toImmutable()` als Startzeit an und ruft sofort acceptOffer(). acceptOffer (WaitlistService.php:143) baut daraus `'start_local' => CarbonImmutable::parse($offer->offered_start_at)->setTimezone($location->timezone)` und uebergibt KEINE table_ids. In create() laeuft damit der Zweig `checkExact()` (ReservationLifecycleService.php:71), und checkExact prueft dieselbe Rasterbedingung `$s->equalTo($startLocal)` (ReservationAvailabilityService.php:90). Eine Ist-Uhrzeit liegt nie exakt auf einem Slotstart -> `reason = 'outside_opening_hours'` -> ValidationException. WaitlistAdminController::seat faengt sie nicht ab, der Gast wird nicht platziert. Nur der Weg ueber ein zuvor per Formular gesetztes Angebot (Zeile 76, Uhrzeit aus 'H:i') funktioniert.

**Auslöser:** Wartelisteneintrag ohne offenes Angebot, Klick auf "Platzieren"/seat waehrend der Oeffnungszeiten -> ValidationException statt Reservierung.

**Vorschlag:** seat() ueber denselben Weg fuehren wie den Walk-in und die Rasterpruefung fuer Sofortplatzierungen abschalten; zusaetzlich acceptOffer() defensiv abfangen und dem Personal eine verstaendliche Meldung zeigen.

### 17. switchTenant lässt jeden Plattform-Admin ohne Auditeintrag in jeden Mandanten

`app/Http/Controllers/Auth/AuthController.php:79`

switchTenant() prüft nur `if (! $user->isSaasAdmin() && $user->membershipFor($tenant) === null) abort(403);` — für JEDEN Benutzer mit gesetztem saas_role fällt die Mitgliedschaftsprüfung also komplett weg, und `current_tenant_id` wird auf einen beliebigen fremden Mandanten gesetzt (Zeile 83-86). Damit gibt es zwei Wege in einen fremden Mandanten, und der zweite unterläuft alle Auflagen des ersten:

- SaasTenantController::impersonate() (Zeile 184-203) ist per authorizeSaas($request, write: true) auf super_admin und support_admin beschränkt (Zeile 222-224), verlangt einen Grund, setzt session('impersonating_tenant_id') und schreibt `support.impersonation_started` ins Auditlog. Der Docblock sagt ausdrücklich "always audited".
- POST /admin/switch-tenant (routes/web.php:193) hängt nur an ['auth','tenant','license','trial'] — keine permission-Middleware, kein saas_role-Filter, kein Auditlog, keine Impersonation-Session.

ResolveTenantContext lässt einen SaaS-Admin mit current_tenant_id === null durch (Zeile 50-52), EnsureTrialActive ebenfalls (tenant === null → next). Der Aufruf ist also für readonly_admin und billing_admin voll erreichbar. Danach liefert User::canInTenant() für readonly_admin `str_ends_with($permission, '.view')` === true (User.php:87-89) — also reservations.view, guests.view, guest_notes.view, guest_notes.sensitive.view, consents.view, audit.view im fremden Mandanten. Der Kommentar in ResolveTenantContext.php:36 ("SaaS admins may enter any tenant (audited via impersonation flow)") beschreibt eine Zusicherung, die dieser Weg nicht einhält. Ein grep über resources/views findet kein Formular, das auf switch-tenant postet — die Route ist ohne UI, aber im Router aktiv.

**Auslöser:** Als Benutzer mit saas_role='readonly_admin' (oder 'billing_admin') anmelden und POST /admin/switch-tenant mit tenant_id=<fremder Mandant> senden. Danach /admin/reservations, /admin/guests/{id}, /admin/audit aufrufen: alle Gast- und Reservierungsdaten des Kunden sind lesbar, im Auditlog des Kunden steht kein einziger Eintrag über den Zugriff. Über /saas/tenants/{id}/impersonate wäre derselbe Benutzer mit 403 abgewiesen worden.

**Vorschlag:** switchTenant auf denselben Pfad zwingen wie impersonate: entweder die Mitgliedschaft auch für SaaS-Admins verlangen, oder — wenn Supportzugriff über diese Route erwünscht ist — dieselbe Rollenprüfung (super_admin/support_admin), denselben Auditeintrag und dasselbe Setzen von session('impersonating_tenant_id') ausführen. Am saubersten: die Route entfernen (kein Blade nutzt sie) und alle Mandantenwechsel über SaasTenantController::impersonate/stopImpersonation führen.

### 18. Stripe-Webhook bucht 'checkout.session.completed' als bezahlt, ohne payment_status zu prüfen

`app/Http/Controllers/Public/PaymentController.php:340`

Im Webhook wird 'checkout.session.completed' direkt auf handlePaid() gemappt; aus dem Objekt werden nur `id` und `payment_intent` gelesen. Das Feld `payment_status` derselben Checkout-Session wird nicht angesehen - obwohl der Code es an anderer Stelle sehr wohl auswertet: StripeProvider::fetchSession() liest in Zeile 87 `$response->json('payment_status') === 'paid'`, und stripeReturn() bucht in Zeile 194 nur bei `$session['paid']`. Für verzögerte Zahlarten (Lastschrift, Sofortüberweisung - im Kommentar Zeile 200-202 ausdrücklich als Fall genannt) kommt 'checkout.session.completed' mit payment_status='unpaid'. Der Rückweg des Gastes verhält sich also korrekt, der Webhook nicht. Zusätzlich fehlt im match (Zeilen 339-348) jede Behandlung des Gegenereignisses: nur 'completed' und 'expired' werden verarbeitet, ein späteres Fehlschlagen der Zahlung ändert nichts mehr.

**Auslöser:** Gast bezahlt die Anzahlung per SEPA-Lastschrift oder Sofortüberweisung. Der Webhook trifft sofort ein, handlePaid() setzt PaymentIntent auf 'paid', reservation.payment_status auf 'paid' und schaltet die Reservierung über den Lifecycle auf Confirmed (Zeilen 385-391), es geht eine Bestätigungsmail raus. Die Zahlung scheitert Tage später - im System bleibt sie 'paid', der Tisch ist reserviert, Geld ist nie geflossen. Umgekehrt kann der Betrieb den Vorgang auch nicht mehr erstatten, weil er nie als offen erscheint.

**Vorschlag:** Im Webhook nur buchen, wenn `($event['data']['object']['payment_status'] ?? null) === 'paid'`; sonst den Vorgang auf einem Zwischenstatus lassen. 'checkout.session.async_payment_succeeded' zusätzlich auf handlePaid mappen und 'checkout.session.async_payment_failed' auf einen Weg, der Reservierung und Intent wieder öffnet.

### 19. Bezahlte Checkout-Sitzung wird nach erneutem Aufruf des Bezahllinks unüberprüfbar - Zahlung fällt still unter den Tisch

`app/Http/Controllers/Public/PaymentController.php:131`

Ein Vorgang hat genau EIN Feld provider_intent_id. Der Bezahllink erzeugt über firstOrCreate() (Zeilen 55-58 bzw. 107-110) beim zweiten Aufruf keinen neuen PaymentIntent, sondern nimmt den bestehenden 'pending' und überschreibt in Zeile 74/131 provider_intent_id mit der Kennung der NEUEN Anbietersitzung. Die alte Sitzung bleibt beim Anbieter aber bezahlbar. Kommt der Gast aus der alten Sitzung zurück, schlägt die Prüfung `hash_equals((string) $paymentIntent->provider_intent_id, $sessionId)` in Zeile 185 (Stripe) bzw. Zeile 149 (PayPal) fehl und der Rückweg endet mit abort(403) - obwohl bezahlt wurde. Bei zwei Anbietern verschärft sich das: die Anbieterauswahl (Zeile 247) führt beide Wege auf denselben Intent, ein Gast, der Stripe UND PayPal öffnet, kann zweimal bezahlen und bekommt für eine der beiden Zahlungen einen 403. Der PayPal-Schutz über die eindeutige Rechnungsnummer (PaymentReference::invoice, SWAYY-{intent id}) greift dagegen nicht, weil er pro Intent vergeben wird und für Stripe gar nicht existiert.

**Auslöser:** Gast klickt „Jetzt bezahlen", die Anbieterseite lädt langsam, er geht zurück und klickt erneut (neue Sitzung, provider_intent_id überschrieben) und bezahlt dann im ersten, noch offenen Tab. Rückweg: 403. Bucht der Betrieb nicht über einen eingerichteten Stripe-Webhook nach - was der Kommentar in Zeile 173-176 ausdrücklich als realen Fall beschreibt -, ist das Geld kassiert und im System nie angekommen; die Reservierung verfällt nach Fristablauf.

**Vorschlag:** Die Sitzungskennungen sammeln statt überschreiben (z. B. Liste in metadata) und im Rückweg gegen jede bekannte Kennung prüfen; oder pro Checkout-Start einen eigenen PaymentIntent anlegen und den vorherigen sofort auf 'cancelled' setzen. Zusätzlich: bei fehlgeschlagenem hash_equals nicht stumm 403, sondern Auditeintrag, damit ein solcher Fall überhaupt auffällt.

### 20. firstOrCreate ohne Unique-Index: zwei gleichzeitige Bezahllink-Aufrufe erzeugen zwei Vorgänge

`app/Http/Controllers/Public/PaymentController.php:107`

`PaymentIntent::withoutGlobalScopes()->firstOrCreate(['tenant_id'=>…,'reservation_id'=>…,'type'=>'deposit','status'=>'pending'], …)` (analog Zeile 55 für Eventbuchungen) ist ohne Datenbankeindeutigkeit nicht atomar: firstOrCreate macht SELECT, dann INSERT. database/migrations/2026_06_12_000080_create_payment_tables.php legt auf payment_intents (Zeilen 32-46) gar keinen Unique-Index an. Zwei zeitgleiche GETs auf denselben Bezahllink finden beide nichts und legen beide eine Zeile an - zwei Anbietersitzungen über denselben Betrag, beide bezahlbar. Beide handlePaid()-Läufe (Zeile 353) laufen sauber durch, weil der CAS dort auf whereKey() steht und die zwei Zeilen sich nicht sehen. Danach ist die Reservierung „bezahlt", und die Erstattungslogik findet die Doppelzahlung nicht: RefundService sucht in Zeile 95-99 mit `->latest()->first()` genau EINEN bezahlten Intent, der zweite bleibt unbemerkt beim Anbieter liegen.

**Auslöser:** Gast öffnet den Bezahllink in zwei Tabs (oder Doppelklick auf den Link in der Zahlungsaufforderung - throttle:booking erlaubt 10 Anfragen/Minute, AppServiceProvider Zeile 31) und bezahlt in beiden. Der Betrieb kassiert doppelt, im System steht eine Anzahlung.

**Vorschlag:** Partiellen Unique-Index auf payment_intents(reservation_id) bzw. (event_booking_id) für status='pending' anlegen und den firstOrCreate-Insert gegen die QueryException absichern; oder den Vorgang beim Anlegen unter einer Sperre auf der Reservierungszeile erzeugen.

### 21. payment_status bleibt für immer 'pending' - der Gast kann nicht mehr stornieren, der Tisch bleibt blockiert

`app/Http/Controllers/Public/PublicBookingController.php:675`

Beim Start des Bezahlvorgangs setzt PaymentController Zeile 132 `payment_status => 'pending'`. Kein Codepfad setzt diesen Wert je auf 'required' zurück: handleExpired() (PaymentController Zeile 420-425) fasst nur den PaymentIntent an, die Reservierung bleibt unberührt; ein Verzeichnis über alle Schreibzugriffe auf reservations.payment_status zeigt sonst nur 'paid', 'expired', 'forfeited', 'refunded' und 'not_required'. In cancel() blockiert Zeile 675 aber genau diesen Wert: „Deine Zahlung wird gerade verarbeitet. Bitte warte kurz und versuche es erneut." - dauerhaft. Der automatische Verfall fängt das nicht auf: ExpireUnpaidReservations::expire() nimmt in Zeile 130-135 nur Buchungen ohne Anzahlungsregel oder mit `cancel_unpaid_automatically = true`; die Spalte hat laut Migration (2026_06_12_000080, Zeile 27) den Vorgabewert false, und das Admin-Formular schreibt bei nicht gesetztem Haken über `$request->boolean()` ebenfalls false. Status PaymentPending zählt zu activeStatuses() (ReservationStatus Zeile 37), belegt also weiter Tische und Kapazität.

**Auslöser:** Gast klickt „Jetzt bezahlen", landet bei Stripe, schliesst den Tab ohne zu zahlen. Die Anzahlungsregel steht auf der Vorgabe (kein Auto-Storno). Danach: Stornieren geht nie mehr (Fehlermeldung), Verfall greift nie, der Tisch bleibt bis zum Termin gesperrt, und der Gast bekommt für eine Buchung, die er loswerden wollte, am Ende noch die Erinnerungsmail.

**Vorschlag:** Beim Ereignis 'checkout.session.expired' und beim Abbruch-Rücksprung (cancel_url) payment_status wieder auf 'required' setzen; zusätzlich in cancel() nur dann blockieren, wenn tatsächlich ein PaymentIntent mit status='pending' und frischem updated_at existiert - dieselbe Kulanzprüfung, die ExpireUnpaidReservations in Zeile 138-144 schon benutzt.

### 22. Salon: Mitarbeiterin kann doppelt gebucht werden - die Sperre schuetzt den Salonpfad nicht

`app/Http/Controllers/Public/PublicBookingController.php:594`

storeSalon() prueft die Verfuegbarkeit der Mitarbeiterin VOR dem Aufruf von create(): `isStaffAvailableForServices()` (Zeile 539) bzw. `firstAvailableStaffForServices()` (Zeile 543). Beide lesen nur (SalonAvailabilityService::hasConflict, Zeile 418-424, ohne Sperre). Danach ruft der Controller `create()` mit `'skip_availability_check' => true` (Zeile 594). In create() wird zwar `lockSlot()` gesetzt (ReservationLifecycleService.php:67), aber wegen skip=true wird danach NICHTS mehr geprueft - der gesamte Block ab Zeile 69 entfaellt, und create() beruehrt staff_member_id ueberhaupt nicht. Die Sperre serialisiert also zwei Einfuegungen, verhindert aber nicht, dass beide auf einem veralteten Pruefergebnis beruhen. Der Kommentar in ReservationLifecycleService.php:61-66 behauptet ausdruecklich, die Sperre schuetze den Salonpfad davor, "dieselbe Person doppelt zu belegen" - das kann sie an dieser Stelle nicht leisten. Identisch im Adminpfad: ReservationBookController.php:418 prueft, Zeile 432 setzt skip=true.

**Auslöser:** Zwei Gaeste oeffnen gleichzeitig denselben freien Termin bei derselben Stylistin und senden das Formular im selben Sekundenfenster ab. Beide Anfragen laufen durch isStaffAvailableForServices (noch frei), danach nacheinander durch create() - zwei Termine, dieselbe Mitarbeiterin, dieselbe Uhrzeit.

**Vorschlag:** Die Personenpruefung nach INNEN verlegen: in create() bei gesetztem staff_member_id nach lockSlot() erneut `canStaffTake($staff, $duration, $startUtc, $location)` aufrufen und bei Fehlschlag eine ValidationException werfen - so wie es reschedule() bereits tut (Zeile 490).

### 23. Fristablauf überschreibt eine soeben bezahlte und bestätigte Buchung – Prüfung ohne Sperre

`app/Jobs/ExpireUnpaidReservations.php:154`

expire() sichert sich nur mit `$reservation->refresh()` (Z. 154) und `if ($reservation->status !== ReservationStatus::PaymentPending) continue;` (Z. 156) ab. Das ist ein reines Lesen-Prüfen-Schreiben ohne `lockForUpdate` und ohne bedingtes UPDATE. Der anschliessende `DB::transaction` (Z. 160) schreibt bedingungslos: `$reservation->update(['payment_status' => 'expired'])` und `transition(..., Expired, ...)`. `transition()` selbst liest in ReservationLifecycleService.php:264 `$from = $reservation->status` aus dem Speicherobjekt (also vom `refresh()` von vorhin) und schreibt in ReservationLifecycleService.php:298 ebenfalls ohne `where('status', ...)`.

Der Gegenspieler ist ein anderer Prozess: PaymentController::handlePaid läuft in php-fpm und sichert per Compare-and-Swap ausschliesslich die **PaymentIntent**-Zeile (PaymentController.php:368-375), nicht die Reservierung. Auf die Reservierung schreibt er danach ungeschützt (PaymentController.php:388-391). Beide Schreiber beanspruchen also nie dasselbe Objekt.

Unter PostgreSQL/READ COMMITTED gewinnt der spätere Schreiber. Kommt der Zahlungseingang zwischen `refresh()` und dem UPDATE des Jobs, steht am Ende: status=expired, payment_status=expired, Tisch frei – bei bezahlter Anzahlung. Der Gast bekommt beide Mails, `reservation_confirmed` von handlePaid und `payment_expired` aus Z. 176. Die Rückweg-Erstattung greift nicht, weil handlePaid den Intent bereits beansprucht hat und der Zweig `! $reservation->status->isActive()` (PaymentController.php:392) zu diesem Zeitpunkt noch nicht zutraf. Geld beim Betrieb, kein Tisch beim Gast, keine Erstattung.

Dieselbe ungesicherte Stelle steht in app/Jobs/ExpireUnconfirmedReservations.php:69-84 (refresh + in_array + transition). Wie es richtig geht, zeigt derselbe Code an zwei anderen Stellen: RefundService.php:196-204 (CAS) und WaitlistService.php:133 (lockForUpdate).

**Auslöser:** Buchung mit Anzahlung, payment_due_at 19:00. Gast startet den Checkout um 18:58, der Anbieter meldet den Erfolg um 19:00:03. Der Lauf um 19:00 hat die Zeile um 19:00:01 geladen und refreshed, der Webhook committet um 19:00:03 Confirmed/paid, das UPDATE des Jobs committet um 19:00:04 und setzt expired zurück.

**Vorschlag:** Die Zeile im selben Transaktionsblock beanspruchen statt vorher zu prüfen: entweder `Reservation::whereKey($id)->where('status','payment_pending')->update([...])` und nur bei Rückgabewert 1 weitermachen, oder in `expire()` und in `transition()` ein `lockForUpdate()` auf die Reservierung. `transition()` sollte den Ausgangsstatus innerhalb der Transaktion frisch lesen, nicht aus dem übergebenen Modell.

### 24. Erstattung bleibt für immer in 'processing' hängen – kein Lauf und kein Knopf erreicht sie noch

`app/Services/RefundService.php:196`

process() beansprucht die Erstattung per CAS und schreibt `status = 'processing'` (Z. 196-199) in einer eigenen, sofort committeten Anweisung – es gibt keine umschliessende Transaktion. Erst danach folgt der Anbieteraufruf im try-Block (Z. 214-235).

Stirbt der Prozess zwischen Z. 199 und Z. 226, bleibt 'processing' stehen. Danach erreicht die Zeile niemand mehr:
- processDue() wählt nur `where('status','approved')` (Z. 354).
- Der CAS in process() beansprucht nur `where('status','approved')` (Z. 198).
- RefundController::retry() bricht mit 422 ab, wenn der Status nicht 'failed' ist (RefundController.php:55).
- In der Oberfläche steht die Zeile dauerhaft blau als „in Bearbeitung" (resources/views/admin/refunds/index.blade.php:16), ohne Aktion.
Ein `grep -rn "'processing'" app/` findet ausser RefundService.php keine weitere Stelle, die diesen Status je wieder verlässt.

Der Kommentar in Z. 208-213 sichert ausdrücklich gegen **Ausnahmen** ab. Gegen den Tod des Prozesses hilft der try-Block nicht: `queue:work` läuft ohne `--timeout` (docker-compose.yml:59), also mit dem Standardwert 60 Sekunden – der Worker schiesst einen länger laufenden Job per SIGALRM ab. Dazu kommen OOM-Kill und Container-Neustart beim Deploy. Ergebnis: Der Gast bekommt sein Geld nie, der Betrieb erfährt es nicht, und der Datensatz behauptet, es laufe.

**Auslöser:** processDue() nimmt eine freigegebene Erstattung, setzt 'processing' und ruft Stripe. Der Anbieter braucht 65 Sekunden oder der Container wird währenddessen neu gestartet. Der Job wird nach retry_after=90 s erneut zugestellt, findet die Zeile aber nicht mehr in 'approved' und läuft leer durch. Die Erstattung steht ab jetzt bis in alle Ewigkeit auf 'processing'.

**Vorschlag:** Beim Beanspruchen einen Zeitstempel mitschreiben (`claimed_at`) und in processDue() zusätzlich Zeilen aufgreifen, die länger als z. B. 15 Minuten in 'processing' stehen – dort aber zwingend erst beim Anbieter nachfragen, ob die Erstattung doch lief. Mindestens: retry() auch für 'processing' zulassen und die Oberfläche solche Zeilen als hängend kennzeichnen.

### 25. Slots nach Mitternacht werden unter dem falschen Datum angeboten und buchen die Nacht davor

`app/Services/ReservationAvailabilityService.php:58`

slotsFor() liefert je Slot nur `'time' => $startLocal->format('H:i')` (Zeile 58). Bei einem Fenster ueber Mitternacht (TimeSlotService::makeWindow schiebt closes bewusst auf den Folgetag, Zeile 83-85) erzeugt slotStarts fuer Tag D auch Starts wie D+1 00:30 - die tauchen in der Liste als "00:30" auf. PublicBookingController::slots (Zeile 194) wirft `start_utc` weg und gibt nur die Uhrzeiten zurueck; das Formular sendet das gewaehlte Datum D plus "00:30", und store() baut daraus `CarbonImmutable::parse($validated['date'].' '.$validated['time'])` (PublicBookingController.php:436) = D 00:30, also 24 Stunden VOR dem angeklickten Slot. checkExact akzeptiert das, weil es bewusst auch das Raster des Vortags mitnimmt (ReservationAvailabilityService.php:86-89) und D 00:30 im Fenster von D-1 liegt. Folge: Die Buchung landet in der Nacht D-1 -> D. Liegt D-1 in der Vergangenheit, kommt stattdessen 'lead_time' zurueck - der Gast bekommt "zu kurzfristig" fuer einen Termin, den die Seite ihm gerade angeboten hat. Dieselbe Verwechslung steckt in nextSlots() (Zeile 167, `date` = $day) und in alternatives() (Zeile 113, sortiert "00:30" als Tagesanfang statt als Nacht). Auch die Anzeige belegt es: booking.blade.php:711 gruppiert nach `parseInt(t.split(':')[0])`, "00:30" landet unter "Vormittag" ganz oben.

**Auslöser:** Standort mit Oeffnungszeit 18:00-02:00. Gast waehlt im Buchungswidget den 20.09., klickt den angebotenen Slot "00:30" und bucht. Die Reservierung steht am 20.09. 00:30, also in der Nacht vom 19. auf den 20. - nicht in der vom 20. auf den 21., die der Gast gemeint hat.

**Vorschlag:** In der Slot-Antwort das tatsaechliche lokale Datum je Slot mitliefern (aus start_utc ableiten) und im Formular ein verstecktes Feld mit diesem Datum bzw. direkt start_utc uebertragen; store() darf dann nicht mehr aus Anzeige-Datum + Uhrzeit zusammensetzen.

### 26. reschedule() laeuft ohne lockSlot - zwei Umbuchungen belegen denselben Tisch

`app/Services/ReservationLifecycleService.php:470`

reschedule() oeffnet eine Transaktion (Zeile 470), ruft darin checkExact() bzw. canStaffTake() und schreibt anschliessend `tables()->sync($tableIds)` (Zeile 546) - aber ohne den `lockSlot()`-Aufruf, den create() an dieser Stelle bewusst setzt (Zeile 67, mit ausfuehrlicher Begruendung). Ohne pg_advisory_xact_lock serialisiert nichts die Pruefung gegen den Schreibvorgang: zwei gleichzeitige Umbuchungen auf dieselbe Uhrzeit finden beide denselben freien Tisch (TableAssignmentService::findTables liest nur), und beide syncen ihn. Genauso wirkungslos ist die Sperre in create() gegen eine zeitgleiche Umbuchung, weil der Umbuchungspfad den Schluessel nie anfasst. Das ist exakt die Bugklasse, die der Projekt-Skill unter "Genau einmal braucht eine Sperre" fuehrt.

**Auslöser:** Zwei Gaeste buchen ueber ihren Aenderungslink gleichzeitig auf denselben Slot um, an dem nur noch ein Tisch frei ist. Beide bekommen "Ihr Termin wurde umgebucht"; in reservation_tables steht derselbe Tisch fuer beide Reservierungen.

**Vorschlag:** lockSlot($location, $newStartLocal) als erste Anweisung in die Transaktion von reschedule() aufnehmen (und beim Umzug ueber einen Tageswechsel auch fuer den alten Betriebstag), analog zu create().

### 27. Manuell gewaehlter Tisch umgeht Vorlaufzeit, Vorausbuchungsgrenze und Platzlimit

`app/Services/ReservationLifecycleService.php:85`

Im Zweig fuer vorgegebene table_ids (Zeile 85-106) laufen nur zwei Pruefungen: `bookingBlockReason()` (Oeffnungs-/Sonderzeiten und Sperrzeiten) und `busyTableIds()` (Kollision). Alles, was in checkSlotDetailed haengt, entfaellt: min_lead_minutes (ReservationAvailabilityService.php:269), max_advance_days (Zeile 273) und die Platzobergrenze max_covers_per_slot im Modus 'person'/'hybrid' (Zeile 290-304). Der oeffentliche Buchungspfad erlaubt dem Gast aber genau das: PublicBookingController::store validiert `'table_id' => ['nullable','integer']` und setzt daraus $tableIds (Zeile 437-457) - und zwar ohne die Einstellung `public_floorplan_enabled` abzufragen, die nur den JSON-Endpunkt schuetzt (Zeile 317). Damit kann jeder Gast per POST einen table_id mitsenden und fuer diesen Tisch die Zeitgrenzen und das Platzlimit des Standorts aushebeln. Da `'date' => ['after_or_equal:today']` nur den Tag prueft, ist auch eine Uhrzeit von heute Mittag um 20 Uhr noch buchbar, solange sie auf dem Raster liegt.

**Auslöser:** Standort mit min_lead_minutes = 120 und capacity_mode 'hybrid'. POST auf /book/{tenant}/{location} mit date=heute, time=(naechster Rasterslot in 15 Minuten), party_size=2 und table_id=(ein online_bookable Tisch) -> Reservierung wird angelegt, obwohl die Vorlaufzeit nicht eingehalten ist und das Platzkontingent des Slots bereits ausgeschoepft sein kann.

**Vorschlag:** Im manuellen Zweig zusaetzlich Vorlauf, Vorausbuchungsgrenze und - im Modus person/hybrid - die Deckelung ueber max_covers_per_slot pruefen (am einfachsten: checkExact mit einer Option 'fixed_table_ids' aufrufen, statt die Pruefungen zu duplizieren). Ausserdem im Controller table_id nur akzeptieren, wenn public_floorplan_enabled gesetzt ist.

### 28. Warteliste: der Aufräumlauf setzt einen bereits angenommenen Eintrag zurück auf „wartet"

`app/Services/WaitlistService.php:180`

expireStale() setzt in Z. 180 den Eintrag zu jedem abgelaufenen Angebot bedingungslos zurück: `$offer->entry()->withoutGlobalScopes()->first()?->update(['status' => 'waiting'])`. Der aktuelle Status des Eintrags wird nicht geprüft.

Mehrere gleichzeitig offene Angebote pro Eintrag sind erreichbar: WaitlistAdminController::offer (Z. 66-88) legt bei jedem Aufruf über WaitlistService::offer() ein neues WaitlistOffer mit status='open' an, ohne bestehende offene Angebote zu schliessen oder auch nur zu prüfen.

Dadurch überschreibt der alle zehn Minuten laufende Aufräumlauf (routes/console.php:18) den Status 'accepted', den acceptOffer() in Z. 152 zusammen mit `reservation_id` gesetzt hat. Der Eintrag steht danach wieder als wartend in der Liste – mit hinterlegter Reservierung. Wird er ein zweites Mal bedient, blockiert derselbe Gast zwei Tische. declineOffer() (Z. 165) hat denselben ungeprüften Rücksetzer.

**Auslöser:** Mitarbeiter schickt um 10:00 ein Angebot (gültig 60 Min). Um 11:05 ist es abgelaufen, der Aufräumlauf war aber noch nicht dran. Um 11:06 schickt der Mitarbeiter ein zweites Angebot, der Gast nimmt um 11:08 an – Eintrag 'accepted', Reservierung steht. Um 11:10 läuft expireStale, findet Angebot 1 (noch 'open', abgelaufen), setzt es auf 'expired' und den Eintrag zurück auf 'waiting'.

**Vorschlag:** Den Rücksetzer an den Status binden: nur zurück auf 'waiting', wenn der Eintrag noch 'offered' ist und kein anderes offenes Angebot mehr existiert – z. B. `WaitlistEntry::whereKey($id)->where('status','offered')->update(...)`. Zusätzlich in WaitlistService::offer() bestehende offene Angebote desselben Eintrags schliessen.

### 29. Scheduler-Schleife mit `sleep 60` überspringt ganze Minuten – tägliche Läufe fallen still aus

`docker-compose.yml:78`

Der Scheduler-Container läuft als `sh -c "while true; do php artisan schedule:run ...; sleep 60; done"`. Die Schleifendauer ist damit 60 Sekunden **plus** der Laufzeit von `schedule:run`, also immer > 60 s. Der Aufrufzeitpunkt wandert pro Durchlauf nach hinten; sobald die Summe der Verschiebung 60 Sekunden erreicht, wird eine Minute nie abgetastet.

`schedule:run` prüft aber genau die aktuelle Minute gegen den Cron-Ausdruck. Vier der neun Einträge in routes/console.php hängen an genau einer Minute pro Tag: `dailyAt('03:30')` (RunRetentionPolicies, Z. 19), `dailyAt('03:45')` (FeedbackRequest::pruneUnanswered, Z. 20), `dailyAt('08:00')` (SendTrialExpiryWarnings, Z. 21), `dailyAt('09:00')` (SendMarketingCampaigns, Z. 23). Wird deren Minute übersprungen, fällt der Lauf für diesen Tag komplett aus – ohne Fehler, ohne Eintrag in failed_jobs, ohne Nachholen.

Die Drift wird zusätzlich beschleunigt, weil zwei Aufgaben als `Schedule::call` **im Scheduler-Prozess selbst** laufen (routes/console.php:18 und 20): WaitlistService::expireStale() lädt in Z. 173-176 alle offenen abgelaufenen Angebote ohne Obergrenze, FeedbackRequest::pruneUnanswered() löscht ohne chunk/limit. Deren Laufzeit geht eins zu eins in die Schleifendauer ein.

Besonders folgenreich: der 03:30-Ausfall betrifft die DSGVO-Aufbewahrungsfrist (Gäste werden an dem Tag nicht anonymisiert) und der 09:00-Ausfall die Gastkampagnen, deren Tagesfenster sich nicht nachholen lässt (siehe eigener Befund zu MarketingCampaignService).

**Auslöser:** Container läuft seit dem letzten Deploy. `schedule:run` braucht mit Cache und Datenbankverbindung ~1,5 s pro Durchlauf, expireStale gelegentlich mehr. Nach etwa 40 Durchläufen liegt der Aufruf eine ganze Minute später als am Start – irgendwann fallen die Aufrufe auf 03:29:58 und 03:31:01. Die Minute 03:30 hat keinen schedule:run gesehen, RunRetentionPolicies läuft an diesem Tag nicht.

**Vorschlag:** Statt der Schleife entweder `php artisan schedule:work` (Laravels eigene Dauerschleife, die auf die Minutengrenze ausrichtet) oder ein echtes `* * * * *`-Cron im Container. Zusätzlich die beiden `Schedule::call`-Aufgaben als Job in die Queue schieben, damit ihre Laufzeit den Scheduler nicht bremst.


## Mittel

### 30. Kontolöschung prüft nur den aktuellen Betrieb – anderer Betrieb bleibt ohne Inhaber zurück

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Http/Controllers/Admin/AccountController.php:130`

Die Schutzabfrage in `destroy()` zählt nur die Inhaber des GERADE aktiven Mandanten: `$tenant = $this->context->tenant()` (Zeile 128), dann `$tenant->memberships()->where('role','tenant_owner')->count() <= 1`. Ein Benutzer kann aber in mehreren Betrieben Mitglied sein und zwischen ihnen wechseln (routes/web.php:193 `switch-tenant`, ResolveTenantContext.php:32 `current_tenant_id`). Ist er in Betrieb A nur Mitarbeiter und in Betrieb B ALLEINIGER Inhaber, greift die Prüfung nicht: er löscht sein Konto, `$user->delete()` (Zeile 142) räumt über die Kaskade auf `tenant_users` (2026_06_12_000010:65 `cascadeOnDelete`) auch seine Inhaberschaft in B ab. Betrieb B hat danach keinen Inhaber mehr – niemand kann dort exportieren (Zeile 56), importieren (86) oder den Betrieb löschen (160), alle drei Routen verlangen die Rolle `tenant_owner`. Dass der Fall bekannt ist, zeigt `UserManagementController::deleteUser()` (Zeile 168): dort wird vor dem Löschen ausdrücklich mit `$user->tenants()->where('tenants.id','!=',$tenant->id)->exists()` über alle Betriebe geprüft. In `destroy()` fehlt genau dieser Schritt. Zweiter Punkt an derselben Stelle: die Prüfung wird ganz übersprungen, wenn `$tenant === null` ist – anders als export/import/destroyTenant hat `destroy()` kein `abort_if($tenant === null, 404)`.

**Auslöser:** Benutzer ist Inhaber von Betrieb B und zusätzlich als Host in Betrieb A eingetragen. Er wechselt nach A (current_tenant_id = A), geht auf /admin/account, tippt „LÖSCHEN" und bestätigt. Betrieb B verliert lautlos seinen einzigen Inhaber.

**Vorschlag:** Wie in `deleteUser()` über ALLE Mitgliedschaften prüfen: für jede Inhaber-Mitgliedschaft des Benutzers zählen, ob der jeweilige Betrieb noch einen weiteren Inhaber hat; sonst mit Fehlermeldung abbrechen und die betroffenen Betriebe benennen.

### 31. Fehlgeschlagener Import zeigt die rohe SQL-Fehlermeldung samt Werten im Formular

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Http/Controllers/Admin/AccountController.php:105`

`catch (\RuntimeException $e) { return back()->withErrors(['file' => $e->getMessage()]); }` – gedacht ist der Block für die drei sauberen Meldungen aus `AccountImportService::assertValid()` (Zeile 184/187/190). Er fängt aber deutlich mehr: `Illuminate\Database\QueryException` erbt von `\PDOException`, und `\PDOException` erbt von `\RuntimeException`. Jeder Datenbankfehler während des Imports – Fremdschlüsselverletzung, NOT-NULL-Verstoß, Längenüberschreitung – landet damit in diesem Zweig, und `QueryException::getMessage()` enthält den vollständigen SQL-Text mitsamt allen gebundenen Werten. Der Betriebsinhaber bekommt also im Formular eine Zeile wie `SQLSTATE[23503]: … (Connection: pgsql, SQL: insert into "guests" (first_name, last_name, email, phone, …) values (…))` – interne Schemadetails und die Klardaten aus der Importdatei in einer Weboberfläche. Praktisch relevant, weil genau die oben beschriebene preferred_-Lücke diesen Pfad auslöst.

**Auslöser:** Eine Exportdatei mit einem Gast, dessen `preferred_table_id` im Ziel nicht existiert, wird hochgeladen. Statt „Der Import ist fehlgeschlagen" erscheint der komplette INSERT samt Gastdaten in der roten Fehlerbox.

**Vorschlag:** Zwei getrennte catch-Blöcke: `RuntimeException` nur noch als eigene Ausnahmeklasse (z. B. `ImportFormatException`) fangen und anzeigen, `QueryException`/`Throwable` protokollieren und dem Benutzer eine neutrale Meldung mit Vorgangs-ID zeigen.

### 32. CSV-Export schreibt Gasteingaben ungeschützt – Formeln laufen in Excel

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Http/Controllers/Admin/GuestController.php:184`

`fputcsv($out, [$g->first_name, $g->last_name, $g->email, $g->phone, …], ';')` schreibt die Felder unverändert. Vor- und Nachname stammen aus dem öffentlichen Buchungsformular (PublicBookingController, validiert nur auf `string`/`max`). Beginnt ein Wert mit `=`, `+`, `-` oder `@`, wertet Excel/LibreOffice ihn beim Öffnen als Formel aus – `=HYPERLINK(...)`, `=cmd|…` oder eine Formel, die andere Zellen ausliest. Der Betriebsinhaber öffnet die Datei erwartungsgemäß direkt aus dem Download. Dieselbe Stelle noch einmal im Teilnehmerexport für Events (app/Http/Controllers/Admin/EventAdminController.php:270), dort ebenfalls mit Name, E-Mail, Telefon und Notiz aus der Gastbuchung.

**Auslöser:** Jemand bucht online unter dem Nachnamen `=HYPERLINK("http://…";"Klicken")`. Ein Mitarbeiter lädt unter Gäste → Export die gaeste.csv und öffnet sie in Excel; die Zelle zeigt einen anklickbaren Link statt des Namens.

**Vorschlag:** Werte vor `fputcsv` entschärfen: beginnt ein Feld mit einem der Zeichen `= + - @ \t \r`, ein einfaches Anführungszeichen voranstellen. Als kleine gemeinsame Hilfsfunktion, damit beide Exportstellen sie nutzen.

### 33. Abgewählter Newsletter wird im Restaurantpfad stillschweigend verschluckt

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Http/Controllers/Public/PublicBookingController.php:477`

store() übergibt die Einwilligungen als array_filter(['privacy' => true, 'newsletter' => (bool) ($validated['newsletter'] ?? false)]). array_filter ohne Callback wirft alle falsy Werte weg – ist der Haken NICHT gesetzt, verschwindet der Schlüssel 'newsletter' komplett aus dem Array. create() iteriert in ReservationLifecycleService.php:120-122 nur über die vorhandenen Schlüssel, also wird GuestProfileService::recordConsent($guest, 'newsletter', false, ...) nie aufgerufen. Genau dieser Aufruf setzt aber marketing_consent auf false und stösst SyncNewsletterSubscriber mit granted=false an (GuestProfileService.php:65-75). Ein Gast, der beim ersten Mal zugestimmt hat und beim zweiten Mal den Haken bewusst weglässt, bleibt damit im Verteiler und in der externen Newsletter-Anbindung – der Widerruf landet weder im Profil noch in der Einwilligungshistorie (Art. 7 Abs. 3 DSGVO verlangt genau diese Nachweisbarkeit). Der Salonpfad in derselben Datei macht es richtig: Zeile 596-599 übergibt 'newsletter' => false ohne array_filter. Dieselbe array_filter-Falle steht in PublicEventController.php:93-96.

**Auslöser:** Mit derselben E-Mail zweimal buchen: erst mit gesetztem Newsletter-Haken, dann ohne. guests.marketing_consent steht danach weiter auf true, in guest_consents fehlt jeder Eintrag mit granted=false.

**Vorschlag:** array_filter an beiden Stellen entfernen und das Array unverändert übergeben – genau wie im Salonpfad. Falls 'privacy' bewusst nur bei true mitgeschickt werden soll, den Schlüssel gezielt setzen statt pauschal zu filtern.

### 34. Stornogrund ungeprüft: über 255 Zeichen bricht die Stornierung mit einer 500er-Seite ab

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Http/Controllers/Public/PublicBookingController.php:688`

cancel() reicht $request->input('reason') ohne jede Validierung an lifecycle->transition() weiter. Alle anderen Gasteingaben in dieser Datei haben eine Obergrenze (name max:120, note max:1000, allergies max:500). transition() schreibt den Wert innerhalb der DB::transaction in ReservationStatusHistory::create(['reason' => $reason]) (ReservationLifecycleService.php:300-309); die Spalte ist über $table->string('reason') als varchar(255) angelegt (database/migrations/2026_06_12_000050_create_reservation_tables.php:71). PostgreSQL bricht bei zu langen Werten mit SQLSTATE 22001 ab – MySQL/SQLite würden kürzen, der Fehler ist also produktionsspezifisch. Die QueryException rollt die Transaktion zurück: Der Gast sieht eine 500er-Seite, die Reservierung ist NICHT storniert, der Betrieb hält weiter einen Tisch frei, der nicht kommt. Das Eingabefeld in manage.blade.php:111 hat kein maxlength, ein längerer Erklärtext ist also der normale Weg dorthin, kein Angriff. Zweiter Weg in denselben Absturz: reason[]=x macht aus input() ein Array, das an den ?string-Parameter von transition() geht – TypeError.

**Auslöser:** Eigene stornierbare Reservierung öffnen, ins Feld 'Grund für die Stornierung' einen Text mit mehr als 255 Zeichen einfügen und absenden. Auf PostgreSQL: 500, Reservierung bleibt aktiv.

**Vorschlag:** In cancel() validieren: $request->validate(['reason' => ['nullable', 'string', 'max:255']]) und den validierten Wert übergeben. Zusätzlich maxlength="255" ans Eingabefeld in manage.blade.php.

### 35. Fehlgeschlagene Wartelisten-Annahme bleibt für den Gast unsichtbar

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Http/Controllers/Public/WaitlistResponseController.php:417`

respond() ruft bei 'accept' waitlist->acceptOffer() auf, ohne die ValidationException abzufangen. acceptOffer() wirft sie an zwei Stellen selbst (WaitlistService.php:125 und 135) und indirekt über lifecycle->create(), das bei belegtem Slot mit 'start_at' abbricht (ReservationLifecycleService.php:80-83). Laravel macht daraus auf der POST-Route einen Redirect zurück auf die GET-Seite mit den Fehlern in der Session – aber resources/views/public/waitlist-offer.blade.php rendert $errors nirgends (die ganze Datei hat 27 Zeilen, keine davon berührt $errors oder session). Weil die Transaktion zurückrollt, steht das Angebot weiter auf 'open': Der Gast sieht nach dem Klick auf 'Annehmen' exakt dieselbe Seite mit demselben Button und erfährt nie, ob er den Tisch bekommen hat. Der Fall ist keine Randerscheinung: acceptOffer legt die Reservierung mit source 'online' an, also läuft checkExact mit online=true – ein Angebot für 'in 30 Minuten' scheitert bei min_lead_minutes=60 zwingend, und ein Angebot, das zwischen Mailversand und Klick durch eine andere Buchung belegt wurde, ebenfalls.

**Auslöser:** Wartelistenangebot für einen Zeitpunkt innerhalb der Vorlaufzeit verschicken (oder den angebotenen Tisch zwischen Mail und Klick anderweitig belegen), dann im Gastlink auf 'Annehmen' klicken: Seite lädt unverändert neu, keine Meldung, kein Tisch.

**Vorschlag:** In waitlist-offer.blade.php einen @if($errors->any())-Block ergänzen und in respond() die ValidationException fangen, um eine verständliche Meldung mitzugeben ('Der Tisch ist leider gerade vergeben worden'). Bei endgültigem Scheitern das Angebot auf 'expired' setzen, damit der Gast nicht in einer Schleife klickt.

### 36. Export lässt die Leistungs-Zusammenstellung von Salonterminen weg

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Services/AccountExportService.php:102`

Die Pivot-Tabelle `reservation_service` fehlt im Export vollständig. Sie hält bei einem Salontermin alle gebuchten Leistungen in Reihenfolge samt Preis- und Dauer-Snapshot (2026_06_12_330000_add_service_combinations_and_gap_opt.php: sort_order, duration_minutes, price_minor). Geschrieben wird sie bei jeder öffentlichen Salonbuchung (PublicBookingController.php:607 `$reservation->services()->sync(...)` mit den Snapshots) und wieder gelesen für die Buchungsansicht (Zeile 723 `'serviceIds' => $reservation->services->pluck('id')`). In der Reservierungszeile steht nur `service_id`, und das ist ausdrücklich nur die ERSTE Leistung (PublicBookingController.php:587: `'service_id' => $services->first()->id, // primary service`; ReservationLifecycleService.php:143 sagt dasselbe). Der Export nimmt über `clean()` nur die Spalten der Reservierung mit, für Tische und Tags gibt es Sonderbehandlung (Zeile 174–175), für Leistungen nicht. Nach dem Umzug ist aus „Schnitt + Farbe + Tönung" ein „Schnitt" geworden – während `start_at`/`end_at` weiter die volle Dauer belegen und die vereinbarten Preise weg sind. Der Betrieb kann nicht mehr belegen, was er abgerechnet hat.

**Auslöser:** Salon exportiert sein Konto und spielt es neu ein. Ein Termin über 150 Minuten mit drei Leistungen zu 95 € erscheint danach als Termin über 150 Minuten mit einer Leistung; die Preis- und Dauer-Snapshots der beiden anderen sind fort.

**Vorschlag:** Analog zu `table_names`/`tag_names` je Reservierung ein `services` mit [service_id (Quell-ID oder Name), sort_order, duration_minutes, price_minor] exportieren und im Import über die bereits vorhandene Services-Map (`$this->mapped('services', …)`) wieder synchronisieren.

### 37. Gast-Tags gehen beim Export verloren

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Services/AccountExportService.php:98`

Tags sind polymorph an Gäste UND Reservierungen gehängt (`taggables` mit `morphs('taggable')`, 2026_06_12_000040_create_guest_tables.php; Guest::tags() app/Models/Guest.php:74, Reservation::tags() app/Models/Reservation.php:154). Der Export gibt die Tag-Definitionen mit (Zeile 118) und hängt sie für Reservierungen ausdrücklich als `tag_names` an (Zeile 175) – bei Gästen fehlt dieser Schritt: Zeile 98 ruft nur `$this->rows(...)`, also `clean()` = reine Spaltenwerte. Der Import kann daher nichts wiederherstellen (Zeile 129 setzt keine Tags). Genau die Markierungen, mit denen ein Betrieb seine Gäste steuert (Stammgast, Allergiker, Hausverbot, Rechnungskunde), sind nach dem Umzug fort, während die Tags selbst als leere Hüllen dastehen. Dass es sich um ein Versehen und nicht um eine Auslassung handelt, zeigt der Merge-Pfad, der Gast-Tags ausdrücklich mitführt (GuestMergeService.php:129 `$keep->tags()->syncWithoutDetaching(...)`).

**Auslöser:** Betrieb hat 40 Gäste mit dem Tag „Hausverbot" markiert, exportiert und importiert das Konto. Danach trägt kein Gast mehr einen Tag; die Sperrliste ist stillschweigend leer.

**Vorschlag:** In `export()` die Gästezeilen wie die Reservierungen behandeln: eigene Methode mit `->with('tags:id,name')` und `$row['tag_names']`. Im Import in der `guests`-Sektion die Tags über die vorhandene Tag-Auflösung (wie AccountImportService.php:332–335) synchronisieren.

### 38. Import verwirft created_at – Buchungszeitpunkt und Statusverlauf kippen auf den Importtag

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Services/AccountImportService.php:135`

`created_at` und `updated_at` stehen in der DROP-Liste (Zeile 58), also bekommt jede importierte Zeile den Importzeitpunkt. Bei `reservation_status_history` (Zeile 135) ist `created_at` das EINZIGE Zeitmerkmal der Tabelle (2026_06_12_000050_create_reservation_tables.php: from_status, to_status, user_id, actor, reason, note, timestamps – kein eigenes `changed_at`). Nach dem Import tragen alle Einträge einer Reservierung dieselbe Sekunde; die Anzeige sortiert danach (resources/views/admin/reservations/show.blade.php:126 `sortByDesc('created_at')`), also ist die Reihenfolge des Verlaufs anschließend beliebig – aus „angefragt → bestätigt → storniert" kann „storniert → angefragt → bestätigt" werden. Dasselbe bei den Reservierungen selbst: `localCreatedAt()` (app/Models/Reservation.php:187) ist der Buchungszeitpunkt und wird als „Gebucht am" angezeigt (show.blade.php:27), in der Liste (index.blade.php:211) und im CSV-Export (ReservationBookController.php:708) – nach dem Import steht dort für jede historische Buchung das Importdatum. Nebenwirkung bei Gästen: `runRetention()` (GuestPrivacyService.php:191) fällt für Gäste ohne `last_visit_at` auf `created_at` zurück – deren Aufbewahrungsuhr startet beim Import wieder bei null. Die Datenschutzerklärung sagt in §14a (resources/legal/datenschutz.md:257) ausdrücklich zu, dass „Reservierung samt Verlauf" mitwandert.

**Auslöser:** Betrieb spielt seinen Export auf dem neuen Server ein und öffnet eine Reservierung von vor drei Monaten: „Gebucht am" zeigt den heutigen Tag, der Statusverlauf listet alle Schritte mit identischem Zeitstempel in zufälliger Reihenfolge.

**Vorschlag:** `created_at` (und `updated_at`) aus der DROP-Liste nehmen und die Werte aus der Datei übernehmen, mit Rückfall auf `now()`, wenn sie fehlen. Die Zeitstempel sind fachliche Daten, keine internen IDs – anders als `id`/`tenant_id` gibt es keinen Grund, sie zu verwerfen.

### 39. Anonymisierung räumt Gastnotizen ab, lässt aber interne Notizen an der Reservierung stehen

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Services/GuestPrivacyService.php:93`

Zeile 93 löscht alle `guest_notes` des Gastes, Zeile 85–91 leert an den Reservierungen `guest_name_snapshot`, `guest_email_snapshot`, `guest_phone_snapshot`, `guest_note` und `allergy_note`. Zwei Textfelder mit demselben Inhaltstyp bleiben unangetastet: `reservations.internal_note` (Migration 2026_06_12_000050: „staff-only", steht direkt neben den beiden geleerten Notizfeldern) und die Tabelle `reservation_notes` mit ihrem `body` – im Export als eigene Sektion geführt (AccountExportService.php:103) und über `ReservationNote` an der Reservierung sichtbar. Beides sind freie Personalvermerke ZU DIESEM GAST („Herr … reklamiert regelmäßig", „Frau …, Rollstuhl, Tisch am Eingang") und damit personenbezogene Daten desselben Betroffenen. Dass Notizen als personenbezogen behandelt werden, zeigt die Behandlung der `guest_notes` eine Zeile darüber – die Reservierungsnotizen sind bloß übersehen worden.

**Auslöser:** Gast verlangt Löschung. Nach dem Anonymisieren die Reservierung im Reservierungsbuch öffnen: unter „Interne Notiz" und im Notizfaden steht weiterhin der volle Name samt Vermerk, während der Gastname darüber „Anonymisierter Gast #123" lautet.

**Vorschlag:** In derselben Transaktion `internal_note` in die Update-Liste bei Zeile 85 aufnehmen und `ReservationNote::withoutGlobalScopes()->whereIn('reservation_id', …)->delete()` ergänzen – dieselbe Behandlung wie bei `guest_notes`.

### 40. Telefonnummer und Zonennamen des Betriebs gehen unescaped per innerHTML auf die Gästeseite

`C:/Users/brigh/Claude Workingdir/gastrobook/resources/views/public/booking.blade.php:746`

Die Slots-Antwort liefert bei zu grosser Gruppe $location->phone mit (PublicBookingController.php:205). Im Frontend landet der Wert roh in einer innerHTML-Zuweisung: altBox.innerHTML = ... + ': <a ... href="tel:' + data.phone.replace(/\s/g,'') + '">' + data.phone + '</a>'. phone ist im Adminbereich als ['nullable','string','max:40'] validiert (LocationController.php:59/96, SettingsController.php:856) – jedes Markup ist erlaubt, und 40 Zeichen reichen für ein lauffähiges Skript-Tag. Dasselbe Muster im Tischplan: buildZoneCards() setzt in Zeile 869-874 zone.color in ein style-Attribut und zone.name in einen Absatz, beides per Template-Literal in innerHTML, beides aus FloorZone-Daten des Betriebs. Das Skript läuft dann auf dem Origin der Anwendung, also auf derselben Domain wie /konto/{tenant} (Gastkonto-Session) und der Adminbereich – die Grenze zwischen einem Mandanten und allen anderen Nutzern, die seine öffentliche Buchungsseite ansehen, wird damit durchlässig. Alle anderen Betriebstexte auf derselben Seite gehen korrekt über Blade ({{ $location->name }}, {{ $location->public_intro }}) oder über textContent (Zeile 915, 982).

**Auslöser:** Als Mandant unter Einstellungen die Telefonnummer des Standorts auf <img src=x onerror=alert(1)> setzen. Dann als Gast auf der Buchungsseite eine Personenzahl über der buchbaren Höchstgrösse wählen – slots() liefert oversized=true samt phone, und die Zeile führt das Markup aus.

**Vorschlag:** Den Zweig wie den Rest der Datei bauen: Elemente per createElement anlegen, Text per textContent setzen und die Telefonnummer nur über setAttribute('href', 'tel:'+…) einsetzen. In buildZoneCards() zone.name per textContent und zone.color über style.background statt über einen Template-String.

### 41. Pflicht-Häkchen für die Datenschutzhinweise ohne jede erreichbare Datenschutzerklärung

`C:/Users/brigh/Claude Workingdir/gastrobook/resources/views/public/booking.blade.php:551`

Die Zustimmung ist verpflichtend ('privacy_accepted' => ['accepted'], PublicBookingController.php:421 und 519). Der Text verlinkt die Hinweise nur, wenn $tenant->privacy_url gesetzt ist, sonst steht dort blosser Fliesstext ohne Ziel. privacy_url ist aber nirgends befüllbar: Ein grep über das ganze Repository (ohne vendor) findet den Namen ausschliesslich in Tenant.php:30 als fillable, in der Migration 2026_06_12_000010_create_saas_tables.php:41 und in genau diesen vier Blade-Zeilen (booking.blade.php:187, 551, 583 und events/show.blade.php:70). Es gibt kein Formularfeld, keinen Controller, keinen Seeder, der den Wert schreibt. In der Praxis ist die Spalte also immer null, und jeder Gast muss bestätigen, etwas gelesen zu haben, das auf der Seite gar nicht erreichbar ist – bei Buchung, bei Wartelisteneintrag und bei Eventbuchung. Auf eine Plattformseite darf die Buchungsseite dafür ausdrücklich nicht verlinken, der Mandant hat also gar keinen Weg.

**Auslöser:** Beliebige Buchungsseite aufrufen: Beim Häkchen steht 'Ich habe die Datenschutzhinweise gelesen' ohne Link, weil privacy_url durch keine Oberfläche gesetzt werden kann.

**Vorschlag:** Feld für privacy_url (und imprint_url) in die Mandanteneinstellungen aufnehmen, mit ['nullable','url','max:255'] validiert. Solange nichts hinterlegt ist, die Zustimmung nicht als gelesenen Text ausgeben, sondern auf die vom Standort gepflegten Angaben verweisen – ein Pflichthaken ohne Bezugstext trägt rechtlich nicht.

### 42. Bestätigungsseite lädt ein Skript von einem fremden CDN

`C:/Users/brigh/Claude Workingdir/gastrobook/resources/views/public/confirmation.blade.php:172`

Bei confetti_on_booking (Standard true, LocationSettings.php:55) bindet die Bestätigungsseite <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"> ein. Drei Punkte: (1) Datenschutz – die IP-Adresse jedes Gastes, der gerade gebucht hat, geht ohne Einwilligung an einen Drittanbieter; das ist ein meldepflichtiger Empfänger in resources/legal/datenschutz.md, und es ist die einzige Stelle im gesamten Gästebereich, die eine externe Verbindung aufbaut (der Rest läuft über @vite). (2) Kein integrity-Attribut und kein crossorigin – wer das CDN kompromittiert, führt beliebiges JavaScript auf dem Origin der Anwendung aus, auf einer Seite, die Buchungscode und Verwaltungstoken im Link trägt (Zeile 163). (3) Wenn das CDN nicht erreichbar ist oder eine strenge CSP greift, ist der Effekt weg, ohne dass es auffällt.

**Auslöser:** Beliebige Buchung an einem Standort mit Standardeinstellungen abschliessen: Die Bestätigungsseite baut beim Laden eine Verbindung zu cdn.jsdelivr.net auf.

**Vorschlag:** canvas-confetti als npm-Abhängigkeit aufnehmen und über den Vite-Build ausliefern – so wie es das Projekt bei Schriften schon hält. Ersatzweise das Skript ganz weglassen und den Effekt mit ein paar Zeilen CSS/Canvas selbst zeichnen.

### 43. API-Token überleben Rollenentzug und lassen sich vom Inhaber nicht widerrufen

`app/Http/Controllers/Admin/ApiTokenController.php:29`

Zwei Lücken, die zusammen wirken:

1. index() listet ausschließlich die Token des angemeldeten Benutzers: `$request->user()->tokens()->get()->filter(...)` (Zeile 29-31). destroy() sucht ebenfalls nur im eigenen Bestand: `$request->user()->tokens()->findOrFail($tokenId)` (Zeile 61). Ein tenant_owner sieht die Token seiner Kollegen also nicht und kann sie über die Oberfläche auch nicht löschen — es gibt keinen anderen Weg, einen einmal ausgegebenen Token zu entziehen, außer die Mitgliedschaft komplett zu löschen.

2. Die REST-API prüft ausschließlich Token-Abilities, nie die Mandantenrolle: ReservationApiController.php:19/50/81, GuestApiController.php:16/42 und WebhookApiController.php:24/34/72 rufen alle nur `$request->user()->tokenCan(...)`. ResolveApiTenant.php:46 prüft lediglich, ob überhaupt noch eine Mitgliedschaft besteht — nicht welche. User::canInTenant() kommt auf dem API-Pfad an keiner Stelle vor.

Folge: Wird ein tenant_owner auf 'readonly' herabgestuft (oder auf einen Standort eingeschränkt), behält sein bestehender Token unverändert reservations:write, guests:read und webhooks:manage über den gesamten Mandanten hinweg — Reservierungen anlegen und stornieren (ReservationApiController::store/cancel), die komplette Gästeliste auslesen und Webhook-Endpunkte auf eine beliebige externe URL anlegen, an die ab dann jede Reservierung ausgeleitet wird. Die Herabstufung wirkt in der Oberfläche sofort und auf dem API-Weg gar nicht; der neue Inhaber kann den Token nirgends sehen.

**Auslöser:** Benutzer A (tenant_owner) legt unter /admin/api-tokens einen Token mit den Scopes reservations:write und webhooks:manage an. Benutzer B (zweiter Inhaber) stuft A über PUT /admin/users/{membership}/role auf 'readonly' herab. A ruft weiterhin POST /api/v1/reservations und POST /api/v1/webhooks auf: beides funktioniert unverändert. Unter /admin/api-tokens sieht B eine leere Liste.

**Vorschlag:** index()/destroy() über alle Token des Mandanten führen (personal_access_tokens nach tokenable_id der Mitglieder plus der 'tenant:<id>'-Ability filtern), damit api_tokens.manage auch fremde Token widerrufen kann. Zusätzlich in den API-Controllern neben tokenCan() die Mandantenrolle prüfen (z. B. reservations:write → canInTenant('reservations.create'/'reservations.cancel')), damit Rollenänderungen auch auf dem API-Weg greifen.

### 44. Abrechnungsantrag ohne Rechteprüfung: jeder Mandantenbenutzer kann die Testphase dauerhaft aufheben

`app/Http/Controllers/Admin/BillingRequestController.php:37`

POST /admin/trial/request (routes/web.php:178) hängt in der admin-Gruppe ohne jede permission-Middleware, und EnsureTrialActive lässt genau diese Route auch im gesperrten Zustand durch (EnsureTrialActive.php:41: routeIs('admin.trial.*', 'billing.confirm')). store() prüft nur, dass überhaupt ein Mandantenkontext existiert (Zeile 39) und legt dann einen BillingRequest mit frei wählbarer contact_email, Firmenanschrift, USt-ID und plan_key an (Zeile 41-60). Der Bestätigungslink geht an die im Formular angegebene Adresse.

confirm() (Zeile 74-97) ist bewusst öffentlich und setzt beim Klick:

    $billingRequest->tenant->update(['status' => 'active', 'trial_ends_at' => null]);

Damit ist die Testphase des Betriebs dauerhaft aufgehoben (EnsureTrialActive.php:33-38 greift nur bei gesetztem trial_ends_at) — ausgelöst von einem beliebigen Mitglied des Mandanten, an eine beliebige Mailadresse, ohne dass der Inhaber etwas davon mitbekommt: store() schreibt keinen Auditlog-Eintrag.

Das Recht billing.manage existiert und wird für die SEPA-Routen auch verlangt (routes/web.php:326). Für den Antrag, der die Rechnungsdaten des Betriebs an den Plattformbetreiber übermittelt und die Sperre aufhebt, wird es nicht verlangt. Die Rollen readonly, staff und host haben keinerlei Abrechnungsrechte, kommen hier aber durch.

**Auslöser:** Als Benutzer mit Rolle 'staff' oder 'readonly' im Mandanten: POST /admin/trial/request mit contact_email=<eigene Adresse>, beliebiger Anschrift und plan_key=professional. Den Link aus der eigenen Mailbox aufrufen — der Mandant steht danach auf status='active' mit trial_ends_at=null, und beim Plattformbetreiber liegt ein Abrechnungsantrag mit fremd erfundenen Firmendaten.

**Vorschlag:** Die Route hinter permission:billing.manage legen (und die Ausnahmeliste in EnsureTrialActive entsprechend auf diese Route mit Rechteprüfung ziehen). store() zusätzlich auditieren ('billing.request_created') und die contact_email gegen die Mailadresse des angemeldeten Benutzers oder die hinterlegte Betriebsadresse prüfen.

### 45. Teilerfolg beim Kündigen der Lastschrift führt in eine Sackgasse: nicht kündbar, nicht neu einrichtbar

`app/Http/Controllers/Admin/DirectDebitController.php:195`

cancel() ruft in Zeile 193 cancelSubscription() und in Zeile 195 cancelMandate(); beide Methoden benutzen ->throw() (GoCardlessService Zeilen 120-132). Schlägt der ZWEITE Aufruf fehl, fängt der catch in Zeile 197 alles ab, gibt eine Fehlermeldung zurück - und das `$profile->update([...])` in den Zeilen 203-207 wird übersprungen. Das Profil steht damit weiterhin auf gocardless_status='active' mit einer subscription_id, die bei GoCardless bereits gekündigt ist. Beim nächsten Versuch läuft cancelSubscription() gegen ein bereits gekündigtes Abo, GoCardless antwortet mit einem Fehler, ->throw() wirft, der catch greift wieder: die Kündigung lässt sich nie abschliessen. Gleichzeitig blockiert setup() in Zeile 62-64 die Neueinrichtung („Es besteht bereits ein aktives Lastschriftmandat"), weil hasActiveDirectDebit() weiterhin true meldet. Der Kunde kommt aus dem Zustand ohne Eingriff in der Datenbank nicht mehr heraus, und die Oberfläche behauptet weiter, es werde abgebucht.

**Auslöser:** Netzwerkfehler oder ein GoCardless-Fehler beim Kündigen des Mandats, nachdem das Abo bereits gekündigt wurde - oder schlicht ein Mandat, das GoCardless zu diesem Zeitpunkt nicht kündigen lässt.

**Vorschlag:** Den lokalen Zustand am erfolgreichen Teilschritt festmachen: nach erfolgreichem cancelSubscription() sofort subscription_id löschen und Status setzen, das Kündigen des Mandats in einen eigenen, wiederholbaren Schritt legen. Fehler „schon gekündigt" (HTTP 422) beider Aufrufe als Erfolg werten.

### 46. Salon-Termin am Telefon wird stillschweigend ohne Mitarbeiterin angelegt

`app/Http/Controllers/Admin/ReservationBookController.php:422`

Im Salonzweig der Adminanlage bricht der Fall mit ausdruecklich gewaehlter Person mit einer Fehlermeldung ab, wenn sie nicht verfuegbar ist (Zeile 418-420). Der Fall "egal wer" tut das nicht: `firstAvailableStaffForServices()` liefert null zurueck, wenn niemand kann (SalonAvailabilityService.php:152-154), und dieses null wandert unveraendert als `'staff_member_id' => $staff?->id` in das $salon-Array, zusammen mit `'skip_availability_check' => true` (Zeile 432). create() prueft dann nichts mehr. Der Termin entsteht ohne Zuordnung, ohne Warnung und ohne die sonst verlangte Berechtigung 'overbook.manual' (die nur der $force-Zweig in Zeile 391-394 abfragt). Er blockiert danach niemanden und taucht in keinem Mitarbeiterkalender auf.

**Auslöser:** Telefonische Terminanfrage fuer Freitag 10:00, alle Mitarbeiterinnen sind zu der Zeit belegt oder im Urlaub. Personal waehlt Leistungen aus und laesst das Mitarbeiterfeld leer -> Erfolgsmeldung "Reservierung angelegt", der Termin steht ohne Zuordnung im System und wird von niemandem bedient.

**Vorschlag:** Wenn im Zweig ohne explizite Wahl kein Staff gefunden wird, dasselbe tun wie im Zweig mit Wahl: mit einem Validierungsfehler zuruecksenden - oder die Uebernahme nur mit gesetztem $force und der Berechtigung 'overbook.manual' erlauben.

### 47. Standortschranke fällt lautlos aus, wenn kein aktiver Standort aufgelöst werden konnte

`app/Http/Controllers/Admin/ReservationBookController.php:777`

authorizeReservation() macht die gesamte Standortprüfung von einem gesetzten Kontextstandort abhängig:

    $location = $this->context->location();
    abort_if($location !== null && $reservation->location_id !== $location->id
        && ! request()->user()->canAccessLocation(...), 403);

Ist $location null, ist die Bedingung false und es wird NICHT abgebrochen — die Schranke fällt auf. Dieselbe Konstruktion steht in ReservationAttachmentController.php:98, dort mit einem Kommentar, der genau diesen Angriff beschreibt ("kommt sonst ueber die Anhang-Route an Dateien des anderen Standorts").

Der Nullfall ist erreichbar: ResolveTenantContext.php:80-88 setzt den Standort auf `$tenant->locations()->get()->first(fn ($l) => $user->canAccessLocation($tenant, $l))` — das ist null, sobald ein Mitglied all_locations = false hat und keine einzige Zeile in location_user besitzt. Genau diesen Zustand legt UserManagementController::invite an: bei all_locations = false werden nur die übermittelten location_ids eingetragen (Zeile 60-71), eine leere oder komplett gefilterte Liste wird nirgends beanstandet.

Mit location = null greift auch RequirePermission nicht als Ersatz: canInTenant() ruft canAccessLocation() nur auf, wenn $location !== null (User.php:104-106). transition() (Zeile 512) und updateParty() (Zeile 616) übergeben ebenfalls $this->context->location(), also null. Der Benutzer bekommt damit über /admin/reservations/{id} und die Folgerouten lesenden UND schreibenden Zugriff auf Reservierungen sämtlicher Standorte des Mandanten inklusive interner Notizen und der Anhang-Downloads. Die entsprechenden Prüfungen in EventAdminController.php:282 und WaitlistAdminController.php:69 sind mit `!== $this->context->locationId()` bzw. `!== $location?->id` formuliert und schlagen im Nullfall korrekt fehl — hier ist das Verhalten also uneinheitlich.

**Auslöser:** Benutzer einladen mit "nur bestimmte Standorte" und ohne angehakten Standort (POST /admin/users/invite mit all_locations=0 und leerem location_ids). Nach der Anmeldung liefert /admin/reservations 404 (abort auf location === null), aber /admin/reservations/{id} für eine Reservierung eines beliebigen Standorts des Mandanten liefert 200, ebenso /admin/reservations/{id}/attachments/{attachment} und POST /admin/reservations/{id}/transition.

**Vorschlag:** Die Prüfung schließend formulieren: `abort_if($location === null || ($reservation->location_id !== $location->id && ! $user->canAccessLocation(...)), 403)` — in ReservationBookController und ReservationAttachmentController gleichlautend. Zusätzlich in UserManagementController::invite und InvitationController::accept eine Mitgliedschaft mit all_locations = false und leerer Standortliste zurückweisen.

### 48. "Tisch teilen" rechnet kommende Reservierungen nicht mit

`app/Http/Controllers/Admin/WalkInController.php:89`

Die Restplatzrechnung fuer geteilte Tische zaehlt nur Reservierungen, die bereits laufen: `where('start_at', '<=', $nowUtc)` und `where('end_at', '>', $nowUtc)` (Zeile 89-90). Eine bestaetigte Reservierung, die in 20 Minuten an demselben Tisch beginnt, geht nicht in `$occupied` ein. Gleichzeitig setzt der Aufruf `'skip_availability_check' => $shared` (Zeile 111), womit im geteilten Fall auch die Kollisionspruefung in create() entfaellt. Der Walk-in bekommt anschliessend die volle Standarddauer (durationFor) und blockiert den Tisch ueber den Beginn der bestehenden Reservierung hinaus. Die Meldung "An diesem Tisch sind nur noch :n Plaetze frei" (Zeile 95) ist in diesem Fall schlicht falsch.

**Auslöser:** Tisch 3 (4 Plaetze) ist frei, um 19:30 liegt dort eine bestaetigte Reservierung fuer 4 Personen. Um 19:10 setzt das Personal einen Walk-in fuer 2 Personen mit "Tisch teilen" auf Tisch 3. Die Anzeige meldet 4 freie Plaetze, der Walk-in laeuft bis 21:10 - der reservierte Gast steht um 19:30 vor einem belegten Tisch.

**Vorschlag:** In der Belegungsrechnung das gesamte geplante Zeitfenster des Walk-ins betrachten (start_at < $ende und end_at > $nowUtc statt nur den Ist-Zeitpunkt) und die naechste beginnende Reservierung im Dialog anzeigen bzw. die Dauer bis dahin kappen.

### 49. GoCardless-Webhook markiert das Ereignis als erledigt, bevor es verarbeitet ist

`app/Http/Controllers/GoCardlessWebhookController.php:67`

markSeen() schreibt die Ereigniskennung in Zeile 67 in gocardless_webhook_events und gibt true zurück; erst danach läuft handleEvent() (Zeile 43). Der Insert steht in keiner Transaktion, die mit der Verarbeitung zusammen zurückrollen könnte. Wirft handleEvent() eine Ausnahme, ist die Kennung trotzdem persistiert: die Wiederholung durch GoCardless landet in Zeile 70-72 im catch und wird als „already processed" verworfen - das Ereignis ist endgültig weg. Zwei konkrete Wurfstellen: `$profile->update($statusUpdate)` in Zeile 122 und `Mail::to($to)->sendNow(...)` in Zeile 141, das laut Kommentar bewusst synchron läuft und damit jeden SMTP-Fehler direkt in den Anfrageverlauf trägt. Zusätzlich bricht die Ausnahme die foreach-Schleife in Zeile 42 ab, sodass die restlichen Ereignisse desselben Pakets ebenfalls nicht verarbeitet werden.

**Auslöser:** SMTP-Server nicht erreichbar, während ein 'payments/failed'-Ereignis eintrifft. Der Mandant wird nie auf 'past_due' gesetzt bzw. niemand erfährt vom fehlgeschlagenen Einzug, und die Wiederholung von GoCardless wird stumm verworfen.

**Vorschlag:** Erst verarbeiten, dann markieren - oder Insert und Verarbeitung in eine gemeinsame DB-Transaktion legen und den Versand über die Queue (bzw. in DB::afterCommit) auslösen. handleEvent() zusätzlich pro Ereignis in try/catch fassen, damit ein Fehler nicht die übrigen Ereignisse des Pakets mitreisst.

### 50. Späte Zahlung auf eine tote Reservierung: bei der Vorgabe refund_mode='off' wird gar nichts zurückgezahlt

`app/Http/Controllers/Public/PaymentController.php:398`

handlePaid() setzt in Zeile 388 payment_status='paid' auch auf einer bereits abgelaufenen oder stornierten Reservierung und ruft dann in Zeile 398 `requestForReservation($reservation, 'late_payment_auto_refund')`. Der Kommentar darüber (Zeilen 395-397) verspricht: „Automatically queue a refund so the guest gets their money back without manual intervention." Das leistet der Aufruf nicht. RefundService::requestForReservation() steigt in Zeile 90-93 sofort mit null aus, wenn `refund_mode === 'off'` - und genau das ist die Vorgabe: LocationSettings::$defaults setzt 'refund_mode' => 'off' (Zeile 51), die Migration ebenfalls (2026_06_12_350000, Zeile 36). Solange der Betrieb Erstattungen nicht ausdrücklich einschaltet, behält er das Geld, der Gast hat keine Reservierung, und ausser einem Auditeintrag ('payment.late_on_inactive_reservation', Zeile 399) passiert nichts - keine Mail, kein Eintrag in der Erstattungsliste, kein Hinweis in der Oberfläche. Zweitens wird selbst bei eingeschalteten Erstattungen der Stornoprozentsatz angewendet (RefundService Zeile 118-119): Bei refund_percent = 50 bekommt der Gast für eine Buchung, die es gar nicht mehr gibt, nur die Hälfte zurück - der Prozentsatz beschreibt die Kulanz bei einer Stornierung, nicht die Rückabwicklung einer gegenstandslosen Zahlung.

**Auslöser:** Gast bezahlt kurz nach Fristablauf (ExpireUnpaidReservations hat die Buchung gerade auf 'expired' gesetzt) oder der Webhook trifft nach einer Absage durch den Betrieb ein. Standardeinstellung: kein Geld zurück, keine Meldung an irgendwen. Der Gast sieht auf seiner Verwaltungsseite eine abgelaufene Buchung mit dem Vermerk „bezahlt".

**Vorschlag:** Für die Quelle 'late_payment_auto_refund' den Modus und den Prozentsatz übergehen: immer den vollen Betrag als Erstattung anlegen (im Modus 'manual' zur Freigabe, sonst freigegeben). Zusätzlich den Betrieb aktiv benachrichtigen, statt sich auf das Auditlog zu verlassen.

### 51. DeliverWebhook zählt Wiederholungsversuche statt Ereignisse – Endpunkt fällt nach vier Ereignissen aus

`app/Jobs/DeliverWebhook.php:114`

registerFailure() erhöht `failure_count` bei **jedem** fehlgeschlagenen Versuch (Z. 115) und schaltet den Endpunkt bei DISABLE_AFTER_FAILURES = 20 (Z. 23) dauerhaft ab (Z. 117). Der Job hat aber `$tries = 5` (Z. 18) und wird nach jedem Fehlschlag selbst wieder eingereiht (Z. 82 bzw. Z. 93). Ein einziges Ereignis erzeugt damit fünf Zählschritte.

Die Schwelle wird also nicht nach 20 gescheiterten Ereignissen erreicht, sondern nach vier. Mit `$backoff = [60, 300, 1800, 7200]` (Z. 21) sind alle fünf Versuche eines Ereignisses in gut zweieinhalb Stunden durch. Ein Endpunkt, der einen halben Abend nicht erreichbar ist, wird also stillschweigend abgeschaltet – und nichts schaltet ihn wieder ein. Jede weitere Zustellung endet danach direkt in Z. 36 mit 'endpoint inactive'. Zurückgesetzt wird der Zähler nur bei einer erfolgreichen Zustellung (Z. 75), die es dann nicht mehr gibt.

**Auslöser:** Der Kunde spielt abends ein Update auf seinem Kassensystem ein, der Webhook-Empfänger ist drei Stunden weg. In der Zeit fallen vier Reservierungen an. 4 Ereignisse x 5 Versuche = 20 -> der Endpunkt steht auf is_active = false. Nach dem Update kommt nichts mehr an, ohne dass jemand etwas gemeldet bekäme.

**Vorschlag:** Nur den letzten Versuch eines Ereignisses zählen (`if ($this->attempts() >= $this->tries)`), oder den Zähler als „Ereignisse in Folge gescheitert" führen. Dazu in der Oberfläche sichtbar machen, warum ein Endpunkt abgeschaltet wurde.

### 52. Zahlungserinnerung folgt der Zahlungsaufforderung auf dem Fuss, wenn die Adresse bestätigt werden muss

`app/Jobs/ExpireUnpaidReservations.php:97`

remind() berechnet die Halbzeit aus `created_at` der Reservierung: `$frist = created_at->diffInMinutes(payment_due_at)` (Z. 97), `$halbzeit = payment_due_at - frist/2` (Z. 98). Auch die Mindestpause greift auf `created_at` zu (Z. 107).

Verlangt der Standort die Bestätigung der E-Mail-Adresse, bleibt `payment_due_at` beim Anlegen bewusst leer (ReservationLifecycleService.php:166-168) und wird erst beim Klick auf den Bestätigungslink gesetzt: `payment_due_at = now() + payment_deadline_minutes` (GuestPortalController.php:136), unmittelbar gefolgt vom Versand der Aufforderung `payment_pending` (GuestPortalController.php:139).

`created_at` liegt dann aber um die gesamte Klickverzögerung des Gastes früher. `$frist` enthält diese Verzögerung mit, die halbe Frist ist entsprechend gross, und `$halbzeit` liegt bereits in der Vergangenheit. Die Mindestpause von 10 Minuten ab `created_at` ist ebenfalls längst verstrichen. Der nächste Fünf-Minuten-Lauf (routes/console.php:30) verschickt die Erinnerung also sofort – genau das, was der Kommentar in Z. 104-106 verhindern soll („Eine Erinnerung, die der Aufforderung auf dem Fuss folgt, liest sich wie ein Fehler."). Die eigentlich vorgesehene Erinnerung zur Halbzeit entfällt damit ersatzlos.

Das ist nicht der bereits bekannte Punkt „payment_due_at bleibt bei manueller Bestätigung leer" – hier ist payment_due_at gesetzt, die Bezugsgrösse für die Halbzeit ist falsch.

**Auslöser:** Standort mit E-Mail-Bestätigung und 60 Minuten Zahlungsfrist. Gast bucht um 12:00, öffnet die Mail erst um 15:00 und klickt. payment_due_at = 16:00, Aufforderung geht um 15:00 raus. frist = 240 Min, halbzeit = 14:00 – bereits vorbei. Um 15:05 verschickt der Lauf die Erinnerung, fünf Minuten nach der Aufforderung.

**Vorschlag:** Die Halbzeit und die Mindestpause am Zeitpunkt der Aufforderung ausrichten statt an `created_at` – der Zeitstempel steht bereits im NotificationLog-Eintrag mit `template_key = 'payment_pending'`, auf den die Abfrage in Z. 83-88 ohnehin schon prüft.

### 53. SendFeedbackRequests: limit() vor dem PHP-Filter – nicht versandfähige Zeilen belegen das Fenster

`app/Jobs/SendFeedbackRequests.php:39`

Die Abfrage begrenzt in Z. 39 auf MAX_PER_RUN = 1000 und sortiert in Z. 38 nach `id` aufsteigend. Erst danach (Z. 41-49) entscheidet PHP, ob überhaupt versandt wird – unter anderem an `$settings->feedback_enabled` und `$r->location->tenant->hasFeature('feedback_enabled')`.

Zeilen, die dieser Filter verwirft, bekommen kein `feedback_requested_at`. Sie bleiben deshalb auf die volle Rückschau von 21 Tagen (Z. 35-36) in der Ergebnismenge und stehen wegen `orderBy('id')` als älteste ganz vorne. Ein Mandant, bei dem die Feedback-Funktion aus ist oder dem Tarif fehlt, verbraucht damit jede Stunde Plätze des 1000er-Fensters, ohne dass je etwas versandt würde.

Genau diese Falle beschreibt der eigene Code an anderer Stelle und vermeidet sie: ExpireUnpaidReservations.php:124-135 filtert die nie stornierbaren Buchungen in SQL statt in PHP, mit dem Kommentar „Würde man sie laden und in PHP überspringen, stünden sie beim nächsten Lauf wieder da".

**Auslöser:** Ein Restaurant mit ~50 abgeschlossenen Reservierungen am Tag schaltet Feedback ab (oder wechselt in einen Tarif ohne das Merkmal). Nach 20 Tagen liegen rund 1000 seiner Reservierungen mit kleineren ids im Rückschaufenster. Der stündliche Lauf lädt nur noch diese, filtert alle weg und verschickt für keinen einzigen anderen Betrieb mehr eine Feedback-Anfrage – bis die alten Zeilen aus dem 21-Tage-Fenster fallen.

**Vorschlag:** `feedback_enabled` und das Tarifmerkmal in die Abfrage ziehen (whereIn über die Standorte bzw. Mandanten mit aktivem Feedback) und nur die Zeitschwelle `feedback_hours_after` in PHP prüfen. Alternativ die aussortierten Zeilen mit einem eigenen Feld ausbuchen.

### 54. Feedback-Anfrage wird erst nach dem Versand ausgebucht – Wiederholung erzeugt Anfrage und Mail doppelt

`app/Jobs/SendFeedbackRequests.php:63`

Die Schleife (Z. 51-64) legt erst den FeedbackRequest an (Z. 52), verschickt dann die Mail (Z. 59) und bucht die Reservierung erst danach mit `feedback_requested_at` aus (Z. 63). Es gibt weder eine Transaktion um die drei Schritte noch eine Sperre.

Bricht der Job zwischen Z. 59 und Z. 63 ab, wird er von `queue:work --tries=3` (docker-compose.yml:59) erneut zugestellt. Die Reservierung erfüllt `whereNull('feedback_requested_at')` (Z. 28) weiterhin, also entsteht ein **zweiter** FeedbackRequest mit eigenem Token und der Gast bekommt die Bewertungsmail ein zweites Mal. Beide Tokens bleiben gültig, es können zwei Bewertungen zur selben Reservierung eingehen.

Derselbe Autor löst dasselbe Problem in MarketingCampaignService.php:117-132 andersherum – dort wird der Platz per `firstOrCreate` **vor** dem Versand beansprucht, ausdrücklich mit dem Kommentar „Claim the slot first: a crash halfway through must not turn into a second mail on the retry."

**Auslöser:** Der Lauf verarbeitet 400 Reservierungen. Bei Nummer 137 fällt Redis für zwei Sekunden aus, `Mail::to(...)->queue()` wirft. Der Job wird wiederholt und schickt an Gast 137 eine zweite Bewertungsmail mit neuem Token. Dasselbe passiert, wenn der Worker den Job nach 60 Sekunden (Standard-timeout, in docker-compose.yml nicht gesetzt) abschiesst.

**Vorschlag:** Reihenfolge umdrehen: `feedback_requested_at` als bedingtes UPDATE (`whereNull('feedback_requested_at')`) beanspruchen und nur bei Rückgabewert 1 FeedbackRequest anlegen und versenden.

### 55. Erinnerung wird erst nach Mail und SMS ausgebucht – Wiederholung schickt beides erneut

`app/Jobs/SendReservationReminders.php:69`

Pro Reservierung wird erst die Mail verschickt (Z. 52), dann die SMS über den Anbieter (Z. 64 → sendSms Z. 98), und erst danach `reminder_sent_at` gesetzt (Z. 69). Kein bedingtes UPDATE, keine Transaktion.

Stirbt der Job zwischen Z. 64 und Z. 69, gilt beim erneuten Zustellen weiterhin `whereNull('reminder_sent_at')` (Z. 28). Der Gast bekommt Erinnerungsmail und Erinnerungs-SMS ein zweites Mal – die SMS kostet den Betrieb bei jedem Versand echtes Geld, und im NotificationLog (Z. 114-122) stehen dann zwei Zeilen.

Die Wahrscheinlichkeit ist nicht klein: `lazyById(200)` (Z. 35) hat keine Obergrenze, der Lauf verarbeitet bei einem Rückstau beliebig viele Reservierungen, und `queue:work` läuft ohne `--timeout` (docker-compose.yml:59), also mit 60 Sekunden Standard. Jeder Lauf über dieser Grenze wird mitten in der Schleife abgeschossen und wiederholt.

**Auslöser:** Der Scheduler stand zwei Stunden. Beim ersten Lauf danach stehen 900 fällige Erinnerungen an. Nach 60 Sekunden schiesst der Worker den Job ab, genau nachdem für Reservierung Nr. 512 die SMS beim Anbieter lag, aber vor dem UPDATE. Beim zweiten Versuch bekommt dieser Gast Mail und SMS erneut.

**Vorschlag:** `reminder_sent_at` vor dem Versand per bedingtem UPDATE beanspruchen (`whereKey($id)->whereNull('reminder_sent_at')->update([...])`) und nur bei Rückgabewert 1 versenden.

### 56. Verpasster Kampagnenlauf verliert die Empfänger dieses Tages endgültig

`app/Services/MarketingCampaignService.php:73`

Zwei der drei Kampagnenarten arbeiten auf einem Fenster von genau einem Tag, ohne Nachlauf:
- rebooking (Z. 73-74): `last_visit_at >= $visitDay` UND `< $visitDay->addDay()`, wobei `$visitDay = $today->subDays($campaign->offset_days)` (Z. 69).
- birthday (Z. 72 → birthdayFilter Z. 228-235): `whereMonth`/`whereDay` gleich genau einem Datum.

`$today` ist der Ausführungstag (Z. 112). Läuft SendMarketingCampaigns an einem Tag nicht, betrachtet der Lauf am Folgetag ein anderes Fenster – die übersprungene Gruppe fällt dauerhaft heraus und wird nie angeschrieben. Auch der Dedupe-Schlüssel wandert mit (`reference()` Z. 215: `'v-'.$today->subDays(offset)->toDateString()`), ein Nachziehen von Hand ist damit ebenfalls nicht möglich.

Nur winback (Z. 75-76) ist offen nach hinten (`last_visit_at <` ohne untere Grenze) und holt sich selbst ein.

Ein verpasster Lauf ist nicht theoretisch: `Schedule::job` legt den Job nur in die Queue, und der einzige Worker (docker-compose.yml:59) verarbeitet nacheinander. Kommt der Job erst am nächsten Tag dran, liest er `now()` und damit den neuen Tag – die Gruppe von gestern ist auch dann weg. Dazu die Drift des Scheduler-Containers (docker-compose.yml:78).

**Auslöser:** Queue-Worker steht wegen eines Redis-Ausfalls von 08:50 bis 09:20. Der um 09:00 eingereihte SendMarketingCampaigns-Job läuft um 09:20 – noch am selben Tag, alles gut. Steht der Worker dagegen bis zum nächsten Morgen (OOM-Kill, Deploy über Nacht), berechnet der Job `$today` neu: alle Gäste mit einem Besuch am übersprungenen Stichtag und alle Geburtstagskinder dieses Tages bekommen nie eine Mail.

**Vorschlag:** Beide Fenster um einen Nachlauf erweitern (z. B. rebooking über `[visitDay - n Tage, visitDay + 1 Tag)`) und den Dedupe-Schlüssel weiter am Besuchstag bzw. am Geburtsjahr festmachen, nicht am Ausführungstag – dann holt ein späterer Lauf die Gruppe nach, ohne doppelt zu schreiben.

### 57. 'refunded' wird pro Erstattungszeile entschieden, nicht über die Summe - erstattete Beträge können den Vorgang überschreiten

`app/Services/RefundService.php:242`

`$fully = $intent === null || $refund->amount_minor >= $intent->amount_minor;` vergleicht nur DIESE eine Erstattung mit dem Vorgangsbetrag. Es gibt an keiner Stelle eine Summenprüfung über alle Refund-Zeilen zu einem payment_intent_id, und auch keine Prüfung vor dem Anbieteraufruf in Zeile 226. Die Doppelprüfung in requestForReservation() (Zeile 111) schliesst ausserdem 'failed' ausdrücklich aus, sodass nach einem fehlgeschlagenen Versuch eine ZWEITE Erstattungszeile für denselben Vorgang entstehen darf. Das ist besonders heikel, weil 'failed' auch dann gesetzt wird, wenn die Erstattung beim Anbieter in Wahrheit durchlief: der catch in Zeile 227-235 fängt jede Ausnahme (auch eine Zeitüberschreitung nach erfolgreicher Verarbeitung) und schreibt selbst dazu „Bitte dort pruefen, ob die Erstattung doch ausgefuehrt wurde". Nichts hindert das System daran, danach über einen anderen Weg eine weitere Erstattung anzulegen und auszuführen. In Verbindung mit den zwei parallel angelegten Zeilen aus Befund 1 bleibt der Vorgang bei refund_percent=50 nach zwei Auszahlungen zu je 50 % auf 'partially_refunded' stehen, obwohl 100 % zurückgeflossen sind.

**Auslöser:** Erstattung schlägt wegen Zeitüberschreitung fehl (Geld ist trotzdem unterwegs); anschliessend legt ein zweiter Auslöser - etwa die späte Zahlung auf eine inaktive Reservierung (PaymentController Zeile 398) oder eine Stornierung durch den Betrieb - eine neue Erstattungszeile an, die ebenfalls ausgeführt wird.

**Vorschlag:** Vor dem Anbieteraufruf die bereits erstattete Summe je payment_intent_id ermitteln (Status 'completed' und 'processing') und den neuen Betrag darauf begrenzen bzw. den Vorgang ablehnen; $fully aus dieser Summe berechnen statt aus der einzelnen Zeile.

### 58. transition() liest den Status ungesichert - doppelte Zaehler, doppelte Mails

`app/Services/ReservationLifecycleService.php:264`

`$from = $reservation->status;` steht VOR der Transaktion (Zeile 264); innerhalb der Transaktion (Zeile 274) wird weder neu gelesen noch `lockForUpdate()` verwendet, und das `update()` (Zeile 298) enthaelt keine Bedingung auf den erwarteten Ausgangsstatus. Zwei gleichzeitige Aufrufe sehen daher beide denselben $from, bestehen beide `canTransitionTo()` und fuehren beide die Nebenwirkungen aus: `registerVisit()` (Zeile 324) erhoeht visit_count zweimal und verzerrt avg_party_size (GuestProfileService.php:91-100), `increment('no_show_count')` bzw. `increment('cancellation_count')` (Zeile 325/326) zaehlen doppelt, es entstehen zwei ReservationStatusHistory-Zeilen, zwei Webhooks und zwei Gastmails. Anders als PaymentController::handlePaid, wo genau diese Absicherung ueber ein bedingtes UPDATE bewusst eingebaut wurde, fehlt sie hier komplett.

**Auslöser:** Zwei Mitarbeitende haben das Reservierungsbuch offen und klicken fast gleichzeitig auf "Abgeschlossen" (ReservationBookController.php:516), oder Einzelaktion und Sammelaktion (Zeile 589) treffen dieselbe Reservierung. Das Gastprofil zaehlt danach zwei Besuche fuer einen Abend; bei "Storniert" zwei Stornierungen, was direkt in das No-Show-Risiko einfliesst.

**Vorschlag:** In der Transaktion die Reservierung mit lockForUpdate() neu laden, $from daraus bestimmen und den Statuswechsel als bedingtes UPDATE (`where('status', $from->value)`) ausfuehren; bei 0 betroffenen Zeilen abbrechen, bevor Zaehler, Historie, Webhook und Mail laufen.

### 59. reassignTables() ohne Transaktion und ohne Sperre

`app/Services/ReservationLifecycleService.php:371`

reassignTables() liest die belegten Tische (Zeile 374), prueft die Schnittmenge (Zeile 381) und schreibt danach `tables()->sync($tableIds)` (Zeile 389) - alles ausserhalb jeder Transaktion und ohne lockSlot(). Zwischen Pruefung und sync liegt ein offenes Fenster. Zusaetzlich sind Schreibvorgang, Audit-Eintrag (Zeile 391) und das nachgelagerte `update(['table_chosen_by_guest' => false])` (Zeile 436) nicht atomar: bricht der Mailversand oder die Namensabfrage (Zeile 411) ab, ist der Tisch bereits umgehaengt, das Gastwunsch-Flag aber noch gesetzt.

**Auslöser:** Zwei Mitarbeitende ziehen im Tischplan gleichzeitig zwei verschiedene Reservierungen auf denselben freien Tisch. Beide Anfragen sehen den Tisch als frei, beide syncen ihn - der Tisch ist doppelt vergeben, und weil $conflicts in beiden Faellen leer war, wird nicht einmal 'reservation.tables_overbooked' protokolliert.

**Vorschlag:** reassignTables() in DB::transaction kapseln und darin lockSlot($location, $reservation->localStart()) aufrufen, bevor busyTableIds gelesen wird.

### 60. Umbuchen bewertet die Anzahlungspflicht nicht neu - der Betrieb verliert die Kaution

`app/Services/ReservationLifecycleService.php:539`

reschedule() schreibt neues Datum, neue Zeit und neue Personenzahl (Zeile 539-544), ruft aber `PaymentRequirementService::requirementFor()` nicht erneut auf. Die Anzahlungsregel wird ausschliesslich in create() ausgewertet (Zeile 145-152) und haengt dort ausdruecklich vom Startzeitpunkt, der Personenzahl, dem Raum und den Leistungen ab. Eine Reservierung, die ohne Anzahlung entstanden ist, behaelt payment_status 'not_required' auch dann, wenn sie per Gastlink auf einen Termin gelegt wird, fuer den eine Regel gilt - und umgekehrt bleibt eine bereits geleistete Anzahlung stehen, wenn der neue Termin keine mehr verlangt. Der Gast steuert diesen Weg selbst: PublicBookingController::reschedule (Zeile 728) erlaubt Datum, Uhrzeit und Personenzahl bis zur modification_deadline.

**Auslöser:** Gast bucht am 12. November fuer 2 Personen (keine Anzahlungsregel), oeffnet dann seinen Aenderungslink und bucht auf den 31. Dezember fuer 8 Personen um, wo eine Regel 20 EUR pro Person verlangt. Die Reservierung steht anschliessend an Silvester mit payment_status 'not_required' und ohne Zahlungsaufforderung.

**Vorschlag:** In reschedule() nach der Verfuegbarkeitspruefung requirementFor() mit den NEUEN Werten aufrufen und payment_status/payment_amount_minor/payment_due_at/deposit_rule_id nachziehen; entfaellt die Pflicht, eine bereits gezahlte Anzahlung ueber RefundService behandeln statt sie stillschweigend zu behalten.

### 61. Nach manueller Bestaetigung bleibt payment_status 'required' - der Gast zahlt eine erlassene Anzahlung

`app/Services/ReservationLifecycleService.php:293`

Beim Wechsel nach Confirmed wird payment_status nur zurueckgesetzt, wenn er 'failed' ist (Zeile 293-296). Der haeufigere Fall PaymentPending -> Confirmed (Personal bestaetigt telefonisch, Anzahlung erlassen oder bar kassiert) laesst payment_status auf 'required' und payment_amount_minor stehen. Das ist nicht nur kosmetisch: PublicBookingController::manage berechnet `$payEnabled` aus `in_array($reservation->payment_status, ['required','pending'])` und `$reservation->status->isActive()` (Zeile 647-651). Beide Bedingungen sind danach erfuellt, der Gast sieht in seiner Verwaltungsansicht weiterhin den Bezahl-Button und kann eine Anzahlung leisten, die der Betrieb bereits erlassen oder bar erhalten hat - die dann per RefundService wieder zurueckgebucht werden muss.

**Auslöser:** Reservierung steht auf payment_pending. Personal setzt sie im Reservierungsbuch auf "Bestaetigt". Der Gast oeffnet danach seinen Verwaltungslink und sieht "Jetzt bezahlen" - Betrag und Zahlungsweg sind aktiv.

**Vorschlag:** Die Bedingung in Zeile 293 auf `in_array($reservation->payment_status, ['failed','required'], true)` erweitern (bei 'pending' nicht anfassen, da dort ein Vorgang laeuft) und payment_due_at mit zuruecksetzen.

### 62. Manuell gewaehlte Tische ignorieren die Pufferzeit

`app/Services/ReservationLifecycleService.php:99`

Die automatische Tischsuche weitet das Pruefzeitfenster bewusst um buffer_minutes auf beiden Seiten auf (TableAssignmentService::findTables, Zeile 28-32). Der manuelle Zweig in create() ruft `busyTableIds($location, $startUtc, $endUtc, null)` mit dem rohen Fenster (Zeile 99), reassignTables ebenso (Zeile 374). Damit gilt die eingestellte Umruestzeit fuer jeden Tisch, den ein Gast im oeffentlichen Tischplan selbst anklickt, und fuer jede Verschiebung im Tischplan schlicht nicht - zwei Buchungen koennen dort auf die Minute aneinanderstossen, waehrend die Automatik denselben Tisch gesperrt haette.

**Auslöser:** Standort mit buffer_minutes = 30. Tisch 5 ist bis 20:00 belegt. Ueber den oeffentlichen Tischplan bucht ein Gast Tisch 5 fuer 20:00 - die Buchung geht durch, obwohl die Automatik erst 20:30 vergeben haette. Der Tisch ist beim Eintreffen des zweiten Gastes noch nicht abgeraeumt.

**Vorschlag:** busyTableIds an beiden Stellen mit `$startUtc->subMinutes($buffer)` / `$endUtc->addMinutes($buffer)` aufrufen (Puffer aus effectiveSettings), damit manuelle und automatische Zuteilung dieselbe Umruestzeit einhalten.

### 63. waitlist.accepted-Webhook wird in der offenen Transaktion abgeschickt und verschwindet spurlos

`app/Services/WaitlistService.php:154`

acceptOffer() ruft `$this->webhooks->dispatch(...)` in Z. 154 **innerhalb** des `DB::transaction`-Blocks (Z. 128-159). WebhookDispatchService::dispatch legt dort die WebhookDelivery-Zeile an (Z. 26) und stellt sofort `DeliverWebhook::dispatch($delivery->id)` in die Queue (Z. 40).

Die Produktionsverbindung ist Redis (docker-compose.yml:17/68) und steht in config/queue.php:73 auf `'after_commit' => false`. Der Job liegt also sofort in Redis, obwohl die Zeile noch nicht committet ist. Der Worker läuft in einem eigenen Container und kann ihn holen, bevor die Transaktion durch ist. DeliverWebhook.php:29-32 findet die Zeile dann nicht und macht ein stilles `return;` – kein Fehler, keine Wiederholung, kein Eintrag in failed_jobs. Der Webhook geht nie raus, und die Delivery-Zeile steht danach für immer auf 'pending'.

Alle anderen Aufrufer machen es richtig: ReservationLifecycleService.php:227 und :344 sowie EventBookingService.php:93 kapseln den Aufruf in `DB::afterCommit`. Nur diese eine Stelle nicht.

**Auslöser:** Gast nimmt das Wartelistenangebot an. Die Transaktion legt Reservierung, Angebotsstatus und Eintragsstatus an; dazwischen landet der DeliverWebhook-Job in Redis. Der Worker ist gerade leer und holt ihn innerhalb weniger Millisekunden, während die Transaktion noch offen ist. Das angeschlossene Kassensystem erfährt nie von der Reservierung.

**Vorschlag:** Den Aufruf in `DB::afterCommit(...)` legen wie an den anderen Stellen – oder generell in WebhookDispatchService `DeliverWebhook::dispatch(...)->afterCommit()` verwenden, dann ist die Stelle unabhängig vom Aufrufer sicher.


## Niedrig

### 64. Anmeldung per Magic Link erneuert die Sitzungskennung nicht

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Http/Controllers/Public/GuestPortalController.php:53`

login() schreibt nach dem Einlösen des Tokens direkt session(['guest_portal' => [...]]) und lässt die bestehende Sitzungskennung unverändert. Ein Wechsel des Berechtigungsstands ohne session()->regenerate() ist die klassische Session-Fixation: Wer einem Gast vorab eine bekannte Sitzungskennung unterschiebt, ist nach dessen Anmeldung im selben Konto. Das Gastkonto zeigt unter dashboard() bis zu 50 Reservierungen mit Datum, Leistungen und Mitarbeiterzuordnung (Zeile 66-72), also personenbezogene Daten. Voraussetzung ist, dass das Cookie gesetzt werden kann – die unescaped innerHTML-Stellen in booking.blade.php liegen auf demselben Origin, der Weg dorthin ist im selben Bereich also vorhanden. Auch logout() (Zeile 81-86) räumt nur den Schlüssel weg, statt die Sitzung zu verwerfen: Auf einem geteilten Gerät bleibt die Kennung nach dem Abmelden gültig.

**Auslöser:** Sitzungskennung des Opfers vorab festlegen, das Opfer meldet sich anschliessend über seinen Anmeldelink an – die alte Kennung trägt danach die guest_portal-Sitzung.

**Vorschlag:** In login() vor dem Schreiben der Sitzungsdaten session()->regenerate() aufrufen, in logout() session()->invalidate() und session()->regenerateToken() statt forget().

### 65. Einbindungs-Skripte werfen 500 statt 404, sobald der Mandant mehrere Standorte hat

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Http/Controllers/Public/PublicBookingController.php:958`

resolveSingleLocation() endet mit $base->sole(): Findet sich kein Standort, dessen Slug dem Mandanten-Slug entspricht, und gibt es mehr als einen aktiven, online buchbaren Standort, wirft sole() eine MultipleRecordsFoundException. Die ist eine RuntimeException und wird vom Handler zu 500 – nicht zu 404. Betroffen sind die öffentlichen GET-Routen /embed/{tenantSlug}.js und /widget/{tenantSlug}/popup.js (Zeile 931-944), also genau die Adressen, die ein Betrieb auf seiner eigenen Website einbindet: Wächst der Mandant von einem auf zwei Standorte, liefert das eingebundene Skript ab sofort einen Serverfehler, die Website des Kunden verliert den Reservierungsbutton, und in den Logs steht ein Fehler statt eines sauberen 404. Dieselbe Konstruktion in storeLanding() (Zeile 113) macht aus einem POST /book/{tenantSlug} bei mehreren Standorten ebenfalls einen 500.

**Auslöser:** Mandant mit zwei aktiven Standorten, deren Slugs beide vom Mandanten-Slug abweichen. GET /embed/{tenantSlug}.js liefert 500.

**Vorschlag:** Statt sole() ein ->get() mit eigener Auswertung: bei genau einem Treffer weiterarbeiten, sonst abort(404) – so wie landing() es in Zeile 77 schon macht. In storeLanding() dieselbe Behandlung, dort passt zusätzlich ein Verweis auf die Standortauswahl.

### 66. Wartelisteneintrag für ein vergangenes Datum wird bestätigt, ist aber sofort abgelaufen

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Http/Controllers/Public/PublicBookingController.php:778`

joinWaitlist() validiert 'date' nur mit date_format:Y-m-d, ohne after_or_equal:today (der Buchungspfad in Zeile 414 hat die Regel). WaitlistService::createEntry() setzt daraus expires_at = Wunschtag endOfDay (WaitlistService.php:59). Bei einem Datum in der Vergangenheit liegt expires_at damit bereits hinter 'jetzt': Der nächste Lauf von expireStale() (Zeile 183-186) setzt den Eintrag auf 'expired', er wird nie einem Angebot zugeordnet. Der Gast bekommt trotzdem die volle Zusage – der fetch-Zweig in booking.blade.php:818 zeigt 'Du stehst auf der Warteliste! Wir melden uns per E-Mail, sobald ein Tisch frei wird.' Auch die Personenzahl ist hier mit min:1,max:100 hart verdrahtet statt an min_party_online/max_party_online gebunden, anders als im Buchungsformular.

**Auslöser:** POST /book/{tenant}/{location}/waitlist mit date=<gestern>, party_size=2, name, email, privacy_accepted=1. Antwort 200 mit der Bestätigungsseite, der Eintrag steht mit bereits abgelaufenem expires_at in der Datenbank.

**Vorschlag:** 'date' um after_or_equal:today ergänzen und party_size an $settings->min_party_online/max_party_online binden, wie es store() tut.

### 67. Dateien werden innerhalb der Datenbanktransaktion von der Platte genommen

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Services/GuestPrivacyService.php:134`

`Storage::disk($attachment->disk)->delete($attachment->path)` läuft innerhalb von `DB::transaction()` (Zeile 82). Der Dateisystemzugriff kennt kein Rollback. Bricht ein späterer Schritt derselben Transaktion ab – die beiden NotificationLog-Updates (Zeile 142/147), das Profil-Update (161) oder der Auditeintrag (179) –, rollt die Datenbank zurück, die Dateien sind aber weg. Zurück bleibt eine `reservation_attachments`-Zeile, die auf einen nicht mehr existierenden Pfad zeigt; der Download läuft ins Leere und der Gast gilt weiterhin als nicht anonymisiert, sodass der nächste Anonymisierungsversuch dieselben (nicht mehr vorhandenen) Anhänge erneut abzuräumen versucht.

**Auslöser:** Anonymisierung eines Gastes mit Reservierungsanhängen; die anschließende Aktualisierung des Versandprotokolls scheitert (z. B. Sperre/Timeout). Datenbank unverändert, Anhangsdateien gelöscht.

**Vorschlag:** Pfade innerhalb der Transaktion nur einsammeln und die Dateien erst nach erfolgreichem Commit löschen – `DB::afterCommit()` bzw. Sammelliste plus Löschschleife hinter `DB::transaction(...)`.

### 68. DSGVO-Auskunft blendet als sensibel markierte Notizen aus

`C:/Users/brigh/Claude Workingdir/gastrobook/app/Services/GuestPrivacyService.php:46`

`'notes' => $guest->notes()->…->where('is_sensitive', false)->pluck('body')` – der Auskunftsexport nach Art. 15 lässt genau die Notizen weg, die als sensibel markiert sind. Der Filter stammt aus der Sichtbarkeitslogik der Oberfläche (dort schützt `guest_notes.sensitive.view` vor Mitarbeitern, GuestController.php:159), gilt aber gegenüber dem Betroffenen nicht: Art. 15 gibt Auskunft über ALLE zu seiner Person gespeicherten Daten, gerade auch über die heikleren. Die aufrufende Route verlangt bereits `guest_notes.view` und begründet das ausdrücklich damit, dass der Export dieselben internen Notizen enthält wie die Profilseite (GuestController.php:196–199) – die Filterzeile widerspricht dieser Begründung. Der Betroffene erfährt also nicht, dass eine Notiz über ihn existiert.

**Auslöser:** Ein Mitarbeiter legt zu einem Gast eine als sensibel markierte Notiz an. Der Gast verlangt Auskunft, der Betrieb lädt den JSON-Export herunter und gibt ihn heraus – die Notiz ist nicht enthalten.

**Vorschlag:** Im Auskunftsexport alle Notizen ausgeben und die Kennzeichnung mitliefern (`['body' => …, 'is_sensitive' => …]`), damit der Betrieb sieht, was er herausgibt. Der Zugriffsschutz bleibt an der Route, wo er hingehört.

### 69. Migration setzt Spalte hinter eine Spalte, die erst die nächste Migration anlegt

`C:/Users/brigh/Claude Workingdir/gastrobook/database/migrations/2026_06_12_360000_add_confetti_and_guest_address_setting.php:12`

`$table->boolean('confetti_on_booking')->default(true)->after('require_email_confirmation')` – die Spalte `require_email_confirmation` wird aber erst in `2026_06_12_360000_add_guest_accounts.php:17` angelegt. Beide Dateien tragen denselben Zeitstempel `2026_06_12_360000`; Laravel sortiert dann nach Dateinamen, und `…_add_confetti_and_guest_address_setting` steht vor `…_add_guest_accounts`. Die confetti-Migration läuft also zuerst und verweist auf eine noch nicht existierende Spalte. Auf PostgreSQL und SQLite fällt das nicht auf, weil `after()` dort folgenlos verworfen wird – auf MySQL/MariaDB bricht `ALTER TABLE … AFTER require_email_confirmation` ab, und `docker/entrypoint.sh` mit `set -e` legt den Container damit in die Neustartschleife. Der doppelte Zeitstempel ist die eigentliche Ursache: die beabsichtigte Reihenfolge ergibt sich nur zufällig aus dem Anfangsbuchstaben des Dateinamens.

**Auslöser:** Frische Installation auf MySQL/MariaDB: `php artisan migrate` bricht bei der confetti-Migration mit „Unknown column 'require_email_confirmation' in 'location_settings'" ab.

**Vorschlag:** Der confetti-Migration einen späteren Zeitstempel geben (Datei umbenennen, solange sie noch nicht überall gelaufen ist) oder das `after()` auf eine Spalte umhängen, die zu diesem Zeitpunkt sicher existiert. Grundsätzlich keine zwei Migrationen mit identischem Zeitstempel, wenn zwischen ihnen eine Abhängigkeit besteht.

### 70. ?paid=1 in der Adresszeile meldet dem Gast eine Anzahlung, die nie eingegangen ist

`C:/Users/brigh/Claude Workingdir/gastrobook/resources/views/public/manage.blade.php:76`

Die Verwaltungsseite entscheidet über request()->boolean('paid') || $reservation->payment_status === 'paid', ob sie 'Anzahlung erhalten – vielen Dank!' anzeigt. Der erste Operand ist ein reiner URL-Parameter, der Zahlungsstand aus der Datenbank wird dadurch übersprungen. Weil der Block ein @elseif($payEnabled) trägt (Zeile 80), verschwindet mit derselben Zeile auch der Bezahl-Button und der Hinweis auf die Zahlungsfrist. Ein Gast, dem der Link mit angehängtem ?paid=1 zugeht – oder der ihn aus einer früheren, erfolgreichen Zahlung erneut aufruft, während inzwischen eine neue Anzahlung fällig ist – bekommt eine Bestätigung über Geld, das nie geflossen ist, und keine Möglichkeit mehr zu zahlen. Die Reservierung verfällt anschliessend über die Zahlungsfrist. Der Parameter ist als Anzeigehilfe für den Rücksprung von Stripe/PayPal gedacht (PaymentController.php:162 und 197), dort ist der Zahlungsstand aber ohnehin schon geschrieben.

**Auslöser:** Reservierung mit offener Anzahlung aufrufen als /reservation/{code}/manage/{token}?paid=1 – die Seite meldet 'Anzahlung erhalten' und blendet den Bezahl-Button aus.

**Vorschlag:** Die Bedingung auf $reservation->payment_status === 'paid' reduzieren. Wird für den Rücksprung eine Zwischenmeldung gebraucht, dafür eine Flash-Message aus PaymentController setzen statt eines frei setzbaren URL-Parameters.

### 71. Kein Weg zurueck aus 'seated' - ein Fehlklick ist nur ueber 'completed' aufloesbar

`app/Enums/ReservationStatus.php:56`

`self::Seated->value => [self::Completed->value]` laesst als einzigen Ausgang 'completed' zu. Fuer 'no_show' wurde die Korrektur ausdruecklich vorgesehen (Zeile 63: NoShow -> Completed, "correction: guest showed up after all"), fuer 'seated' fehlt das Gegenstueck vollstaendig: weder zurueck nach 'confirmed' noch nach 'cancelled_by_restaurant'. Wer im Reservierungsbuch die falsche Zeile ancheckt, kann das nicht rueckgaengig machen - und der einzige erlaubte Ausweg 'completed' loest in transition() `registerVisit()` aus (ReservationLifecycleService.php:324), traegt also einen Besuch samt last_visit_at und avg_party_size in ein Gastprofil ein, dessen Gast nie da war. Damit rechnet die Stammgast-Erkennung weiter.

**Auslöser:** Zwei Reservierungen um 19:00 untereinander im Buch, Personal checkt versehentlich die falsche ein. Die Statusliste bietet danach nur noch "Abgeschlossen" an; jeder Weg zurueck erzeugt entweder einen falschen Besuchszaehler oder erfordert einen Eingriff in der Datenbank.

**Vorschlag:** Seated -> Confirmed und Seated -> CancelledByRestaurant als Korrekturuebergaenge aufnehmen (analog zu NoShow -> Completed) und in transition() beim Verlassen von 'seated' seated_at wieder auf null setzen.

### 72. Selbstfreischaltung nach Testablauf: ein Klick auf den eigenen Bestätigungslink hebt die Sperre dauerhaft auf

`app/Http/Controllers/Admin/BillingRequestController.php:87`

confirm() ist eine öffentliche Route (routes/web.php Zeile 172) und setzt in Zeile 87 `$billingRequest->tenant->update(['status' => 'active', 'trial_ends_at' => null])`, sobald der Empfänger den Link aus der Bestätigungsmail anklickt. Die Empfängeradresse gibt der Mandant im selben Formular selbst an (store(), Zeilen 41-54, validiert nur das Format). Es gibt keine Zahlungsprüfung, keine Freigabe durch den Plattformbetreiber und keine Wiederholungsgrenze - der eigentliche Freischaltweg mit Prüfung ist activate() (Zeile 115), das hinter authorizePlatform() liegt und als einziges auch den Tarif umstellt. Weil trial_ends_at auf null gesetzt wird, läuft die Testphase danach nie wieder ab: die Freischaltung ist endgültig und unbefristet. Der Kommentar in Zeile 86 beschreibt das als gewollt („billing is settled manually outside the app"), es gibt aber keinen Datensatz, der offen hält, ob je abgerechnet wurde - der einzige Nachweis ist der BillingRequest selbst.

**Auslöser:** Mandant erreicht das Ende der Testphase, füllt das Formular mit einer beliebigen eigenen Adresse aus und klickt den Link in der Mail. Das Konto ist unbefristet freigeschaltet, ohne dass irgendwo eine Zahlung hinterlegt oder erwartet wird.

**Vorschlag:** Bei confirm() den Mandanten auf einen eigenen Status ('pending_billing') setzen und die Sperre erst bei activate() durch den Plattformbetreiber aufheben - oder wenigstens trial_ends_at auf eine kurze Kulanzfrist setzen statt auf null, damit ein nicht abgerechnetes Konto von selbst wieder zumacht.

### 73. Webhook-Anlage über die API prüft das Tarif-Merkmal webhooks_enabled nicht

`app/Http/Controllers/Api/V1/WebhookApiController.php:34`

store(), index() und destroy() prüfen nur `tokenCan('webhooks:manage')`. Der gleichwertige Weg über die Verwaltung ruft dagegen requireFeature() auf (Admin/WebhookController.php:56, 108, 125 → Zeile 160-166: `abort_unless($this->context->tenant()->hasFeature('webhooks_enabled'), 403)`). ResolveApiTenant.php:50 prüft nur api_enabled, nicht webhooks_enabled.

Ein Mandant, dem webhooks_enabled über feature_overrides (Tenant.php:130-133) abgeschaltet wurde, kann über /api/v1/webhooks also weiterhin Endpunkte anlegen und rotieren. Ausgeliefert wird anschließend zwar nichts (WebhookDispatchService.php:16 prüft das Merkmal beim Versand), aber die Oberfläche zeigt danach Endpunkte an, die nie zustellen, und die Tarifgrenze ist an einem von zwei Eingängen wirkungslos. In den ausgelieferten Tarifen (database/seeders/PlanSeeder.php:18-28) sind alle Merkmale auf true, der Fall tritt heute also nur über einen manuellen Override auf.

**Auslöser:** Bei einem Mandanten feature_overrides = {"webhooks_enabled": false} setzen. POST /admin/webhooks liefert 403 "Webhooks sind in diesem Tarif nicht enthalten". POST /api/v1/webhooks mit einem Token, das webhooks:manage trägt, liefert 201 und legt den Endpunkt an.

**Vorschlag:** In WebhookApiController dieselbe Prüfung ergänzen wie im Admin-Controller — am besten die vorhandene Methode wiederverwenden, damit die beiden Eingänge nicht erneut auseinanderlaufen (die EVENTS-Liste teilen sie sich bereits über AdminWebhookController::EVENTS).

### 74. Erinnerung geht direkt nach der Buchung raus, wenn kurzfristig gebucht wird

`app/Jobs/SendReservationReminders.php:41`

Die einzige Zeitbedingung für den Versand ist `now()->gte($r->start_at->copy()->subHours($settings->reminder_hours_before))` (Z. 41). Ein Mindestabstand zur Buchungsbestätigung fehlt.

Liegt der Buchungszeitpunkt bereits innerhalb der eingestellten Vorwarnzeit, ist die Bedingung sofort erfüllt und der Viertelstundenlauf (routes/console.php:15) schickt die Erinnerung unmittelbar nach der Bestätigungsmail. Bei der zulässigen Obergrenze von 168 Stunden (Z. 22) trifft das auf jede Buchung zu, die weniger als eine Woche im Voraus erfolgt.

Dass der Autor diesen Fall grundsätzlich vermeiden will, zeigt die Schwesterstelle: ExpireUnpaidReservations.php:51 führt eigens MIN_REMINDER_GAP_MINUTES ein, „Eine Erinnerung, die der Aufforderung auf dem Fuss folgt, liest sich wie ein Fehler."

**Auslöser:** Standort mit reminder_hours_before = 24. Gast bucht um 17:00 für 19:00 desselben Tages und bekommt die Bestätigung. Der Lauf um 17:15 stellt fest, dass 19:00 minus 24 Stunden längst vorbei ist, und schickt „Erinnerung: Ihre Reservierung heute um 19:00 Uhr" – 15 Minuten nach der Bestätigung.

**Vorschlag:** Analog zu ExpireUnpaidReservations einen Mindestabstand zwischen `created_at` (bzw. `confirmed_at`) und dem Erinnerungsversand einziehen und Buchungen, die schon innerhalb der Vorwarnzeit entstehen, gar nicht erinnern.

### 75. SendTrialExpiryWarnings: each() blättert per OFFSET, während die Schleife die Bedingung ungültig macht

`app/Jobs/SendTrialExpiryWarnings.php:35`

Die Abfrage filtert auf `whereNull('trial_warning_sent_at')` (Z. 33) und wird mit `->each(...)` (Z. 35) durchlaufen. `each()` blättert intern mit `chunk(1000)`, also mit LIMIT/OFFSET. Die Rückmeldung in Z. 48 setzt `trial_warning_sent_at` für jeden verarbeiteten Mandanten und nimmt ihn damit aus der Ergebnismenge.

Nach der ersten Seite rutschen die verbleibenden Zeilen nach vorn, die zweite Seite fragt aber mit OFFSET 1000 – überspringt also genau so viele Mandanten, wie gerade abgearbeitet wurden. Deren Eigentümer bekommen keine Warnung vor Ablauf der Testphase, und weil `trial_warning_sent_at` bei ihnen leer bleibt, würde der Lauf am nächsten Tag zwar erneut greifen, aber nur, solange `trial_ends_at` noch im Zwei-Tage-Fenster (Z. 32) liegt.

Greift erst ab mehr als 1000 gleichzeitig passenden Mandanten, ist also derzeit latent. Dasselbe Muster steht in RunRetentionPolicies.php:19, dort allerdings ohne Auswirkung, weil runRetention() die Filterbedingung `status = 'active'` nicht verändert.

**Auslöser:** Mehr als 1000 Mandanten, deren Testphase in vier bis sechs Tagen endet (z. B. nach einer grossen Registrierungswelle). Der Lauf warnt die ersten 1000 und überspringt die nächsten 1000 vollständig.

**Vorschlag:** `each()` durch `lazyById()` oder `chunkById()` ersetzen – dort wird nach `id > letzte id` weitergeblättert statt per OFFSET, das Ausbuchen in der Schleife stört dann nicht.

### 76. approve() ignoriert bei Eventbuchungen die Einstellung refund_processing='scheduled'

`app/Services/RefundService.php:368`

processingMode() ermittelt den Standort ausschliesslich über `$refund->reservation_id` (Zeilen 370-373). Bei einer Erstattung zu einer Eventbuchung ist reservation_id null, damit $location null, und Zeile 375 liefert über `?? 'immediate'` die Sofortverarbeitung. approve() (Zeile 164) zahlt also sofort aus, obwohl der Standort auf refund_processing='scheduled' steht - der Zwilling requestForEventBooking() liest die Einstellung in Zeile 340 korrekt aus `$settings->refund_processing`. Ein Betrieb, der Erstattungen bewusst gebündelt und zeitversetzt laufen lässt (etwa um eine Rücknahme vor dem Auszahlen zu ermöglichen), verliert diese Kontrolle für alle Event-Erstattungen.

**Auslöser:** Standort mit refund_mode='manual' und refund_processing='scheduled'; Mitarbeiter gibt im Erstattungsbereich eine Eventbuchung frei - das Geld ist sofort weg statt beim nächsten Sammellauf.

**Vorschlag:** In processingMode() den Zweig für event_booking_id ergänzen (EventBooking → Event → Location → effectiveSettings), so wie requestForEventBooking() es in den Zeilen 279-285 bereits macht.

### 77. Betriebstag-Sperre trennt ueberlappende Buchungen an der 06:00-Grenze

`app/Services/ReservationLifecycleService.php:663`

`$betriebstag = $startLocal->hour < 6 ? $startLocal->subDay() : $startLocal;` (Zeile 663) soll verhindern, dass ein Fenster ueber Mitternacht in zwei Sperren zerfaellt. An der 06:00-Grenze entsteht dasselbe Problem noch einmal: Eine Buchung um 05:30 bekommt den Schluessel des Vortags, eine um 06:00 den des laufenden Tags. Bei einer Dauer von 120 Minuten ueberlappen diese beiden Buchungen (05:30-07:30 und 06:00-08:00), laufen aber unter verschiedenen Advisory Locks und damit ungebremst nebeneinander - genau der Fall, den der Kommentar in Zeile 649-652 als behoben beschreibt. Betroffen sind Betriebe mit Fruehstuecks- oder Durchgangsbetrieb ueber 06:00 hinweg.

**Auslöser:** Standort mit Oeffnungszeit 05:00-14:00, ein passender Tisch frei. Zwei gleichzeitige Online-Buchungen um 05:30 und 06:00 erhalten crc32-Schluessel fuer zwei verschiedene Tage, halten sich gegenseitig nicht auf und koennen denselben Tisch belegen.

**Vorschlag:** Statt einer Tagesgrenze beide moeglichen Betriebstage sperren (Vortag und Tag des Starts, in fester Reihenfolge, damit kein Deadlock entsteht) - oder die Sperre am Standort statt am Tag festmachen, solange die Buchungslast das erlaubt.

### 78. Warteliste: Angebot haelt den Platz nicht und prueft ihn nicht

`app/Services/WaitlistService.php:73`

offer() legt den Angebotsdatensatz an und verschickt die Mail (Zeile 75-107), ohne zu pruefen, ob zum angebotenen Zeitfenster ueberhaupt noch ein Tisch frei ist, und ohne zu pruefen, ob fuer dasselbe Fenster bereits ein offenes Angebot an einen anderen Wartenden herausging. Der Platz wird auch nicht reserviert - erst acceptOffer() ruft create() auf, das die Verfuegbarkeit ganz normal prueft (Zeile 141-149). Zu einer Doppelvergabe fuehrt das nicht (der zweite Zugriff scheitert an checkExact), wohl aber zu falsch informierten Gaesten: Wer zweiter klickt, bekommt statt eines Tisches eine Validierungsmeldung auf der Antwortseite, obwohl ihm per Mail ausdruecklich "Ein Tisch ist frei geworden" zugesagt wurde. Dasselbe passiert, wenn in der Zwischenzeit ein normaler Online-Gast den Tisch nimmt.

**Auslöser:** Ein Tisch wird um 19:00 frei. Das Personal bietet ihn ueber die Wartelistenansicht nacheinander zwei Eintraegen an (WaitlistAdminController.php:79 prueft nichts). Beide bekommen dieselbe Zusage per Mail, nur der schnellere bekommt einen Tisch.

**Vorschlag:** In offer() vor dem Anlegen `checkExact()` bzw. `busyTableIds()` fuer das angebotene Fenster pruefen und offene Angebote anderer Eintraege mit ueberlappendem Fenster ablehnen; alternativ das Angebot als echten Hold fuehren (Reservierung im Status waitlist_offered, die Tische belegt und beim Ablauf freigegeben wird).

