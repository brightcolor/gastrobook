<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Jobs\DeliverWebhook;
use App\Jobs\SendFeedbackRequests;
use App\Jobs\SendReservationReminders;
use App\Models\FeedbackRequest;
use App\Models\Guest;
use App\Models\MarketingCampaign;
use App\Models\MarketingSend;
use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\MarketingCampaignService;
use App\Services\ReservationLifecycleService;
use App\Services\Sms\SmsManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Die Laeufe im Hintergrund.
 *
 * Sie verschicken Post und Geld, ohne dass jemand zusieht - jeder Fehler hier
 * faellt erst auf, wenn der Gast anruft.
 */
class BackgroundRunsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>  $abweichend
     */
    private function reservation(array $setup, array $abweichend = []): Reservation
    {
        $start = CarbonImmutable::now($setup['location']->timezone)->addHours(3);

        return Reservation::create(array_merge([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => 2,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone,
            'status' => ReservationStatus::Confirmed,
            'source' => 'online',
            'guest_name_snapshot' => 'Frau Kessler',
            'guest_email_snapshot' => 'kessler@example.test',
        ], $abweichend));
    }

    // ── Erinnerungen ──────────────────────────────────────────────────────

    /**
     * Wer kurzfristig bucht, liegt sofort innerhalb der Vorwarnzeit. Die
     * Erinnerung ging dann fuenfzehn Minuten nach der Bestaetigung raus.
     */
    public function test_no_reminder_right_after_a_short_notice_booking(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['reminder_enabled' => true, 'reminder_hours_before' => 24]);
        $reservation = $this->reservation($setup);

        (new SendReservationReminders)->handle(
            app(ReservationLifecycleService::class),
            app(SmsManager::class),
        );

        $this->assertNull($reservation->fresh()->reminder_sent_at);
    }

    /**
     * Und wer rechtzeitig gebucht hat, bekommt sie - genau einmal.
     *
     * Der Test bricht den Versand MITTEN im Lauf ab. Genau das ist der Fall,
     * um den es geht: Der Worker schiesst nach sechzig Sekunden ab, der Job
     * wird erneut zugestellt. Ein Test, der den Job einfach zweimal fehlerfrei
     * aufruft, beweist dagegen nichts - dort filtert schon die Abfrage die
     * Zeile weg, und er waere auch mit der alten Reihenfolge gruen.
     */
    public function test_a_crash_during_sending_does_not_send_twice(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['reminder_enabled' => true, 'reminder_hours_before' => 24]);
        $reservation = $this->reservation($setup);
        $reservation->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        // Erster Lauf: der Versand scheitert.
        $kaputt = new class extends ReservationLifecycleService
        {
            public function __construct() {}

            public function sendGuestMail(Reservation $reservation, string $templateKey, array $extra = []): void
            {
                throw new \RuntimeException('Mailserver weg');
            }
        };

        try {
            (new SendReservationReminders)->handle($kaputt, app(SmsManager::class));
        } catch (\RuntimeException) {
            // Erwartet - der Job stirbt genau hier.
        }

        // Der Platz ist trotzdem beansprucht.
        $this->assertNotNull($reservation->fresh()->reminder_sent_at);

        // Zweiter Lauf mit funktionierendem Versand: nichts mehr.
        (new SendReservationReminders)->handle(
            app(ReservationLifecycleService::class),
            app(SmsManager::class),
        );

        $this->assertSame(0, NotificationLog::withoutGlobalScopes()
            ->where('reservation_id', $reservation->id)
            ->where('template_key', 'reservation_reminder')
            ->count(), 'Die Erinnerung ging nach dem Abbruch doch noch einmal raus.');
    }

    public function test_a_reminder_goes_out_exactly_once(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['reminder_enabled' => true, 'reminder_hours_before' => 24]);
        $reservation = $this->reservation($setup);
        $reservation->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        $lauf = fn () => (new SendReservationReminders)->handle(
            app(ReservationLifecycleService::class),
            app(SmsManager::class),
        );

        $lauf();
        $this->assertNotNull($reservation->fresh()->reminder_sent_at);

        $lauf();
        $this->assertSame(1, NotificationLog::withoutGlobalScopes()
            ->where('reservation_id', $reservation->id)
            ->where('template_key', 'reservation_reminder')
            ->count());
    }

    // ── Feedback ──────────────────────────────────────────────────────────

    /**
     * Standorte mit abgeschaltetem Feedback bekamen kein
     * feedback_requested_at, blieben also im Rueckschaufenster stehen - und
     * weil sie die aeltesten sind, belegten sie das ganze Fenster.
     */
    public function test_a_location_without_feedback_does_not_block_the_window(): void
    {
        Mail::fake();

        $aus = $this->createTenantSetup();
        $aus['location']->settings()->update(['feedback_enabled' => false, 'feedback_hours_after' => 1]);
        $alt = $this->reservation($aus, [
            'status' => ReservationStatus::Completed,
            'start_at' => now()->subDays(2),
            'end_at' => now()->subDays(2)->addHours(2),
            'departed_at' => now()->subDays(2)->addHours(2),
        ]);

        $an = $this->createTenantSetup();
        $an['location']->settings()->update(['feedback_enabled' => true, 'feedback_hours_after' => 1]);
        $neu = $this->reservation($an, [
            'status' => ReservationStatus::Completed,
            'start_at' => now()->subDay(),
            'end_at' => now()->subDay()->addHours(2),
            'departed_at' => now()->subDay()->addHours(2),
        ]);

        (new SendFeedbackRequests)->handle(app(ReservationLifecycleService::class));

        $this->assertNull($alt->fresh()->feedback_requested_at);
        $this->assertNotNull($neu->fresh()->feedback_requested_at);
    }

    public function test_a_feedback_request_goes_out_exactly_once(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['feedback_enabled' => true, 'feedback_hours_after' => 1]);
        $reservation = $this->reservation($setup, [
            'status' => ReservationStatus::Completed,
            'start_at' => now()->subDay(),
            'end_at' => now()->subDay()->addHours(2),
            'departed_at' => now()->subDay()->addHours(2),
        ]);

        $lauf = fn () => (new SendFeedbackRequests)->handle(app(ReservationLifecycleService::class));
        $lauf();
        $lauf();

        $this->assertSame(1, FeedbackRequest::withoutGlobalScopes()
            ->where('reservation_id', $reservation->id)->count());
    }

    // ── Webhooks ──────────────────────────────────────────────────────────

    /**
     * Gezaehlt werden gescheiterte Ereignisse, nicht Versuche. Bei fuenf
     * Versuchen je Ereignis war die Abschaltschwelle von 20 sonst nach vier
     * Ereignissen erreicht.
     */
    public function test_a_single_failed_event_counts_once(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $setup = $this->createTenantSetup();
        $endpoint = WebhookEndpoint::create([
            'tenant_id' => $setup['tenant']->id,
            'url' => 'https://93.184.216.34/hook',
            'secret' => 'geheim',
            'events' => ['*'],
            'is_active' => true,
        ]);
        $delivery = WebhookDelivery::create([
            'tenant_id' => $setup['tenant']->id,
            'webhook_endpoint_id' => $endpoint->id,
            'event' => 'reservation.created',
            'payload' => ['event' => 'reservation.created'],
            'status' => 'pending',
        ]);

        // Erster Versuch von fuenf: noch kein Zaehlschritt.
        (new DeliverWebhook($delivery->id))->handle();

        $this->assertSame(0, $endpoint->fresh()->failure_count);
        $this->assertTrue((bool) $endpoint->fresh()->is_active);
    }

    // ── Kampagnen ─────────────────────────────────────────────────────────

    /**
     * Ein ausgefallener Lauf verlor die Gruppe dieses Tages fuer immer: Das
     * Fenster war genau einen Tag breit und wanderte mit dem Ausfuehrungstag
     * weiter.
     */
    public function test_a_missed_campaign_day_is_caught_up(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();

        $kampagne = MarketingCampaign::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'name' => 'Wiederkommen',
            'type' => 'rebooking',
            'offset_days' => 30,
            'min_visits' => 0,
            'subject' => 'Schön war es',
            'body' => 'Hallo {{name}}',
            'is_active' => true,
        ]);

        $tz = $setup['location']->timezone;
        $heute = CarbonImmutable::now($tz)->startOfDay();
        // Besuch, der GESTERN faellig gewesen waere.
        $besuch = $heute->subDays(31)->setTime(19, 0);

        $guest = Guest::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'first_name' => 'Klara', 'last_name' => 'Meier',
            'email' => 'klara@example.test',
            'marketing_consent' => true,
            'last_visit_at' => $besuch->utc(),
            'visit_count' => 1,
        ]);
        $this->reservation($setup, [
            'guest_id' => $guest->id,
            'status' => ReservationStatus::Completed,
            'start_at' => $besuch->utc(),
            'end_at' => $besuch->addHours(2)->utc(),
        ]);

        $versandt = app(MarketingCampaignService::class)->run($kampagne, $heute);

        $this->assertSame(1, $versandt, 'Der uebersprungene Tag wurde nicht nachgeholt.');

        // Der Schluessel haengt am Besuchstag - ein zweiter Lauf schreibt nicht
        // noch einmal.
        $this->assertSame(0, app(MarketingCampaignService::class)->run($kampagne, $heute->addDay()));
        $this->assertSame(1, MarketingSend::withoutGlobalScopes()
            ->where('marketing_campaign_id', $kampagne->id)->count());
    }
}
