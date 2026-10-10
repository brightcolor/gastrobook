# Mail-Design: einheitliche, hochwertige Mails

Stand: 2026-10-10 · Entwurf zur Freigabe · Zweig `mail-design`, baut auf `new-ui` 1dda878

## Ziel

Jede Mail, die Swayy verschickt, sieht hochwertig und einheitlich aus, liest
sich auf dem Handy gut und kommt sauber durch Spamfilter. Gäste erkennen den
Betrieb, Betriebe erkennen Swayy. Swayy behält sein Erscheinungsbild.

Heute hat nur ein Teil der Mails einen HTML-Rahmen (fester Kopfbalken in
`#0f766e`, Text mit `<br>`, lange Links als Adresse). Die Link-Mails für Gäste
und alle Mails von Swayy an Betriebe sind reiner Text. Markenfarbe und Logo
eines Betriebs kommen in keiner Mail an.

## Entscheidungen aus dem Brainstorming

1. **Umfang:** alle drei Gruppen: Gast-Mails, Mails an den Betrieb, Mails von
   Swayy an Betriebe (Mathias, 10.10.2026).
2. **Weg A, Struktur erkennen:** Betriebe schreiben ihre Vorlagen weiter als
   normalen Text. Der Rahmen erkennt Angaben-Zeilen und Link-Zeilen und
   gestaltet sie als Tabelle und Knopf. Der Editor bleibt, wie er ist.
3. **Fußbereich der Gast-Mails:** Betrieb mit Adresse, Telefon, E-Mail,
   Impressum, Datenschutz und Grund der Mail, darunter leise „Reserviert mit
   Swayy“.
4. **Charakter:** gastlich und edel, mit viel Luft und sparsamer Markenfarbe.
   Schrift serifenlos und edel; nur der Schriftzug „Swayy“ steht in Fraunces.
5. **Ohne Logo:** Der Name des Betriebs steht als Wortmarke im Kopf.
6. **Spamfilter:** Inhalt sauber, nichts von außen geladen, Text- und HTML-Teil
   gleichwertig (Mathias: „spamfilter sollen die mails mögen“).
7. **Mails an den Betrieb** erscheinen im Swayy-Auftritt und bekommen einen
   Knopf in die Verwaltung.
8. **Überschrift je Mailart** setzt Swayy. Die Standardtexte werden so
   angepasst, dass sie die Überschrift nicht wiederholen; selbst geschriebene
   Vorlagen bleiben unverändert.
9. **Anrede der Mails an Betriebe:** eine Einstellung für ganz Swayy, Vorgabe
   du, passend zur Verwaltung (Mathias, 10.10.2026). Gast-Mails folgen weiter
   der Anrede des Standorts.

Freigegebener Entwurf: Bilder vom 10.10.2026 (Demo-Betrieb Trattoria Luna:
Reservierung bestätigt hell/dunkel/Handy, Bestätigungslink, Neue Reservierung,
Passwort zurücksetzen). Die beiden Swayy-Mails im Entwurf siezen; mit der
Vorgabe du erscheinen sie in der du-Fassung.

## Umfang

| Gruppe | Mail | Klasse / Versand | Auftritt | Anrede |
|---|---|---|---|---|
| Gast | 10 Vorlagen: bestätigt, angefragt, storniert, abgelehnt, Erinnerung, Feedback, Warteliste-Angebot, Zahlung offen, Zahlungserinnerung, Zahlung abgelaufen | `TemplatedMail` über `GuestMailSender::toGuest` | Betrieb | du/Sie je Standort |
| Gast | Event-Buchung bestätigt | `EventBookingService` → `toGuest` | Betrieb | du/Sie |
| Gast | Warteliste | `WaitlistService` → `toGuest` | Betrieb | du/Sie |
| Gast | Marketing-Kampagne (und Testversand) | `MarketingCampaignService` → `toGuest` | Betrieb | du/Sie |
| Gast | Bestätigungslink, Anmeldelink | `GuestLinkMail` (heute nur Text) | Betrieb | du/Sie |
| Betrieb | Neue Reservierung, Erstattungen | `GuestMailSender::toOperator` | Swayy | Einstellung (Vorgabe du) |
| Betrieb | Weitergeleitete Gast-Antwort | `ForwardedGuestReplyMail` (nur Text) | Swayy | Einstellung (Vorgabe du) |
| Swayy | Passwort zurücksetzen, Testphase endet, Abrechnungsanfrage (Bestätigung, Inhaber), Lastschrift-Meldungen | `PasswordResetMail`, `TrialExpiryWarningMail`, `BillingRequest*Mail`, `TemplatedMail` aus `DirectDebitController` und `GoCardlessWebhookController` | Swayy | Einstellung (Vorgabe du) |
| Swayy | Kundenpanel: Willkommen, freigegeben | `ProvisionedWelcomeMail`, `ProvisionedApprovedMail` | Swayy | Einstellung (Vorgabe du) |
| Swayy intern | Kontaktanfrage an das Swayy-Team | `ContactRequestMail` | Swayy | Einstellung (Vorgabe du) |

