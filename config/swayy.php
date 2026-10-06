<?php

use App\Support\EnvSetting;

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
    | Mails an Gäste: Absendername und Antwortadresse
    |--------------------------------------------------------------------------
    |
    | Absender bleibt MAIL_FROM_ADDRESS, damit SPF und DKIM passen. Antwortet
    | ein Gast, geht die Mail an die Antwortadresse (Reply-To) des Betriebs.
    | Trägt der Betrieb unter Einstellungen › Allgemein › „E-Mails an Gäste“
    | nichts ein, gilt der erste gefüllte Wert aus diesen Listen.
    |
    | Ein unbekannter Eintrag legt nichts lahm: Es gilt die Vorgabe, und eine
    | Warnung im Log nennt die Variable. „-“ heißt: ohne Ersatz.
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
];
