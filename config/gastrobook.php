<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Erster Oberadmin (Bootstrap)
    |--------------------------------------------------------------------------
    |
    | Wird beim ersten Start von `php artisan gastrobook:create-admin --if-missing`
    | genutzt, um einen Plattform-Oberadmin anzulegen, falls noch keiner existiert.
    | Leer lassen, um den Admin manuell anzulegen.
    |
    */
    'admin' => [
        'email' => env('GASTROBOOK_ADMIN_EMAIL'),
        'password' => env('GASTROBOOK_ADMIN_PASSWORD'),
        'name' => env('GASTROBOOK_ADMIN_NAME', 'Administrator'),
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
        'sse' => env('GASTROBOOK_BOARD_SSE', true),
    ],
];