### Nicht im Umfang

- Vorschau der gestalteten Mail im Vorlagen-Editor der Betriebe
- Ein Feld „Website“ für Standorte (gibt es heute nicht; der Fußbereich zeigt Telefon und E-Mail)
- Wochentag in den Datumsplatzhaltern
- Umbau des Kampagnen-Editors
- SMS, PDF-Rechnungen

## Aufbau einer Mail

Von oben nach unten, alles zentriert in einer Spalte von höchstens 600 px:

1. **Kopf** außerhalb der Karte
   - Betrieb: Logo auf einer hellen Plakette, ohne Logo der Name als Wortmarke
     (serifenlos, halbfett, leicht enge Laufweite).
   - Swayy: Logo-Bild (Zeichen und Schriftzug „Swayy“ in Fraunces), je eine
     Fassung für hell und dunkel.
2. **Karte** (weiß, 1 px Linie, Ecken 16 px, Innenabstand 44 px, auf dem Handy 24 px)
   - Überschrift je Mailart (26 px, halbfett, Handy 23 px)
   - Text in Absätzen (16 px, Zeilenhöhe 1,6)
   - Angaben als Tabelle auf zart getönter Fläche (Ecken 12 px, Haarlinien
     zwischen den Zeilen, Bezeichnung links gedämpft, Wert rechts halbfett)
   - Knopf (Markenfarbe, weiße Schrift, Ecken 6 px; auf dem Handy volle Breite);
     weitere Links als zweiter, schlichter Knopf
   - Trennlinie und Hinweis in kleiner, gedämpfter Schrift (z. B. Gültigkeit
     eines Links)
   - Abschluss und Unterschrift
3. **Fußbereich** außerhalb der Karte, zentriert, 13 px (siehe unten)

Schrift: Systemschrift des Geräts mit Stapel
`-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif`
(iPhone/Mac: San Francisco, Windows: Segoe UI, Android: Roboto). Es wird keine
Schrift nachgeladen.

Farben: Stone-Töne aus der Swayy-Oberfläche für Flächen und Text; die
Markenfarbe nur für Knopf, getönte Fläche, Haarlinien darin und Links im Text.

## Struktur aus dem Text (Weg A)

Eingabe ist der fertige Text nach dem Einsetzen der Platzhalter. Ein Parser
zerlegt ihn in Bausteine; der HTML-Rahmen zeichnet die Bausteine. Der Textteil
der Mail bleibt der Text selbst.

Regeln, in dieser Reihenfolge:

1. Zeilenenden vereinheitlichen, Leerzeichen am Zeilenende entfernen, mehr als
   eine Leerzeile zu einer zusammenfassen.
2. **Leere Angaben fallen weg:** Eine Zeile „Bezeichnung:“ ohne Wert (etwa
   weil `{payment_link}` leer ist) erscheint weder im HTML- noch im Textteil.
3. Blöcke entstehen an Leerzeilen.
4. **Link-Zeile → Knopf:** eine Zeile „Beschriftung: https://…“ oder eine Zeile
   „Beschriftung:“ direkt gefolgt von einer Zeile, die nur aus einem Link
   besteht, bei einer Beschriftung bis 60 Zeichen (längere bleiben Absatz mit
   Link). Die Beschriftung wird zum Knopftext. Der erste Knopf einer Mail ist
   der Hauptknopf, weitere sind schlichte Knöpfe. Link-Zeilen innerhalb eines
   Angaben-Blocks werden herausgelöst und folgen der Tabelle.
5. **Angaben-Block → Tabelle:** ein Block, in dem jede verbleibende Zeile die
   Form „Bezeichnung: Wert“ hat (Bezeichnung höchstens 40 Zeichen, ohne Link
   im Wert) und der mindestens 2 solche Zeilen hat. Leerzeichen zum Ausrichten
   („Datum:    24.11.2026“) werden entfernt. Der erste Doppelpunkt trennt, „Uhrzeit:
   18:00 Uhr“ bleibt also richtig.
