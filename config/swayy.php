<?php

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
    | Rechtstexte (Markdown)
    |--------------------------------------------------------------------------
    |
    | Impressum, Datenschutz und AGB liegen als Markdown unter
    | storage/app/legal/<key>.md (bind-gemountet → auf dem Host editierbar).
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
];
