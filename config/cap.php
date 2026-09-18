<?php

/*
|--------------------------------------------------------------------------
| Cap CAPTCHA (https://capjs.js.org)
|--------------------------------------------------------------------------
|
| Self-hosted proof-of-work CAPTCHA. The browser solves a small challenge and
| sends the result as a "cap-token" field; the app has the Cap server confirm
| it. Widget and WebAssembly come from our own Cap server, the pako inflater
| from public/vendor/cap/ – no third-party CDN is contacted for a guest.
|
*/

return [
    // Master switch. Without server_url, site_key and secret_key the guard
    // stays off even when enabled: a half-configured CAPTCHA must not block
    // a form.
    'enabled' => (bool) env('CAP_ENABLED', false),

    'server_url' => rtrim((string) env('CAP_SERVER_URL', ''), '/'),
    'site_key' => (string) env('CAP_SITE_KEY', ''),
    'secret_key' => (string) env('CAP_SECRET_KEY', ''),

    // Timeout for the siteverify call, in seconds.
    'timeout' => (int) env('CAP_TIMEOUT', 8),

    // Escape hatch: when the Cap server is unreachable or answers something
    // unreadable, the request is rejected. Set true to let it through instead
    // – unverified, and with an error-level log entry every time, so the lost
    // protection stays visible. For an incident, not for permanent use.
    'fail_open' => (bool) env('CAP_FAIL_OPEN', false),

    // Protected forms, individually switchable. The keys double as the
    // middleware parameter ("cap:booking") and the Blade attribute
    // (<x-cap-widget form="booking" />).
    'forms' => [
        // Table reservation, salon appointment and event booking.
        'booking' => (bool) env('CAP_PROTECT_BOOKING', true),

        // Waitlist entry (sent via fetch).
        'waitlist' => (bool) env('CAP_PROTECT_WAITLIST', true),

        // Contact form on the marketing site.
        'contact' => (bool) env('CAP_PROTECT_CONTACT', true),

        // Sign-up of a new tenant.
        'register' => (bool) env('CAP_PROTECT_REGISTER', true),

        // Admin login.
        'login' => (bool) env('CAP_PROTECT_LOGIN', true),

        // Password reset request – sends mail to a foreign address.
        'password' => (bool) env('CAP_PROTECT_PASSWORD', true),

        // Guest portal magic link – sends mail as well.
        'portal' => (bool) env('CAP_PROTECT_PORTAL', true),
    ],
];