6. **Trennlinie:** eine Zeile nur aus `-`, `—` oder `=` (mindestens 3 Zeichen).
7. **Alles andere ist Absatz**, Zeilenumbrüche bleiben. Links (nur `http`/`https`)
   im Fließtext werden anklickbar; der sichtbare Text ist die Adresse selbst,
   umbrechbar, damit nichts über den Rand ragt.
8. **Abmeldelink:** Ein Link auf die Abmeldeadresse einer Kampagne wird nie
   zum Knopf. Den Abschnitt, den Swayy anhängt, wenn der Betrieb keinen
   `{unsubscribe_link}` gesetzt hat, zeigt der HTML-Teil nur im Fußbereich
   („Keine Neuigkeiten mehr? Abmelden“); im Textteil bleibt er stehen.

Alle Inhalte werden maskiert. Nur das Markup, das der Rahmen selbst erzeugt,
geht ungefiltert in die Mail. Text, der in kein Muster passt, wird nie
verworfen, er bleibt Absatz.

## Überschriften je Mailart

Jeder Versandweg gibt eine Mailart mit. Die Überschriften stehen in
`lang/de/mails.php`, je in du und Sie. Gast-Mails wählen nach der Anrede des
Standorts, Mails an Betriebe und Swayy-Mails nach `SWAYY_MAIL_OWNER_ADDRESS`.
Ebenda steht je Mailart der Grund für den Fußbereich, ebenfalls in beiden
Formen.

| Mailart | du | Sie |
|---|---|---|
| `reservation_confirmed` | Deine Reservierung ist bestätigt | Ihre Reservierung ist bestätigt |
| `reservation_requested` | Deine Anfrage ist angekommen | Ihre Anfrage ist angekommen |
| `reservation_cancelled` | Deine Reservierung ist storniert | Ihre Reservierung ist storniert |
| `reservation_rejected` | Antwort auf deine Anfrage | Antwort auf Ihre Anfrage |
| `reservation_reminder` | Erinnerung an deine Reservierung | Erinnerung an Ihre Reservierung |
| `feedback_request` | Wie war dein Besuch? | Wie war Ihr Besuch? |
| `waitlist_offer` | Ein Tisch ist frei geworden | Ein Tisch ist frei geworden |
| `payment_pending` | Bitte schließ deine Zahlung ab | Bitte schließen Sie Ihre Zahlung ab |
| `payment_reminder` | Deine Zahlung ist noch offen | Ihre Zahlung ist noch offen |
| `payment_expired` | Die Zahlungsfrist ist abgelaufen | Die Zahlungsfrist ist abgelaufen |
| `event_booking_confirmed` | Deine Buchung ist bestätigt | Ihre Buchung ist bestätigt |
| `guest_verify` | Bitte bestätige deine E-Mail-Adresse | Bitte bestätigen Sie Ihre E-Mail-Adresse |
| `guest_login` | Dein Anmeldelink | Ihr Anmeldelink |
| `operator_new_reservation` | Neue Reservierung für {Standort} | Neue Reservierung für {Standort} |
| `operator_refund` | Erstattung zu einer Reservierung | Erstattung zu einer Reservierung |
| `operator_guest_reply` | Antwort von {Gast} | Antwort von {Gast} |
| `swayy_password_reset` | Neues Passwort festlegen | Neues Passwort festlegen |
| `swayy_trial_expiry` | Deine Testphase endet bald | Ihre Testphase endet bald |
| `swayy_billing_confirm` | Bitte bestätige deine Anfrage | Bitte bestätigen Sie Ihre Anfrage |
| `swayy_billing_owner` | Neue Tarifanfrage von {Betrieb} | Neue Tarifanfrage von {Betrieb} |
| `swayy_provisioned_welcome` | Willkommen bei Swayy | Willkommen bei Swayy |
| `swayy_provisioned_approved` | Dein Zugang ist freigeschaltet | Ihr Zugang ist freigeschaltet |
| `swayy_contact` | Neue Kontaktanfrage | Neue Kontaktanfrage |
| `swayy_notice` | Betreff ohne den Zusatz „– Swayy“ | Betreff ohne den Zusatz „– Swayy“ |

`swayy_notice` gilt für die Lastschrift-Meldungen, deren Betreff je Anlass
wechselt. Marketing-Kampagnen haben keine Überschrift: ihr Inhalt gehört dem
Betrieb. Eine unbekannte Mailart ergibt keine Überschrift.

