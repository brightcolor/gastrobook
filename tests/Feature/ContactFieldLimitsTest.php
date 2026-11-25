<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Längengrenzen für E-Mail-Adressen und Telefonnummern (swayy.contact_fields).
 * Ohne Grenze lief eine überlange Adresse bis in die Datenbank und scheiterte
 * dort mit einem Fehler 500; jetzt bekommt der Absender eine lesbare Meldung.
 */
class ContactFieldLimitsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /**
     * Eine gültige Adresse mit genau $length Zeichen, Domain in Teilen zu
     * höchstens 63 Zeichen.
     */
    private function address(int $length): string
    {
        $local = str_repeat('a', 20);
        $rest = $length - strlen($local) - 1 - strlen('.test');
        $labels = [];
        while ($rest > 0) {
            $take = $rest <= 63 ? $rest : min(60, $rest - 2);
            $labels[] = str_repeat('b', $take);
            $rest -= $take + ($rest > $take ? 1 : 0);
        }

        $address = $local.'@'.implode('.', $labels).'.test';
        $this->assertSame($length, strlen($address));

        return $address;
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function book(array $setup, string $email, string $phone = '+49 170 0000000'): TestResponse
    {
        $url = '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug;

        return $this->from($url)->post($url, [
            'date' => CarbonImmutable::now('Europe/Berlin')->addDays(2)->toDateString(),
            'time' => '19:00', 'party_size' => 2,
            'name' => 'Frau Kessler', 'email' => $email, 'phone' => $phone, 'privacy_accepted' => '1',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function restaurant(): array
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        return $setup;
    }

    public function test_the_booking_rejects_an_address_over_the_default_limit(): void
    {
        $setup = $this->restaurant();

        $this->book($setup, $this->address(255))
            ->assertSessionHasErrors(['email' => 'E-Mail-Adresse darf höchstens 254 Zeichen lang sein.']);
        $this->assertSame(0, Reservation::withoutGlobalScopes()->count());

        $this->book($setup, $this->address(254))->assertSessionHasNoErrors();
        $this->assertSame(1, Reservation::withoutGlobalScopes()->count());
    }

    public function test_the_limits_come_from_the_settings(): void
    {
        config(['swayy.contact_fields.email_max' => 40, 'swayy.contact_fields.phone_max' => 12]);
        $setup = $this->restaurant();

        $this->book($setup, $this->address(41))->assertSessionHasErrors('email');
        $this->book($setup, $this->address(40), '+49 170 12345')->assertSessionHasErrors('phone');
        $this->assertSame(0, Reservation::withoutGlobalScopes()->count());

        $this->book($setup, $this->address(40), '+49 170 1234')->assertSessionHasNoErrors();
        $this->assertSame(1, Reservation::withoutGlobalScopes()->count());
    }

    public function test_the_api_answers_with_a_readable_error(): void
    {
        $setup = $this->createTenantSetup();
        $user = $this->createMember($setup['tenant'], 'tenant_owner');
        $token = $user->createToken('test', ['tenant:'.$setup['tenant']->id, 'reservations:write'])->plainTextToken;
        $this->clearTenantContext();

        $this->withToken($token)->postJson('/api/v1/reservations', [
            'location_id' => $setup['location']->id,
            'date' => now()->addDays(2)->toDateString(),
            'time' => '19:00',
            'party_size' => 2,
            'guest_name' => 'Frau Kessler',
            'guest_email' => $this->address(255),
        ])->assertStatus(422)->assertJsonValidationErrors('guest_email');
        $this->assertSame(0, Reservation::withoutGlobalScopes()->count());
    }

    public function test_login_and_password_reset_reject_an_overlong_address(): void
    {
        User::factory()->create(['email' => 'kessler@example.test']);

        $tooLong = ['email' => 'E-Mail-Adresse darf höchstens 254 Zeichen lang sein.'];

        $this->from('/login')->post('/login', ['email' => $this->address(255), 'password' => 'egal-was'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors($tooLong);

        $this->from(route('password.request'))->post(route('password.email'), ['email' => $this->address(255)])
            ->assertSessionHasErrors($tooLong);
    }
}
