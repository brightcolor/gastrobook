<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Admin\WebhookController;
use App\Support\ApiScopes;
use App\Support\IntegrationStatus;
use App\Support\TemplateLabels;
use App\Support\WebhookEvents;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Die Wörter für Vorlagen, API-Rechte, Webhook-Ereignisse und den Zustand
 * einer Anbindung stehen in App\Support. Die Seiten (Controller) und das
 * Protokoll (AuditLog) lesen dieselben Tabellen.
 */
class SharedLabelsTest extends TestCase
{
    public function test_integration_status_names_every_state(): void
    {
        $this->assertSame('Verbunden', IntegrationStatus::label('connected'));
        $this->assertSame('Ausgeschaltet', IntegrationStatus::label('disconnected'));
        $this->assertSame('Fehler beim letzten Test', IntegrationStatus::label('error'));
        $this->assertSame('Nicht eingerichtet', IntegrationStatus::label(null));
        $this->assertSame('Nicht eingerichtet', IntegrationStatus::label('unbekannt'));
        $this->assertSame('Nicht eingerichtet', IntegrationStatus::NOT_SET_UP);
    }

    public function test_template_labels_follow_the_business_type(): void
    {
        $this->assertSame('Reservierung bestätigt', TemplateLabels::label('reservation_confirmed', false));
        $this->assertSame('Termin bestätigt', TemplateLabels::label('reservation_confirmed', true));
        // Ohne eigenen Salon-Wortlaut gilt die allgemeine Bezeichnung, eine unbekannte Kennung bleibt.
        $this->assertSame('Erinnerung', TemplateLabels::label('reservation_reminder', true));
        $this->assertSame('brand_new', TemplateLabels::label('brand_new', true));
        $this->assertSame([], array_diff(array_keys(TemplateLabels::SALON_LABELS), array_keys(TemplateLabels::LABELS)));
    }

    public function test_api_scopes_name_every_right_for_both_business_types(): void
    {
        $restaurant = ApiScopes::all(false);
        $salon = ApiScopes::all(true);

        $this->assertSame(array_keys($restaurant), array_keys($salon));
        $this->assertSame('Reservierungen lesen', $restaurant['reservations:read']['label']);
        $this->assertSame('Termine lesen', $salon['reservations:read']['label']);
        $this->assertSame('Gäste lesen', ApiScopes::labels(false)['guests:read']);
        $this->assertSame('Kunden lesen', ApiScopes::labels(true)['guests:read']);
        foreach ($restaurant as $scope => $info) {
            $this->assertNotSame('', $info['label'], $scope);
            $this->assertNotSame('', $info['help'], $scope);
        }
    }

    public function test_webhook_events_have_labels_and_the_controller_keeps_its_public_names(): void
    {
        $this->assertSame([], array_values(array_diff(WebhookEvents::EVENTS, array_keys(WebhookEvents::LABELS))));
        $this->assertSame('Neue Reservierung', WebhookEvents::labels(false)['reservation.created']);
        $this->assertSame('Neuer Termin', WebhookEvents::labels(true)['reservation.created']);
        // Bezeichnungen ohne Salon-Wortlaut gelten für beide.
        $this->assertSame('Zahlung eingegangen', WebhookEvents::labels(true)['payment.succeeded']);
        $this->assertSame('Alle Ereignisse', WebhookEvents::ALL_LABEL);

        // Die Namen am Controller bleiben, damit Aufrufer (REST-API, Tests) unverändert arbeiten.
        $this->assertSame(WebhookEvents::EVENTS, WebhookController::EVENTS);
        $this->assertSame(WebhookEvents::LABELS, WebhookController::EVENT_LABELS);
        $this->assertSame(WebhookEvents::SALON_LABELS, WebhookController::EVENT_LABELS_SALON);
    }

    public function test_models_services_and_support_do_not_depend_on_http_controllers(): void
    {
        $offenders = [];
        foreach (['Models', 'Services', 'Support'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'use App\\Http\\')) {
                    $offenders[] = $directory.'/'.$file->getFilename();
                }
            }
        }

        $this->assertSame([], $offenders, 'Diese Dateien importieren Klassen aus App\\Http: '.implode(', ', $offenders));
    }
}