## Marke: Farbe und Logo

**Farbe.** Quelle ist `tenants.brand_primary_color`, sonst
`swayy.ui.default_brand_color` (`#0f766e`). Swayy-Mails nutzen immer die
Swayy-Farbe. Aus der Markenfarbe berechnet `MailTheme`:

- Knopffläche: die Markenfarbe; erreicht Weiß darauf nicht das
  Mindestkontrastverhältnis (Vorgabe 4,5:1), wird sie schrittweise
  abgedunkelt, bis es reicht.
- Getönte Fläche und Haarlinien: Markenfarbe zu 5,5 % bzw. 16 % auf Weiß.
- Dunkelmodus: Fläche und Linien aus der Markenfarbe auf Stone-900,
  Linkfarbe aufgehellt, bis der Kontrast reicht.
- Eine ungültige Farbe gilt als nicht gesetzt (Vorgabe greift), mit Logeintrag
  in verständlichen Worten.

**Logo.** Reihenfolge: Logo des Standorts, Logo des Betriebs, sonst Wortmarke.
Das Logo wird als Inline-Bild (CID) eingebettet, mit `alt` = Name des Betriebs,
Höhe 44 px, Breite höchstens 220 px, immer auf einer hellen Plakette (damit
dunkle Logos im Dunkelmodus lesbar bleiben). Eingebettet werden nur PNG, JPEG
und GIF bis zur eingestellten Größe; andere Formate oder größere Dateien
ergeben die Wortmarke.

Das Swayy-Logo liegt als PNG in doppelter Auflösung im Repo (hell und dunkel,
aus `public/logo.svg` erzeugt) und wird ebenso eingebettet. „Reserviert mit
Swayy“ im Gast-Fußbereich nutzt ein kleines Schriftzug-Bild mit `alt="Swayy"`.

## Fußbereich

**Gast-Mails (Auftritt Betrieb):**

1. Name des Standorts (halbfett)
2. Straße · PLZ Ort
3. Telefon (`tel:`-Link) · E-Mail (`mailto:`-Link)
4. Impressum · Datenschutz (Links des Betriebs)
5. Grund der Mail in Anrede des Standorts, z. B. „Du bekommst diese Mail, weil
   du bei Trattoria Luna einen Tisch reserviert hast.“
6. Bei Kampagnen: „Keine Neuigkeiten mehr? Abmelden“ mit dem Abmeldelink
7. „Reserviert mit Swayy“ mit Link auf die Swayy-Startseite (abschaltbar)

Jede Zeile erscheint nur, wenn ihre Angaben vorliegen. Fehlt alles außer dem
Namen, bleiben Name, Grund und Swayy-Hinweis.

**Mails an den Betrieb und Swayy-Mails:** Schriftzug Swayy, „Hilfe & Kontakt ·
Impressum · Datenschutz“ (Swayy-Routen), Grund der Mail.

**Textteil:** Der Fußbereich steht auch im Textteil, unter einer Zeile `--`,
mit ausgeschriebenen Adressen statt Links.

## Zustellbarkeit

- Jede Mail ist mehrteilig (Text und HTML) mit gleichem Inhalt.
- Nichts wird von außen geladen: keine Schriften, keine Bilder per URL, kein
  Zählpixel. Bilder nur eingebettet, mit `alt`, Breite und Höhe.
- Kein versteckter Text, keine Schrift in Hintergrundfarbe, kein
  Vorschautext per `display:none`.
- Sichtbarer Linktext ist nie eine andere Adresse als das Ziel.
- Kein JavaScript, keine Formulare, kein externes CSS; Stile inline plus ein
  kleiner `<style>`-Block für Dunkelmodus und Handy.
- HTML-Teil unter 60 KB (Gmail kürzt ab 102 KB), `lang="de"`, `charset=utf-8`,
  `<title>` gesetzt.
- Absender bleibt `MAIL_FROM_ADDRESS` (SPF/DKIM), Anzeigename Betrieb bzw.
  „Swayy“; Reply-To wie bisher.
- **Kampagnen:** Kopfzeilen `List-Unsubscribe` (HTTPS-Link) und
  `List-Unsubscribe-Post: List-Unsubscribe=One-Click` nach RFC 8058, dazu ein
  neuer signierter POST-Endpunkt für die Abmeldung per Klick ohne
  Bestätigungsseite.
- Texte ohne Großbuchstaben-Wörter, ohne gehäufte Ausrufezeichen, ohne
  „Klicken Sie hier“.

