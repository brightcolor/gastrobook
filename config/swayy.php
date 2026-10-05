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
