<?php

declare(strict_types=1);

use App\Support\EnvSetting;

/*
 * Die Bereitstellungs-Schnittstelle fuer das Kundenpanel von bright color
 * (panel.kundenberei.ch), Version 1. Das Panel fuehrt Kunden, Vertraege und
 * Tarife und ruft swayy unter /provisioning/v1 mit signierten Aufrufen auf.
 * Vertrag: Skill `kundenpanel-saas`, Umsetzung:
 * docs/superpowers/specs/2026-10-08-kundenpanel-anbindung-design.md.
 *
 * OHNE SCHLUESSEL IST DIE SCHNITTSTELLE AUS. Alles hier ist einstellbar und
 * bleibt bei einem ungueltigen Wert in seinen Grenzen (EnvSetting).
 */
return [
    // Ein oder zwei Schluessel, je mindestens 32 Zeichen, durch Komma. Zwei
    // erlauben den Wechsel bei laufendem Betrieb; der erste signiert auch den
    // Rueckweg ins Panel. Leer schaltet die Schnittstelle ab.
    'keys' => EnvSetting::secretList('PROVISIONING_KEYS', minLength: 32, maxCount: 2),

    // Name dieses Dienstes im Panel; steht in /info und im Rueckweg-Link.
    'service_code' => EnvSetting::pattern('PROVISIONING_SERVICE_CODE', 'swayy', '/^[a-z0-9-]{1,40}$/', 'darf nur a–z, 0–9 und - enthalten, höchstens 40 Zeichen'),

    // So viele Sekunden darf der Zeitstempel eines Aufrufs von der Uhr dieses
    // Servers abweichen, in beide Richtungen.
    'max_skew_seconds' => EnvSetting::integer('PROVISIONING_MAX_SKEW_SECONDS', default: 300, min: 30, max: 3600),

    // So lange merkt sich swayy die Antwort auf einen Aufruf und wiederholt sie
    // fuer dieselbe Aufrufkennung.
    'idempotency_days' => EnvSetting::integer('PROVISIONING_IDEMPOTENCY_DAYS', default: 7, min: 1, max: 90),

    // IP-Netze, aus denen das Panel aufrufen darf (CIDR, Komma). Leer erlaubt alle.
    'allowed_networks' => EnvSetting::networks('PROVISIONING_ALLOWED_NETWORKS'),

    // Fehlversuche mit falscher Signatur je Absender, danach Sperre fuer
    // lock_seconds Sekunden.
    'max_failures' => EnvSetting::integer('PROVISIONING_MAX_FAILURES', default: 10, min: 1, max: 100),
    'lock_seconds' => EnvSetting::integer('PROVISIONING_LOCK_SECONDS', default: 900, min: 60, max: 86400),

    // Uhrzeit, zu der abgelaufene Aufrufkennungen und Anmeldelinks geloescht werden.
    'cleanup_time' => EnvSetting::time('PROVISIONING_CLEANUP_TIME', '04:40'),

    // So viele Sekunden gilt ein Anmeldelink aus dem Kundenbereich.
    'login_link_seconds' => EnvSetting::integer('LOGIN_LINK_SECONDS', default: 120, min: 30, max: 900),

    // Kundenbereich: Rueckweg „Tarif und Rechnungen“ und Bestellseite fuer
    // „Kostenlos testen“ und die alte Registrierung. Leer: der Weg entfaellt,
    // /register bleibt die eigene Registrierung.
    'panel_base_url' => EnvSetting::httpsUrl('PANEL_BASE_URL'),
    'panel_order_url' => EnvSetting::httpsUrl('PANEL_ORDER_URL'),
];
