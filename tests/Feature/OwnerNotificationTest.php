<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class OwnerNotificationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function book(array $setup): void
    {
        app(ReservationLifecycleService::class)->create($setup['location'], [
            'party_size' => 4,
            'start_local' => CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(19, 0),
            'source' => 'online',
            'guest_name' => 'Erika Musterfrau',
            'guest_email' => 'erika@example.test',
            'guest_phone' => '+49 170 111',
            'allergy_note' => 'Nüsse',
        ]);
    }

    public function test_no_owner_mail_when_disabled(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $this->book($setup);

        Mail::assertNotQueued(TemplatedMail::class, fn ($m) => str_contains($m->mailSubject, 'Neue Reservierung'));
    }

    public function test_owner_mail_contains_all_details(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update([
            'owner_notification_enabled' => true,
            'owner_notification_email' => 'inhaber@betrieb.test',
        ]);
        $this->clearTenantContext();

        $this->book($setup);

        Mail::assertQueued(TemplatedMail::class, function ($mail) {
            if (! str_contains($mail->mailSubject, 'Neue Reservierung')) {
                return false;
            }
            $body = $mail->mailBody;

            return $mail->hasTo('inhaber@betrieb.test')
                && str_contains($mail->mailSubject, 'Erika Musterfrau')
                && str_contains($body, '4')                      // Personenzahl
                && str_contains($body, 'erika@example.test')     // Kontakt
                && str_contains($body, '+49 170 111')
                && str_contains($body, 'Nüsse');                 // Allergien
        });
    }

    public function test_falls_back_to_location_contact_email(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->update(['email' => 'kontakt@standort.test']);
        $setup['location']->settings->update([
            'owner_notification_enabled' => true,
            'owner_notification_email' => null,
        ]);
        $this->clearTenantContext();

        $this->book($setup);

        Mail::assertQueued(TemplatedMail::class, fn ($m) => $m->hasTo('kontakt@standort.test')
            && str_contains($m->mailSubject, 'Neue Reservierung'));
    }
}
