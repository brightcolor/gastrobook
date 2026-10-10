<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MailPreviewCommandTest extends TestCase
{
    public function test_preview_writes_every_mail_type(): void
    {
        config(['mail.default' => 'array']);
        $ziel = storage_path('framework/testing/mail-vorschau');
        File::deleteDirectory($ziel);

        $this->artisan('mail:vorschau', ['--ziel' => $ziel, '--eml' => true])->assertSuccessful();

        foreach (['reservation_confirmed', 'reservation_requested', 'payment_pending', 'reservation_table_changed', 'waitlist_offer',
            'event_booking_confirmed', 'guest_verify', 'guest_login', 'marketing', 'operator_new_reservation', 'operator_guest_reply',
            'swayy_password_reset', 'swayy_trial_expiry', 'swayy_billing_confirm', 'swayy_provisioned_welcome', 'swayy_notice'] as $art) {
            $this->assertFileExists("{$ziel}/{$art}.html", $art);
            $this->assertFileExists("{$ziel}/{$art}.txt", $art);
            $this->assertFileExists("{$ziel}/{$art}.eml", $art);
            $this->assertDoesNotMatchRegularExpression('/\ssrc="https?:/i', (string) file_get_contents("{$ziel}/{$art}.html"), $art);
            $this->assertStringContainsString('Content-Type: multipart/alternative', (string) file_get_contents("{$ziel}/{$art}.eml"), $art);
        }
    }
}
