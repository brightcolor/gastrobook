<?php

use App\Models\Guest;
use App\Models\Location;
use App\Models\Reservation;
use App\Support\EnvSetting;

// Kleinster und größter Wert eines Paares stehen unabhängig in der .env. Der
// größte Wert liegt nie unter dem kleinsten, sonst lehnte das Feld jeden Wert ab.
$bookingDurationMin = EnvSetting::integer('SWAYY_BOOKING_DURATION_MIN', default: 30, min: 5, max: 240);
$bookingRuleDurationMin = EnvSetting::integer('SWAYY_BOOKING_RULE_DURATION_MIN', default: 15, min: 5, max: 240);
$serviceDurationMin = EnvSetting::integer('SWAYY_SERVICE_DURATION_MIN', default: 5, min: 1, max: 60);
// Die Kennung aus Buchstaben ist nie länger als die längste Kennung überhaupt.
$smsSenderIdMax = EnvSetting::integer('SWAYY_SMS_SENDER_ID_MAX', default: 16, min: 3, max: 16);

return [
    /*
    |--------------------------------------------------------------------------
    | Erster Oberadmin (Bootstrap)
    |--------------------------------------------------------------------------
    |
    | Wird beim ersten Start von `php artisan swayy:create-admin --if-missing`
    | genutzt, um einen Plattform-Oberadmin anzulegen, falls noch keiner existiert.
    | Leer lassen, um den Admin manuell anzulegen.
    |
    */
    'admin' => [
        'email' => env('SWAYY_ADMIN_EMAIL'),
        'password' => env('SWAYY_ADMIN_PASSWORD'),
        'name' => env('SWAYY_ADMIN_NAME', 'Administrator'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Live-Board
    |--------------------------------------------------------------------------
    |
    | Echtzeit-Updates per Server-Sent Events (SSE). Eine offene SSE-Verbindung
    | belegt einen PHP-Worker – auf dem Single-Worker-Dev-Server (`php artisan
    | serve`) daher auf false setzen; dann nutzt das Board Polling.
    |
    */
    'board' => [
        'sse' => env('SWAYY_BOARD_SSE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stammgast-Erkennung
    |--------------------------------------------------------------------------
    |
    | Ab wie vielen gezählten Besuchen ein Gast automatisch als Stammgast gilt
    | (zusätzlich zum manuellen VIP-Flag).
    |
    */
    'regular_after_visits' => (int) env('SWAYY_REGULAR_AFTER_VISITS', 5),

    /*
    |--------------------------------------------------------------------------
    | Plattform-Owner E-Mail
    |--------------------------------------------------------------------------
    |
    | Wohin Trial-Ablauf-Warnungen und bestätigte Billing-Anfragen gesendet
    | werden. Im SaaS-Betrieb auf die eigene E-Mail setzen.
    |
    */
    'owner_email' => env('SWAYY_OWNER_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Rechtstexte (Markdown)
    |--------------------------------------------------------------------------
    |
    | Impressum, Datenschutz und AGB liegen als Markdown unter
    | storage/app/private/legal/<key>.md (bind-gemountet → auf dem Host editierbar).
    | Fehlende Dateien legt `php artisan swayy:install-legal` aus den
    | Vorlagen in resources/legal an. Der Controller liest sie pro Request
    | frisch – Änderungen wirken sofort, ohne Neustart.
    |
    */
    'legal' => [
        'documents' => [
            'impressum' => 'Impressum',
            'datenschutz' => 'Datenschutzerklärung',
            'agb' => 'AGB',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ausgehende Anfragen an Adressen der Betriebe
    |--------------------------------------------------------------------------
    |
    | Webhooks und die MailWizz-Anbindung rufen Adressen auf, die ein Betrieb
    | selbst einträgt. Erlaubt sind https-Ziele, deren Name auf global
    | geroutete Adressen zeigt. Geprüft wird beim Speichern und vor jedem
    | Aufruf; der Aufruf geht genau an die geprüfte Adresse und folgt keiner
    | Weiterleitung (App\Support\OutboundUrlGuard).
    |
    | allowed_networks: Netze, die trotzdem erreichbar sein dürfen, etwa eine
    | MailWizz-Installation im internen Netz einer Selbstinstallation.
    | Komma-Liste aus IP-Adressen und Netzen in CIDR-Schreibweise. Ein Eintrag
    | gibt das Netz für alle Betriebe dieser Installation frei. Vorgabe: leer,
    | also nur öffentliche Ziele.
    |
    */
    'outbound' => [
        'allowed_networks' => EnvSetting::networks('SWAYY_OUTBOUND_ALLOWED_NETWORKS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy für die ausgelieferten Seiten
    |--------------------------------------------------------------------------
    |
    | Die Grundrichtlinie ohne `frame-ancestors` – die setzt die Middleware
    | App\Http\Middleware\SecurityHeaders je Seite: `'none'` für Verwaltung,
    | Plattform und Anmeldung, `*` für die öffentlichen Buchungsseiten und die
    | Einbett-Widgets.
    |
    | `script-src`/`style-src` behalten `'unsafe-inline'`: Die Oberfläche nutzt
    | Inline-Stilattribute und Inline-Ereignisbehandlung (onclick usw.). Eine
    | strengere Fassung über Nonces ist ein eigener Umbau. Changebar über
    | `SWAYY_CSP_BASE`, etwa um einen Zahlungsanbieter als `frame-src` zu
    | ergänzen.
    |
    */
    'security' => [
        'csp_base' => env('SWAYY_CSP_BASE', implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self' 'unsafe-inline'",
            "connect-src 'self'",
            "frame-src 'self'",
        ])),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mails an Gäste: Absendername und Antwortadresse
    |--------------------------------------------------------------------------
    |
    | Absender bleibt MAIL_FROM_ADDRESS, damit SPF und DKIM passen. Antwortet
    | ein Gast, geht die Mail an die Antwortadresse (Reply-To) des Betriebs.
    | Trägt der Betrieb unter Einstellungen › Gästekommunikation › Absender &
    | Antworten nichts ein, gilt der erste gefüllte Wert aus diesen Listen.
    |
    | Ein unbekannter Eintrag legt nichts lahm: Es gilt die Vorgabe, und eine
    | Warnung im Log nennt die Variable. „-“ heißt: ohne Ersatz.
    |
    | from_name_max, reply_to_max: längster Absendername und längste
    | Antwortadresse in Zeichen (je 10–255 und 30–255; die Spalten
    | tenants.mail_from_name und mail_reply_to sind varchar(255)).
    |
    */
    'guest_mail' => [
        // Ersatz für die Antwortadresse, in dieser Reihenfolge.
        // Erlaubt: location_email (E-Mail des Standorts aus den Stammdaten),
        // owner_notification_email (Adresse für Betreiber-Benachrichtigungen).
        // Vorgabe: location_email,owner_notification_email
        'reply_to_fallbacks' => EnvSetting::choiceList(
            'SWAYY_GUEST_MAIL_REPLY_TO_FALLBACKS',
            default: ['location_email', 'owner_notification_email'],
            allowed: ['location_email', 'owner_notification_email'],
        ),

        // Ersatz für den Absendernamen, in dieser Reihenfolge. Ohne Treffer
        // gilt MAIL_FROM_NAME. Erlaubt: location_name, tenant_name.
        // Vorgabe: location_name,tenant_name
        'from_name_fallbacks' => EnvSetting::choiceList(
            'SWAYY_GUEST_MAIL_FROM_NAME_FALLBACKS',
            default: ['location_name', 'tenant_name'],
            allowed: ['location_name', 'tenant_name'],
        ),

        // Benachrichtigung an den Betrieb über eine neue Buchung: Antworten
        // gehen direkt an den Gast. true oder false, Vorgabe true.
        'owner_reply_to_guest' => (bool) env('SWAYY_GUEST_MAIL_OWNER_REPLY_TO_GUEST', true),

        'from_name_max' => EnvSetting::integer('SWAYY_GUEST_MAIL_FROM_NAME_MAX', default: 100, min: 10, max: 255),
        'reply_to_max' => EnvSetting::integer('SWAYY_GUEST_MAIL_REPLY_TO_MAX', default: 200, min: 30, max: 255),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gästeprofile: Zuordnung neuer Buchungen
    |--------------------------------------------------------------------------
    |
    | Eine Buchung fällt dem Profil mit derselben E-Mail-Adresse zu. Die hier
    | eingetragenen Buchungswege dürfen ein Profil zusätzlich über die
    | Telefonnummer finden, wenn keine Adresse passt. Online-Buchungen,
    | Wartelisten-Angebote und Veranstaltungen ordnen immer nur über die
    | Adresse zu: Die Nummer im öffentlichen Formular kann jeder eintippen,
    | und mit ihr bekäme ein Fremder das Profil samt Kundenkonto eines Gastes.
    | Passt dort nur die Nummer, entsteht ein neues Profil. Das Personal sieht
    | es im Gästeprofil unter „Mögliche Dublette“ und führt beide bei Bedarf
    | zusammen.
    |
    | Ein unbekannter Eintrag legt nichts lahm: Es gilt die Vorgabe, und eine
    | Warnung im Log nennt die Variable. „-“ heißt: nur über die Adresse.
    |
    */
    'guest_profiles' => [
        // Erlaubt: manual (Buchung im Reservierungsbuch), phone (telefonisch
        // angenommen), walk_in (Laufkundschaft), api (Schnittstelle),
        // phone_assistant (Telefonassistent über die Schnittstelle).
        // Vorgabe: manual,phone,walk_in,api,phone_assistant
        'phone_match_sources' => EnvSetting::choiceList(
            'SWAYY_GUEST_PHONE_MATCH_SOURCES',
            default: ['manual', 'phone', 'walk_in', 'api', 'phone_assistant'],
            allowed: ['manual', 'phone', 'walk_in', 'api', 'phone_assistant'],
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Zustellung an die Endpunkte der Betriebe (App\Jobs\DeliverWebhook).
    | Lehnt die Zielprüfung eine Adresse ab, etwa weil sie in ein internes Netz
    | führt, schaltet sich der Endpunkt sofort ab. Lässt sich der Name nur
    | gerade nicht auflösen, zählt das als Fehlversuch wie eine Antwort mit
    | Fehlerstatus und wird wiederholt.
    |
    | Ein ungültiger Wert legt nichts lahm: Es gilt die Vorgabe oder die
    | nächste Grenze, und eine Warnung im Log nennt die Variable.
    |
    */
    'webhooks' => [
        // Wartezeit je Zustellung in Sekunden. 1 bis 60, Vorgabe 10.
        'timeout' => EnvSetting::integer('SWAYY_WEBHOOK_TIMEOUT', default: 10, min: 1, max: 60),

        // Versuche je Ereignis. 1 bis 10, Vorgabe 5.
        'tries' => EnvSetting::integer('SWAYY_WEBHOOK_TRIES', default: 5, min: 1, max: 10),

        // Wartezeiten zwischen den Versuchen in Sekunden, Komma-Liste. Der
        // letzte Wert gilt für alle weiteren Versuche. Je Wert 1 bis 86400,
        // Vorgabe 60,300,1800,7200.
        'backoff' => EnvSetting::integerList('SWAYY_WEBHOOK_BACKOFF', default: [60, 300, 1800, 7200], min: 1, max: 86400),

        // Nach so vielen gescheiterten Ereignissen in Folge schaltet sich ein
        // Endpunkt ab. Gezählt wird ein Ereignis, wenn alle Versuche
        // gescheitert sind. 1 bis 1000, Vorgabe 20.
        'disable_after' => EnvSetting::integer('SWAYY_WEBHOOK_DISABLE_AFTER', default: 20, min: 1, max: 1000),

        // Zeichen der Antwort einer Gegenstelle, die das Zustellprotokoll
        // aufbewahrt. 0 bis 10000, Vorgabe 2000.
        'response_limit' => EnvSetting::integer('SWAYY_WEBHOOK_RESPONSE_LIMIT', default: 2000, min: 0, max: 10000),

        // Einträge, die das Zustellprotokoll auf der Webhook-Seite zeigt.
        // 1 bis 500, Vorgabe 25.
        'log_entries' => EnvSetting::integer('SWAYY_WEBHOOK_LOG_ENTRIES', default: 25, min: 1, max: 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Antwortadresse über Swayy (eingehende Gästeantworten)
    |--------------------------------------------------------------------------
    |
    | Gäste antworten an <slug>+<code>.<hmac>@<domain>. Postal nimmt die Mail an
    | und ruft POST /webhooks/postal. Leere Domain heißt: Relay aus, es gilt das
    | direkte Reply-To (guest_mail). Ein ungültiger Wert legt nichts lahm: Es
    | gilt die Vorgabe oder die nächste Grenze, eine Warnung nennt die Variable.
    |
    */
    'guest_mail_relay' => [
        'domain' => env('SWAYY_GUEST_MAIL_RELAY_DOMAIN', ''),
        'hmac_length' => EnvSetting::integer('SWAYY_GUEST_MAIL_RELAY_HMAC_LENGTH', default: 8, min: 6, max: 32),
        'separator_tag' => env('SWAYY_GUEST_MAIL_RELAY_SEP_TAG', '+'),
        'separator_hmac' => env('SWAYY_GUEST_MAIL_RELAY_SEP_HMAC', '.'),
        // Öffentlicher Schlüssel, mit dem Postal signiert: der Wert hinter p=
        // aus `postal default-dkim-record` auf dem Postal-Server. Angenommen
        // werden auch der ganze Eintrag „v=DKIM1; …; p=…" und ein PEM-Block.
        'inbound_public_key' => env('SWAYY_GUEST_MAIL_RELAY_PUBLIC_KEY', ''),
        // Verfahren der Signatur: sha256 (Vorgabe) oder sha1.
        'inbound_signature_algo' => EnvSetting::choiceList('SWAYY_GUEST_MAIL_RELAY_SIGNATURE_ALGO', default: ['sha256'], allowed: ['sha256', 'sha1'])[0] ?? 'sha256',
        // Kopfzeile mit der Signatur. Postal schickt beide: X-Postal-Signature-256
        // (SHA-256) und X-Postal-Signature (SHA-1). Leer: passend zum Verfahren.
        'inbound_signature_header' => env('SWAYY_GUEST_MAIL_RELAY_SIGNATURE_HEADER', ''),
        // Optionales Geheimnis in der Kopfzeile X-Postal-Shared-Secret, etwa für
        // einen vorgeschalteten Proxy. Postal selbst schickt nur die Signatur.
        'inbound_shared_secret' => env('SWAYY_GUEST_MAIL_RELAY_SHARED_SECRET', ''),
        'max_message_bytes' => EnvSetting::integer('SWAYY_GUEST_MAIL_RELAY_MAX_BYTES', default: 10485760, min: 65536, max: 52428800),
        'forward_subject_prefix' => env('SWAYY_GUEST_MAIL_RELAY_SUBJECT_PREFIX', 'Antwort von'),
        // Marker einer automatischen Mail (Schleifenschutz). Komma-Liste, überschreibbar.
        'auto_mail_headers' => array_values(array_filter(array_map('trim', explode(
            ',',
            env('SWAYY_GUEST_MAIL_RELAY_AUTO_HEADERS', 'Auto-Submitted,Precedence,List-Id,List-Unsubscribe')
        )))),
        'rate_per_sender' => EnvSetting::integer('SWAYY_GUEST_MAIL_RELAY_RATE_PER_SENDER', default: 20, min: 1, max: 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Newsletter-Anbindung (MailWizz)
    |--------------------------------------------------------------------------
    |
    | Ein ungültiger Wert legt nichts lahm: Es gilt die Vorgabe oder die
    | nächste Grenze, und eine Warnung im Log nennt die Variable.
    |
    */
    'newsletter' => [
        // Wartezeit je Anfrage an MailWizz in Sekunden, auch beim
        // Verbindungstest auf der Einstellungsseite. 1 bis 60, Vorgabe 10.
        'timeout' => EnvSetting::integer('SWAYY_NEWSLETTER_TIMEOUT', default: 10, min: 1, max: 60),

        // Versuche je Übertragung eines Gastes. 1 bis 10, Vorgabe 3.
        'tries' => EnvSetting::integer('SWAYY_NEWSLETTER_TRIES', default: 3, min: 1, max: 10),

        // Wartezeiten zwischen den Versuchen in Sekunden, Komma-Liste. Der
        // letzte Wert gilt für alle weiteren Versuche. Je Wert 1 bis 86400,
        // Vorgabe 60,600.
        'backoff' => EnvSetting::integerList('SWAYY_NEWSLETTER_BACKOFF', default: [60, 600], min: 1, max: 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Protokoll
    |--------------------------------------------------------------------------
    |
    | per_page: Einträge je Seite im Protokoll (10–500).
    | entities: Kürzel in der Adresse (?entity=…&entity_id=…) und das Modell,
    | dessen Änderungen der Filter „nur dieser Eintrag“ zeigt. Die
    | Reservierungsdetails verlinken mit dem Kürzel „reservation“.
    |
    */
    'audit' => [
        'per_page' => EnvSetting::integer('SWAYY_AUDIT_PER_PAGE', default: 100, min: 10, max: 500),
        'entities' => [
            'reservation' => Reservation::class,
            'guest' => Guest::class,
            'location' => Location::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Standorte
    |--------------------------------------------------------------------------
    |
    | default_timezone: Zeitzone, die das Formular „Neuer Standort“ vorschlägt
    | und die gilt, solange kein Standort gewählt ist (etwa im Protokoll).
    | intro_max: längste Begrüßung auf der Buchungsseite in Zeichen (100–5000).
    |
    */
    'locations' => [
        'default_timezone' => EnvSetting::timezone('SWAYY_DEFAULT_TIMEZONE', default: 'Europe/Berlin'),
        'intro_max' => EnvSetting::integer('SWAYY_LOCATION_INTRO_MAX', default: 1000, min: 100, max: 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Buchungseinstellungen eines Standorts
    |--------------------------------------------------------------------------
    |
    | Grenzen der Seiten „Kapazität & Dauer“ und „Buchungsregeln“ (bis Teil 3
    | auch der alten Sammelseite), gelesen über App\Support\BookingSettingRules.
    | Die Seiten zeigen sie als Feldgrenzen, die Prüfung beim Speichern nutzt
    | dieselben Werte.
    |
    | slot_intervals: Auswahl für den Takt der Uhrzeiten in Minuten (je 5–240).
    | duration_min, duration_max: Standarddauer eines Besuchs in Minuten.
    | buffer_max: längster Puffer nach einer Buchung in Minuten.
    | duration_rules_max: höchstens so viele Tischzeiten je Gruppengröße.
    | rule_duration_min, rule_duration_max: Grenzen einer Tischzeit in Minuten.
    | rule_party_max: größte Gruppe in einer Tischzeit-Regel.
    | covers_max: größte Zahl für „Höchstens gleichzeitig“ (Gäste).
    | lead_max_minutes: längster Mindestvorlauf in Minuten.
    | advance_max_days: so viele Tage im Voraus ist höchstens buchbar.
    | deadline_max_minutes: längste Storno- und Umbuchungsfrist in Minuten.
    | party_online_max: größte Gruppe, die online buchen kann.
    |
    | Liegt der größte Wert eines Paares (duration, rule_duration) in der .env
    | unter dem kleinsten, gilt der kleinste auch als größter. covers_max,
    | lead_max_minutes und deadline_max_minutes enden bei 32767, dem größten
    | Wert der Datenbankspalten (smallint). party_online_max und rule_party_max
    | enden bei 100: Jeder Buchungsweg (öffentliche Buchung, Buchen in der
    | Verwaltung, Walk-in, Warteliste, API) nimmt höchstens 100 Personen an
    | (party_size, max:100). Eine Grenze oder Tischzeit für größere Gruppen
    | käme nie zur Anwendung.
    |
    */
    'booking' => [
        'slot_intervals' => EnvSetting::integerList('SWAYY_BOOKING_SLOT_INTERVALS', default: [15, 30, 60], min: 5, max: 240),
        'duration_min' => $bookingDurationMin,
        'duration_max' => max($bookingDurationMin, EnvSetting::integer('SWAYY_BOOKING_DURATION_MAX', default: 480, min: 60, max: 1440)),
        'buffer_max' => EnvSetting::integer('SWAYY_BOOKING_BUFFER_MAX', default: 120, min: 0, max: 480),
        'duration_rules_max' => EnvSetting::integer('SWAYY_BOOKING_DURATION_RULES_MAX', default: 20, min: 1, max: 50),
        'rule_duration_min' => $bookingRuleDurationMin,
        'rule_duration_max' => max($bookingRuleDurationMin, EnvSetting::integer('SWAYY_BOOKING_RULE_DURATION_MAX', default: 600, min: 60, max: 1440)),
        'rule_party_max' => EnvSetting::integer('SWAYY_BOOKING_RULE_PARTY_MAX', default: 100, min: 1, max: 100),
        // Spalte location_settings.max_covers_per_slot (smallint): höchstens 32767.
        'covers_max' => EnvSetting::integer('SWAYY_BOOKING_COVERS_MAX', default: 2000, min: 1, max: 32767),
        // Spalte location_settings.min_lead_minutes (smallint): höchstens 32767.
        'lead_max_minutes' => EnvSetting::integer('SWAYY_BOOKING_LEAD_MAX', default: 10080, min: 0, max: 32767),
        'advance_max_days' => EnvSetting::integer('SWAYY_BOOKING_ADVANCE_MAX', default: 730, min: 1, max: 1825),
        // Spalten cancellation_deadline_minutes und modification_deadline_minutes (smallint): höchstens 32767.
        'deadline_max_minutes' => EnvSetting::integer('SWAYY_BOOKING_DEADLINE_MAX', default: 20160, min: 0, max: 32767),
        'party_online_max' => EnvSetting::integer('SWAYY_BOOKING_PARTY_ONLINE_MAX', default: 100, min: 1, max: 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Benachrichtigungen an Gäste und den Betrieb
    |--------------------------------------------------------------------------
    |
    | Grenzen der Seite „Benachrichtigungen“ (bis Teil 3 auch der alten
    | Sammelseite), gelesen über App\Support\NotificationSettingRules. Die
    | Seite zeigt sie als Feldgrenzen, die Prüfung beim Speichern nutzt
    | dieselben Werte.
    |
    | reminder_hours_min, reminder_hours_max: Erinnerung so viele Stunden vor
    | dem Besuch (1–24 und 24–720; die Spalte ist smallint).
    | feedback_hours_min, feedback_hours_max: Feedback-Anfrage so viele Stunden
    | nach dem Besuch (1–24 und 24–720; die Spalte ist smallint).
    | feedback_url_max: Länge des Links zum Bewertungsportal in Zeichen (30–255;
    | die Spalte feedback_external_url ist varchar(255)).
    | owner_email_max: Länge der Adresse für die Info an den Betrieb in Zeichen
    | (50–255; die Spalte owner_notification_email ist varchar(255)).
    | feedback_lookback_grace_days: Tage Luft für Ausfälle von Scheduler oder
    | Queue (0–30). Die Feedback-Anfragen (App\Jobs\SendFeedbackRequests)
    | gehen an Besuche der letzten feedback_hours_max Stunden, auf ganze Tage
    | aufgerundet, plus diese Tage. Das Fenster hält ein Wiedereinschalten von
    | Feedback bei frischen Besuchen und schützt alte Gäste vor einem Schwung
    | Anfragen.
    | Die Erinnerungen (App\Jobs\SendReservationReminders) laden Buchungen bis
    | reminder_hours_max Stunden im Voraus.
    |
    | Der kleinste Wert eines Paares liegt höchstens bei 24, der größte
    | mindestens bei 24: Das Feld bleibt für jede Einstellung ausfüllbar.
    |
    */
    'notifications' => [
        'reminder_hours_min' => EnvSetting::integer('SWAYY_REMINDER_HOURS_MIN', default: 1, min: 1, max: 24),
        'reminder_hours_max' => EnvSetting::integer('SWAYY_REMINDER_HOURS_MAX', default: 168, min: 24, max: 720),
        'feedback_hours_min' => EnvSetting::integer('SWAYY_FEEDBACK_HOURS_MIN', default: 1, min: 1, max: 24),
        'feedback_hours_max' => EnvSetting::integer('SWAYY_FEEDBACK_HOURS_MAX', default: 336, min: 24, max: 720),
        'feedback_url_max' => EnvSetting::integer('SWAYY_FEEDBACK_URL_MAX', default: 255, min: 30, max: 255),
        'owner_email_max' => EnvSetting::integer('SWAYY_OWNER_EMAIL_MAX', default: 200, min: 50, max: 255),
        'feedback_lookback_grace_days' => EnvSetting::integer('SWAYY_FEEDBACK_LOOKBACK_GRACE_DAYS', default: 7, min: 0, max: 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mailvorlagen
    |--------------------------------------------------------------------------
    |
    | Länge von Betreff und Text einer eigenen Mailvorlage; Spalten subject
    | varchar(255), body text. Gelesen vom NotificationTemplateController: Die
    | Seite „Vorlagen“ zeigt sie als Feldgrenzen, die Prüfung beim Speichern
    | nutzt dieselben Werte.
    |
    | subject_max: längster Betreff in Zeichen (20–255).
    | body_max: längster Text in Zeichen (500–20000).
    |
    */
    'templates' => [
        'subject_max' => EnvSetting::integer('SWAYY_TEMPLATE_SUBJECT_MAX', default: 200, min: 20, max: 255),
        'body_max' => EnvSetting::integer('SWAYY_TEMPLATE_BODY_MAX', default: 5000, min: 500, max: 20000),
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS-Versand (seven.io)
    |--------------------------------------------------------------------------
    |
    | Grenzen der Seite „SMS-Versand“ (bis Teil 3 auch der alten Sammelseite),
    | gelesen vom SmsController. Zugangsdaten und Absenderkennung liegen
    | verschlüsselt in integration_connections.credentials_encrypted (text).
    |
    | sender_id_max: längste Absenderkennung aus Ziffern, auf Wunsch mit
    | führendem + (Rufnummer), in Ziffern (3–16).
    | sender_id_text_max: längste Absenderkennung mit Buchstaben oder anderen
    | Zeichen in Zeichen (3–16, nie über sender_id_max); SMS-Netze erlauben
    | dafür 11. Beide Grenzen prüft das Speichern, jede mit eigener Meldung.
    | api_key_max: längster API-Key in Zeichen (32–1000).
    |
    */
    'sms' => [
        'sender_id_max' => $smsSenderIdMax,
        'sender_id_text_max' => min($smsSenderIdMax, EnvSetting::integer('SWAYY_SMS_SENDER_ID_TEXT_MAX', default: 11, min: 3, max: 16)),
        'api_key_max' => EnvSetting::integer('SWAYY_SMS_API_KEY_MAX', default: 255, min: 32, max: 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tische
    |--------------------------------------------------------------------------
    |
    | capacity_max: größte Platzzahl eines Tisches (1–255; mehr fasst die
    | Spalte in der Datenbank nicht). Gilt für „Räume & Tische“ und den Tischplan.
    | room_size_max_m: größte Breite und Tiefe eines Raums im Tischplan in
    | Metern (10–327). Der Tischplan rechnet 1 Meter als 100 Einheiten; die
    | Spalten rooms.plan_width und plan_height sind smallint und fassen
    | höchstens 32767, also 327 Meter.
    | name_max, room_name_max, combination_name_max: längster Name eines
    | Tisches, eines Raums und einer Tischkombination in Zeichen (10–255; die
    | Spalten fassen 255).
    |
    */
    'tables' => [
        'capacity_max' => EnvSetting::integer('SWAYY_TABLE_CAPACITY_MAX', default: 50, min: 1, max: 255),
        'room_size_max_m' => EnvSetting::integer('SWAYY_ROOM_SIZE_MAX_M', default: 300, min: 10, max: 327),
        'name_max' => EnvSetting::integer('SWAYY_TABLE_NAME_MAX', default: 40, min: 10, max: 255),
        'room_name_max' => EnvSetting::integer('SWAYY_ROOM_NAME_MAX', default: 80, min: 10, max: 255),
        'combination_name_max' => EnvSetting::integer('SWAYY_COMBINATION_NAME_MAX', default: 80, min: 10, max: 255),
    ],

    /*
    |--------------------------------------------------------------------------
    | Salon
    |--------------------------------------------------------------------------
    |
    | service_duration_min, service_duration_max: Dauer einer Leistung in Minuten.
    | Liegt der größte Wert in der .env unter dem kleinsten, gilt der kleinste
    | auch als größter.
    | service_price_max: höchster Preis einer Leistung in Euro. Die Spalte
    | price_minor speichert Cent bis 2147483647 und fasst damit jeden erlaubten
    | Preis (Obergrenze der Einstellung: 100000 Euro).
    | service_name_max: längster Name einer Leistung in Zeichen (10–255; die
    | Spalte services.name fasst 255). service_description_max: längste
    | Beschreibung in Zeichen (50–5000; die Spalte ist text und fasst jede
    | Länge, die Obergrenze hält die Buchungsseite lesbar).
    | staff_name_max: längster Name einer Person in Zeichen (10–255; die Spalte
    | staff_members.name fasst 255). staff_bio_max: längste Kurzvorstellung in
    | Zeichen (50–5000; die Spalte ist text und fasst jede Länge, die
    | Obergrenze hält Anfragen und Protokolleinträge klein). staff_hours_max: die meisten Zeitfenster, die eine
    | Person je Woche haben kann (7–200); sie begrenzt die Arbeitszeiten, die
    | eine Anfrage senden kann. absence_reason_max: längster Grund einer
    | Abwesenheit in Zeichen (10–120; die Spalte staff_absences.reason fasst
    | 120). absence_window_years: Beginn und Ende einer Abwesenheit liegen
    | höchstens so viele Jahre vor oder nach heute (1–100); das Jahr eines
    | Tippfehlers wie 0026 fällt so auf, und jedes Datum liegt im Bereich der
    | Zeitstempel-Spalten.
    |
    */
    'salon' => [
        'service_duration_min' => $serviceDurationMin,
        'service_duration_max' => max($serviceDurationMin, EnvSetting::integer('SWAYY_SERVICE_DURATION_MAX', default: 480, min: 30, max: 1440)),
        'service_price_max' => EnvSetting::integer('SWAYY_SERVICE_PRICE_MAX', default: 1000, min: 1, max: 100000),
        'service_name_max' => EnvSetting::integer('SWAYY_SERVICE_NAME_MAX', default: 120, min: 10, max: 255),
        'service_description_max' => EnvSetting::integer('SWAYY_SERVICE_DESCRIPTION_MAX', default: 1000, min: 50, max: 5000),
        'staff_name_max' => EnvSetting::integer('SWAYY_STAFF_NAME_MAX', default: 120, min: 10, max: 255),
        'staff_bio_max' => EnvSetting::integer('SWAYY_STAFF_BIO_MAX', default: 500, min: 50, max: 5000),
        'staff_hours_max' => EnvSetting::integer('SWAYY_STAFF_HOURS_MAX', default: 28, min: 7, max: 200),
        'absence_reason_max' => EnvSetting::integer('SWAYY_ABSENCE_REASON_MAX', default: 120, min: 10, max: 120),
        'absence_window_years' => EnvSetting::integer('SWAYY_ABSENCE_WINDOW_YEARS', default: 10, min: 1, max: 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hochgeladene Dateien
    |--------------------------------------------------------------------------
    |
    | logo_max_kb: größte Datei für ein Standort-Logo in KB (64–10240).
    | logo_types: erlaubte Dateiendungen für Logos; SVG ist nie erlaubt, weil
    | es Skripte tragen kann. Eine leere Liste („-“) gilt als Vorgabe, sonst
    | ließe sich kein Logo hochladen. logo_directory: Ordner auf dem Speicher
    | „public“, darunter je Standort ein Unterordner.
    | import_max_kb: größte Datei für „Daten einspielen“ in KB (1024–20480);
    | mehr lassen PHP und nginx nicht durch (docker/php.ini).
    |
    */
    'uploads' => [
        'logo_max_kb' => EnvSetting::integer('SWAYY_LOGO_MAX_KB', default: 3072, min: 64, max: 10240),
        'logo_types' => EnvSetting::choiceList('SWAYY_LOGO_TYPES', default: ['jpg', 'jpeg', 'png', 'webp'], allowed: ['jpg', 'jpeg', 'png', 'webp', 'gif'])
            ?: ['jpg', 'jpeg', 'png', 'webp'],
        'logo_directory' => EnvSetting::pattern('SWAYY_LOGO_DIRECTORY', default: 'logos', regex: '/^[a-z0-9][a-z0-9_-]*$/', hint: 'ist kein Ordnername aus Kleinbuchstaben, Ziffern, - und _'),
        'import_max_kb' => EnvSetting::integer('SWAYY_IMPORT_MAX_KB', default: 20480, min: 1024, max: 20480),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gestaltung der Mails
    |--------------------------------------------------------------------------
    |
    | Alle Mails laufen durch einen gemeinsamen Rahmen (App\Mail\Layout).
    | owner_address: Anrede in Mails an Betriebe und in Swayy-Mails, „du“ oder
    | „Sie“; Gast-Mails folgen der Anrede des Standorts.
    | powered_by: „Reserviert mit Swayy“ im Fußbereich der Gast-Mails.
    | logo_max_kb: größte Logodatei, die eingebettet wird (16–1024); größere
    | Logos ergeben die Wortmarke. logo_height: Anzeigehöhe in px (24–96).
    | contrast_min: Mindestkontrast für Knopftext und Links (3,0–7,0); eine zu
    | helle Markenfarbe wird dafür abgedunkelt.
    | details_min_rows: Zeilen „Bezeichnung: Wert“, ab denen ein Absatz zur
    | Tabelle wird (1–6). label_max: längste Bezeichnung darin in Zeichen
    | (10–80). button_label_max: längste Knopfbeschriftung (20–120).
    | Ohne Umgebungsvariable: Farben, Tönung, Schriften und Maße des Rahmens.
    |
    */
    'mail' => [
        'owner_address' => EnvSetting::pattern('SWAYY_MAIL_OWNER_ADDRESS', default: 'du', regex: '/^(du|Sie)$/', hint: 'ist weder „du“ noch „Sie“'),
        'powered_by' => (bool) env('SWAYY_MAIL_POWERED_BY', true),
        'logo_max_kb' => EnvSetting::integer('SWAYY_MAIL_LOGO_MAX_KB', default: 200, min: 16, max: 1024),
        'logo_height' => EnvSetting::integer('SWAYY_MAIL_LOGO_HEIGHT', default: 44, min: 24, max: 96),
        'contrast_min' => EnvSetting::decimal('SWAYY_MAIL_CONTRAST_MIN', default: 4.5, min: 3.0, max: 7.0),
        'details_min_rows' => EnvSetting::integer('SWAYY_MAIL_DETAILS_MIN_ROWS', default: 2, min: 1, max: 6),
        'label_max' => EnvSetting::integer('SWAYY_MAIL_LABEL_MAX', default: 40, min: 10, max: 80),
        'button_label_max' => EnvSetting::integer('SWAYY_MAIL_BUTTON_LABEL_MAX', default: 60, min: 20, max: 120),
        'logo_types' => ['png', 'jpg', 'jpeg', 'gif'],
        'logo_max_width' => 220,
        'swayy_logo' => resource_path('images/mail/swayy@2x.png'),
        'swayy_logo_height' => 32,
        'width' => 600,
        'mobile_breakpoint' => 520,
        'font_stack' => "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif",
        'display_stack' => "'Fraunces',Georgia,'Times New Roman',serif",
        'tint' => 0.055,
        'line' => 0.16,
        'dark_tint' => 0.16,
        'dark_line' => 0.32,
        'palette' => [
            'page' => '#f5f5f4',
            'card' => '#ffffff',
            'plate' => '#ffffff',
            'border' => '#e7e5e4',
            'strong' => '#1c1917',
            'body' => '#44403c',
            'muted' => '#57534e',
            'faint' => '#78716c',
            'on_brand' => '#ffffff',
            'dark_page' => '#0c0a09',
            'dark_card' => '#1c1917',
            'dark_border' => '#292524',
            'dark_strong' => '#fafaf9',
            'dark_body' => '#d6d3d1',
            'dark_muted' => '#a8a29e',
        ],
    ],

    /*
    | Links für Gäste: Gültigkeit des Anmeldelinks (10–1440) und des
    | Bestätigungslinks einer Buchung (60–10080), jeweils in Minuten.
    */
    'guest_auth' => [
        'login_minutes' => EnvSetting::integer('SWAYY_GUEST_LOGIN_LINK_MINUTES', default: 60, min: 10, max: 1440),
        'verify_minutes' => EnvSetting::integer('SWAYY_GUEST_VERIFY_LINK_MINUTES', default: 1440, min: 60, max: 10080),
    ],

    /*
    | Tarifanfrage nach der Testphase: so viele Stunden gilt der
    | Bestätigungslink an die Kontaktadresse (1–336).
    */
    'billing_request' => [
        'confirm_hours' => EnvSetting::integer('SWAYY_BILLING_CONFIRM_HOURS', default: 72, min: 1, max: 336),
    ],

    /*
    |--------------------------------------------------------------------------
    | Oberfläche der Verwaltung
    |--------------------------------------------------------------------------
    |
    | toast_ms: wie lange ein Hinweis wie „Gespeichert“ stehen bleibt, in
    | Millisekunden. reload_delay_ms: Wartezeit, bevor die Seite nach einer
    | Änderung neu lädt, die Navigation oder Aufbau betrifft (etwa der
    | Betriebstyp), in Millisekunden; so bleibt „Gespeichert“ lesbar.
    | default_brand_color: Markenfarbe, solange ein Betrieb keine eigene
    | gewählt hat (Buchungsseite, Buchungsknopf, Farbwahl in den Einstellungen).
    | default_staff_color: Kalenderfarbe, die „Person hinzufügen“ im Salon
    | vorschlägt. default_label_color: Farbe für neue Tags und Leistungen.
    | widget_label_max: längster Text auf dem Buchungsknopf und dem Link, den
    | „Buchungsseite & Widget“ erzeugt, in Zeichen. tag_name_max: längster
    | Name eines Tags in Zeichen (10–255; die Spalte fasst 255).
    |
    */
    'ui' => [
        'toast_ms' => EnvSetting::integer('SWAYY_UI_TOAST_MS', default: 3200, min: 1000, max: 20000),
        'reload_delay_ms' => EnvSetting::integer('SWAYY_UI_RELOAD_DELAY_MS', default: 1200, min: 0, max: 10000),
        'default_brand_color' => EnvSetting::hexColor('SWAYY_UI_DEFAULT_BRAND_COLOR', default: '#0f766e'),
        'default_staff_color' => EnvSetting::hexColor('SWAYY_UI_DEFAULT_STAFF_COLOR', default: '#0f766e'),
        'default_label_color' => EnvSetting::hexColor('SWAYY_UI_DEFAULT_LABEL_COLOR', default: '#6b7280'),
        'widget_label_max' => EnvSetting::integer('SWAYY_UI_WIDGET_LABEL_MAX', default: 40, min: 10, max: 100),
        'tag_name_max' => EnvSetting::integer('SWAYY_UI_TAG_NAME_MAX', default: 40, min: 10, max: 255),
    ],
];
