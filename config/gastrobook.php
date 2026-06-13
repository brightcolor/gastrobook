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
];