## Dunkelmodus und Handy

- `color-scheme: light dark`; Regeln unter `@media (prefers-color-scheme: dark)`
  und für Outlook.com (`[data-ogsc]`): Seite Stone-950, Karte Stone-900, Text
  hell, getönte Fläche aus der Markenfarbe, Swayy-Logo in der dunklen Fassung.
- Unter 520 px: Innenabstand 24 px, Überschrift 23 px, Knopf volle Breite.
- Lange Werte (Mailadressen, Links) brechen um, statt die Karte zu verbreitern.
- Clients ohne Media Queries (Teile von Outlook) zeigen die helle Fassung in
  voller Breite bis 600 px.

## Texte

- **Standardvorlagen** (`NotificationTemplateRenderer::defaults`, du und Sie)
  werden auf die Überschriften abgestimmt: kein Satz, der die Überschrift
  wiederholt; Link-Zeilen mit klarer Beschriftung („Reservierung ändern oder
  stornieren: {cancel_link}“, „Jetzt bezahlen: {payment_link}“). Selbst
  geschriebene Vorlagen in `notification_templates` bleiben unangetastet
  (auf swayy.de gibt es zum Stand 08.10.2026 keine).
- **Event-, Wartelisten- und Link-Mails** bekommen dieselbe Abstimmung; die
  Wartelisten-Mail etwa nennt die Frist im Satz und hat die Link-Zeile „Tisch
  bestätigen: {Link}“, damit der Knopf eine kurze Beschriftung trägt.
- **Link-Mails** folgen durchgehend der Anrede des Standorts, auch im Hinweis
  zur Gültigkeit. (Die Aufgabe „Anrede und Seitentitel im Gast-Buchungsablauf
  angleichen“ ändert `mail/guest-link.blade.php` auf einem eigenen Zweig; beim
  Zusammenführen gilt deren Anrede-Regel, die neue Gestaltung kommt von hier.)
- **Mails an Betriebe und Swayy-Mails** bekommen je eine du- und eine
  Sie-Fassung; `SWAYY_MAIL_OWNER_ADDRESS` wählt (Vorgabe du). Texte mit
  Angaben-Zeilen und Link-Zeilen, damit der Parser Tabelle und Knopf erkennt. Die
  Unterstreichung mit `====` in der Testphasen-Mail entfällt (die Überschrift
  übernimmt).
- **Mail an den Betrieb „Neue Reservierung“:** Angaben in zwei Blöcken
  (Reservierung, Gast), dazu „Reservierung öffnen: {Link in die Verwaltung}“.
- Alle Texte mit echten Umlauten und positiv formuliert.

## Einstellungen

Neuer Abschnitt `swayy.mail` in `config/swayy.php` (über `EnvSetting`, mit
Grenzen und Hinweis), Einträge in `.env.example`:

| Einstellung | Vorgabe | Grenzen | Wirkung |
|---|---|---|---|
| `SWAYY_MAIL_OWNER_ADDRESS` | `du` | `du`, `Sie` | Anrede in Mails an Betriebe und in Swayy-Mails |
| `SWAYY_MAIL_POWERED_BY` | `true` | bool | „Reserviert mit Swayy“ in Gast-Mails |
| `SWAYY_MAIL_LOGO_MAX_KB` | 200 | 16–1024 | größte Logodatei, die eingebettet wird |
| `SWAYY_MAIL_LOGO_HEIGHT` | 44 | 24–96 | Anzeigehöhe des Logos in px |
| `SWAYY_MAIL_CONTRAST_MIN` | 4.5 | 3.0–7.0 | Mindestkontrast für Knopftext und Links |
| `SWAYY_MAIL_DETAILS_MIN_ROWS` | 2 | 1–6 | Zeilen, ab denen ein Block zur Tabelle wird |
| `SWAYY_MAIL_LABEL_MAX` | 40 | 10–80 | längste Bezeichnung in einer Angaben-Zeile |

Ohne Umgebungsvariable, aber in derselben Datei: Stone-Palette, Stärke der
Tönung (0,055) und der Haarlinien (0,16), Schriftstapel, Breite 600 px,
Umbruchbreite 520 px.

## Technik

