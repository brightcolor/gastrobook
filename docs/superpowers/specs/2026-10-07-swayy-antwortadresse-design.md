# Swayy-eigene Antwortadresse je Betrieb (eingehende Gästeantworten)

Stand: 2026-10-07 · Entwurf zur Freigabe · baut auf v1.129.1 (PR #45)

## Ziel

Antworten von Gästen auf Buchungsmails sollen beim Betrieb ankommen und
zugleich im Buchungsverlauf und Gästeprofil sichtbar werden.

Heute trägt jede Gästemail (seit PR #45) als Reply-To direkt die Adresse des
Betriebs. Antworten gehen damit an Swayy vorbei und bleiben in der Oberfläche
unsichtbar. Dieser Entwurf gibt jedem Betrieb eine Swayy-eigene Antwortadresse.
Swayy nimmt die Antwort an, ordnet sie der Buchung zu, legt sie im Verlauf ab
und leitet sie an die Adresse des Betriebs weiter.

## Entscheidungen aus dem Brainstorming

1. **Rückweg:** Swayy leitet an den Betrieb weiter und setzt Reply-To = Gast.
   Der Betrieb antwortet aus seinem Postfach direkt an den Gast. Swayy hält die
   eingehende Richtung fest.
2. **Kennung:** HMAC aus dem Reservierungscode. Adresse
   `<slug>+<code>.<hmac>@<relay-domain>`. Zustandslos, fälschungssicher über
   einen aus `APP_KEY` abgeleiteten Schlüssel.
3. **Geltung:** Opt-in je Betrieb, Vorgabe aus. Das direkte Reply-To aus PR #45
   gilt, solange Relay aus ist, die Relay-Domain fehlt oder eine Mail keinen
   Buchungsbezug hat.
4. **Unzuordenbar:** Passt wenigstens der Slug, geht die Mail ohne
   Buchungsbezug an den Betrieb, mit Vermerk „nicht zugeordnet". Passt der Slug
   nicht, bleibt nur ein Protokolleintrag.
5. **Anhänge:** gehen mit der Weiterleitung an den Betrieb. Swayy speichert den
   Text und die Anhang-Metadaten (Name, Größe, MIME-Typ). Die Dateien selbst
   bleiben ungespeichert.
6. **Aufbewahrung:** an Buchung und Gast gekoppelt. Die Löschung der Buchung
   entfernt die Antworten mit, die Anonymisierung über `GuestPrivacyService`
   leert die personenbezogenen Felder.

## Adressformat und HMAC

- Lokalteil: `<slug>+<code>.<hmac>`.
  Beispiel: `baeckerei-mueller+R-7KQ2M9.a1b2c3d4@antwort.swayy.de`.
- Der `code` bleibt lesbar (`R-XXXXXX`, enthält selbst ein `-`).
- `hmac = base32( hash_hmac('sha256', "<tenant_id>:<code>", <abgeleiteter Schlüssel>) )`,
  kleingeschrieben, ohne Füllzeichen, auf `hmac_length` Zeichen gekürzt.
- Abgeleiteter Schlüssel: `hash_hmac('sha256', 'swayy.guest-mail-relay', APP_KEY)`,
  damit der App-Schlüssel nicht roh verwendet wird.
- Trenner: `+` zwischen Slug und Tag (Subadressierung), `.` zwischen Code und
  HMAC. Beide als Einstellung.
- **Erzeugen:** `GuestReplyAddress::for(Reservation $r): string`.
- **Parsen/Prüfen eingehend:** am ersten `+` teilen → Slug und Tag; am letzten
  `.` teilen → Code und HMAC. Slug → Tenant, Code → Reservierung im Tenant,
  HMAC per `hash_equals` gegen den neu berechneten Wert. Ein HMAC-Fehler zählt
  als unzuordenbar.
- Der HMAC verhindert, dass sich eine erratene Slug/Code-Kombination einer
  Buchung unterschieben lässt.

## Datenfluss (eingehend)

1. Gast erhält eine Buchungsmail. Reply-To = `<slug>+<code>.<hmac>@<domain>`,
   sobald der Betrieb Relay aktiviert hat und ein Reservierungskontext vorliegt.
2. Gast antwortet. Die Mail geht an Postal (MX der Relay-Domain).
3. Die Postal-Eingangsroute (Catch-all) schiebt die Nachricht per HTTP POST an
   `POST /webhooks/postal`.
4. gastrobook prüft die Postal-Signatur, zerlegt die Empfängeradresse, prüft den
   HMAC, findet Betrieb (Slug) und Buchung (Code).
5. Die Nachricht wird in `guest_mail_replies` gespeichert, verknüpft mit Buchung
   und Gast, und erscheint im Buchungsverlauf sowie im Gästeprofil.
6. gastrobook leitet die Nachricht an die Adresse des Betriebs weiter, Reply-To
   = Gast. Der Betrieb antwortet direkt an den Gast.

## Komponenten

| Baustein | Aufgabe |
|----------|---------|
| Route `POST /webhooks/postal` (routes/web.php) | Eingang; CSRF-Ausnahme in bootstrap/app.php (`webhooks/postal`), Name `webhooks.postal` |
| `App\Http\Controllers\PostalInboundController` | Vorbild `GoCardlessWebhookController`: Signatur prüfen, Idempotenz, Fehler je Nachricht isoliert, 200/400/401 mit klarer Meldung |
| `App\Services\Mail\PostalSignatureVerifier` | prüft den Postal-Signatur-Header gegen den konfigurierten Public Key |
| `App\Services\Mail\GuestReplyAddress` | baut und parst die Adresse, berechnet und prüft den HMAC |
| `App\Services\Mail\InboundGuestReplyRouter` | ordnet zu, legt den Datensatz an, entscheidet Weiterleitung/Verwerfen, stößt die Weiterleitung an |
| `App\Services\GuestMailSender` | erweitert: Relay-Reply-To, wenn Reservierungskontext da und Tenant-Relay aktiv; sonst wie bisher (PR #45) |
| `App\Mail\ForwardedGuestReplyMail` | Weiterleitung an den Betrieb, From = MAIL_FROM_ADDRESS + Betriebsname, Reply-To = Gast, Anhänge angehängt |
| Modell + Migration `guest_mail_replies` | Speicher der eingehenden Antworten (Felder unten) |
| Spalte `tenants.mail_relay_enabled` (bool, Vorgabe false) | der Opt-in-Schalter je Betrieb |
| Oberfläche `reservations/show.blade.php`, `guests/show.blade.php` | Abschnitt „Antworten" im Verlauf und Gästeprofil |
| `SettingsController` (Allgemein › E-Mails an Gäste) | Schalter „Antwortadresse über Swayy", Validierung mit verständlicher Meldung |

## Datenmodell: Tabelle `guest_mail_replies`

- `id`
- `tenant_id` → cascadeOnDelete
- `location_id` nullable → nullOnDelete
- `reservation_id` nullable → cascadeOnDelete (Antworten leben mit der Buchung)
- `guest_id` nullable → nullOnDelete
- `postal_message_id` string, unique (Idempotenz, wie `gocardless_webhook_events`)
- `message_id_header` string nullable
- `from_email`, `from_name` (werden bei Anonymisierung geleert)
- `to_recipient` string (die RCPT-Adresse wie empfangen)
- `subject` string nullable
- `body_text` text (geleert bei Anonymisierung)
- `has_attachments` bool
- `attachments_meta` json nullable (Name, Größe, MIME je Anhang)
- `match_status` string: `matched` | `slug_only` | `unmatched`
- `forwarded_to` string nullable, `forwarded_at` timestamp nullable
- `forward_status` string: `queued` | `sent` | `failed` | `skipped`
- `received_at` timestamp
- `timestamps`
- Index `tenant_id+reservation_id`, `tenant_id+guest_id`

## Postal-Signatur und Eingangsauthentifizierung

- Postal signiert den HTTP-POST (Signatur-Header über den Rohbody). Der Public
  Key stammt aus der Postal-Oberfläche des Mailservers und kommt als Einstellung
  `inbound_public_key` (PEM). Prüfung analog `GoCardlessService::verifyWebhookSignature`.
- Optional zusätzlich ein Shared Secret als Einstellung (defense in depth).
  Vorgabe: nur Signatur.
- Fehlende oder falsche Signatur → 401 mit klarer JSON-Meldung, nichts wird
  verarbeitet, ein Protokolleintrag nennt den Grund.
- Die genauen Feldnamen des Postal-Payloads (`rcpt_to`, `mail_from`, `message`,
  `base64`) und der Signatur-Header werden beim Einrichten der Postal-Route
  final geprüft.

## Weiterleitung an den Betrieb

- Zieladresse: dieselbe Auflösung wie die Reply-To-Ersatzliste aus
  `config/swayy.php` (`guest_mail.reply_to_fallbacks`: `location_email`,
  `owner_notification_email`).
- `ForwardedGuestReplyMail`: From = MAIL_FROM_ADDRESS mit Betriebsname, Reply-To
  = Gastadresse, Betreff = `<Präfix> <Gast>: <Originalbetreff>`, Body =
  Originaltext mit kurzem Kopf zum Buchungsbezug (Code, Datum). Anhänge
  angehängt.
- `slug_only` (Slug passt, Buchung nicht): Weiterleitung an die Standardadresse
  des Betriebs, Vermerk „nicht zugeordnet", ohne Buchungsbezug.
- Zustellung über die Queue wie die übrigen Gästemails.

## Schleifen-, Bounce- und Missbrauchsschutz (alle als Einstellung)

- Header-Marker einer automatischen Mail (`Auto-Submitted`, `Precedence: bulk`,
  `List-Id`) und leerer Envelope-Absender (`<>`, Bounce) → `forward_status =
  skipped`, nur Protokoll. Das unterbindet Mailschleifen.
- Größengrenze der Nachricht (`max_message_bytes`). Darüber nur ein Hinweis an
  den Betrieb.
- Ratenbegrenzung je Absender und Tenant.
- Idempotenz über `postal_message_id` (unique) überspringt Doppelzustellungen.

## Einstellungen (Grundsatz „Nichts fest im Code")

Neuer Block in `config/swayy.php` → `guest_mail_relay`, Vorgaben an einer Stelle,
jede Einstellung mit Grenzen, Prüfung und verständlicher Meldung:

- `domain` (`SWAYY_GUEST_MAIL_RELAY_DOMAIN`), Vorgabe `''`. Leer heißt: Relay
  global nicht möglich, überall Fallback PR #45.
- `hmac_length`, Vorgabe 8, min 6, max 32.
- `separator_tag` (`+`) und `separator_hmac` (`.`).
- `inbound_public_key` (PEM) und optional `inbound_shared_secret`.
- `max_message_bytes`, Vorgabe moderat (z. B. 10 MB).
- `forward_subject_prefix`, Vorgabe „Antwort von".
- `auto_mail_headers` (Liste der Schleifen-Marker), Vorgabe
  `Auto-Submitted, Precedence: bulk, List-Id`.
- `rate_per_sender` und Zeitfenster.

Je Betrieb: `tenants.mail_relay_enabled` (bool, Vorgabe false) als Opt-in.
Migration, `$fillable`, Settings-Oberfläche und Validierung.

## Datenschutz (DSGVO)

- Neue Verarbeitung: eingehende Gastmails (Inhalt, Absenderadresse) werden bei
  Swayy gespeichert. Die Datenschutzerklärung (`resources/legal/datenschutz`,
  ausgerollt per `swayy:install-legal`) bekommt einen Abschnitt „Kommunikation
  über die Antwortadresse".
- Datenminimierung: Anhangsdateien bleiben ungespeichert, nur Metadaten.
  Kopplung an Buchung und Gast; Anonymisierung leert die personenbezogenen
  Felder, die Löschung der Buchung entfernt die Antworten.
- Zweckbindung: Zuordnung zur Buchung, Verlauf und Weiterleitung an den Betrieb.
- Postal (`mx.mailware.cc`) ist der annehmende Mailserver; die Verarbeitung dort
  ist über das bestehende Setup abgedeckt (siehe Serverprotokoll
  `mx.mailware.cc.md`).
- Die Betriebe werden an der Einstellung und in der Anwendungsdokumentation über
  die Antwortadresse informiert.

## Verständliche Fehlermeldungen

- Webhook: 401 bei Signaturfehler, 400 bei kaputtem Payload, sonst 200. Die
  Meldung nennt den Grund (Signatur, Payload) im erwarteten Format.
- Settings: Aktivieren ohne eingerichtete Relay-Domain → Meldung, dass die
  Antwortadresse noch einzurichten ist und der Betreiber sie bereitstellt.
- Weiterleitung fehlgeschlagen → `forward_status = failed`, im Verlauf sichtbar,
  mit Grund im Protokoll.

## Tests

- Unit `GuestReplyAddress`: Bauen und Parsen, Code mit `-`, falscher HMAC,
  fehlender Tag, andere `hmac_length` als die Vorgabe.
- Unit `PostalSignatureVerifier`: gültige und ungültige Signatur.
- Feature Webhook: gültige Antwort → gespeichert und weitergeleitet
  (`Mail::fake`), Reply-To = Gast, Datensatz `matched`.
- Feature unzuordenbar: `slug_only` → an den Betrieb mit Vermerk; Slug unbekannt
  → verworfen plus Protokoll.
- Feature Idempotenz: gleiche `postal_message_id` zweimal → einmal verarbeitet.
- Feature Schleifenschutz: `Auto-Submitted` → `skipped`, keine Weiterleitung.
- Feature Opt-in: Tenant aus → Reply-To bleibt direkt (PR #45); Tenant an plus
  Reservierungskontext → Relay-Adresse.
- Feature Anhänge: Metadaten gespeichert, Datei nicht, Weiterleitung trägt den
  Anhang.
- Feature Datenschutz: Anonymisierung leert die Felder, Löschung der Buchung
  entfernt die Antworten.

## DNS und Postal (erst nach Freigabe)

- DNS: Subdomain `antwort.swayy.de`, MX → `mx.mailware.cc`. SPF/DMARC für die
  Subdomain nach Bedarf.
- Postal: Mailserver/Domain `antwort.swayy.de`, Eingangsroute Catch-all →
  HTTP-Endpoint `https://swayy.de/webhooks/postal`. Public Key in die Einstellung
  übernehmen.
- Reihenfolge: Code und Einstellungen zuerst. Ohne scharfe Relay-Domain bleibt
  das Verhalten wie heute. Danach DNS und Postal, dann Relay je Testbetrieb
  aktivieren.
- Serverarbeiten an `mx.mailware.cc` und `swayy.de` kommen ins jeweilige
  Serverprotokoll.

## Außerhalb des Umfangs (YAGNI)

- Rückkanal über Swayy (voller Thread beidseitig) — Entscheidung 1.
- Anhangsdateien bei Swayy ablegen — Entscheidung 5.
- Mehrere Relay-Domains je Tenant oder eigene Domains der Betriebe — später.
- Antworten über SMS/WhatsApp — später.

## Offene Punkte

- Postal-Payload-Felder und Signatur-Header beim Einrichten final prüfen.
- Betreff- und Kopf-Vorlage der Weiterleitung: einfaches Muster als Einstellung,
  später eventuell ein `notification_template`.