| Baustein | Aufgabe |
|---|---|
| `App\Mail\Layout\TextBlocks` | Parser: Text → Liste von Bausteinen (`Paragraph`, `Details`, `Button`, `Divider`) nach den Regeln oben; liefert auch den bereinigten Textteil |
| `App\Mail\Layout\MailTheme` | Farben aus Markenfarbe und Einstellungen, Kontrastprüfung, Dunkelmodus-Werte |
| `App\Mail\Layout\MailFrame` | Wert-Objekt: Auftritt (`tenant`/`swayy`), Mailart, Anrede, Überschrift, Theme, Logo-Pfad, Fußbereich-Daten, Abmeldelink; serialisierbar für die Queue |
| `App\Mail\Layout\MailFrameFactory` | baut den Rahmen aus Betrieb/Standort/Mailart oder für Swayy |
| `resources/views/mail/layout/*.blade.php` | Rahmen, Kopf, Bausteine, Fußbereich (HTML) und Textfassung |
| `resources/images/mail/*` | Swayy-Logo hell/dunkel, kleiner Schriftzug (PNG @2x) |

- `TemplatedMail` und `GuestLinkMail` bekommen einen `MailFrame` und rendern
  HTML über den Rahmen; der Textteil kommt aus dem bereinigten Text plus Fußbereich.
- Alle übrigen Mailables rendern ihren bisherigen Text durch denselben Rahmen;
  sie geben Mailart und Auftritt Swayy mit.
- `GuestMailSender::toGuest`/`toOperator` nehmen die Mailart entgegen; alle
  Aufrufer geben sie mit. `toOperator` setzt den Auftritt Swayy und den
  Anzeigenamen „Swayy“.
- Bilder werden beim Rendern mit `$message->embed()` eingebettet; der
  `MailFrame` trägt nur Pfade, damit Mails in der Queue klein bleiben.
- Ein Artisan-Befehl `mail:vorschau` schreibt jede Mailart mit Demo-Daten als
  HTML- und Textdatei nach `storage/app/mail-vorschau/` (für Prüfung und
  Bildschirmfotos; schickt nichts).

## Tests und Prüfung

- **Parser:** Tabellen-Erkennung (Ausrichtungs-Leerzeichen, Doppelpunkt im
  Wert, zu lange Bezeichnung, Einzelzeile), Link-Zeilen in beiden Formen,
  herausgelöste Links aus Angaben-Blöcken, leere Angaben, Trennlinie,
  Links im Fließtext, Maskierung von HTML und `javascript:`, Text ohne Muster,
  leerer Text, Einstellungen mit anderen Werten (`DETAILS_MIN_ROWS` 1 und 3,
  `LABEL_MAX` 10).
- **Theme:** Kontrast mit heller (`#facc15`), mittlerer und dunkler Markenfarbe,
  ungültige Farbe, `CONTRAST_MIN` 7.
- **Mails:** jede Mailart rendert HTML und Text; Überschrift in du und Sie;
  Knopf mit richtigem Ziel; Fußbereich mit und ohne Adresse/Telefon/Impressum;
  `SWAYY_MAIL_POWERED_BY=false`; Mails an Betriebe und Swayy-Mails mit
  `SWAYY_MAIL_OWNER_ADDRESS` du und Sie (Überschrift, Text, Grund); keine `src="http`, kein `<script`, kein
  `display:none`; Kampagnen mit beiden Abmelde-Kopfzeilen; One-Click-POST meldet
  ab und ist ohne gültige Signatur wirkungslos; Logo eingebettet und
  Wortmarke als Ersatz (SVG, zu groß, fehlt).
- **Sicht:** `mail:vorschau` für alle Mailarten, Bildschirmfotos hell, dunkel
  und 390 px, Abgleich mit dem freigegebenen Entwurf.
- **Spam:** jede Mailart als `.eml` durch SpamAssassin in einem
  Wegwerf-Container aus dem offiziellen Debian-Abbild (Paket `spamassassin`,
  ohne Netzwerktests); Ziel unter 1 Punkt, gefundene Regeln werden behoben.
- **Ende zu Ende:** auf dev-swayy (nach Freigabe des Abbilds) Testbuchung,
  Bestätigung im Admin, Mails in Mailpit ansehen.

## Branch und Auslieferung

- Zweig `mail-design` von `new-ui` 1dda878, weil die Kundenpanel-Mails nur
  dort existieren. Ausrollen nach `new-ui`; vorher auf den dann aktuellen
  `main` bringen.
- Version und Changelog beim Ausrollen (Minor-Version, neue Funktion).
- Kein Push und kein Ausrollen ohne Freigabe von Mathias.

## Offene Punkte

- Zusammenführen mit der Aufgabe „Anrede und Seitentitel im
  Gast-Buchungsablauf angleichen“ (siehe Texte).
