<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PublicWaitlistTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function url(array $setup): string
    {
        return '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug.'/waitlist';
    }

    public function test_guest_can_join_the_waitlist(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString();

        $this->postJson($this->url($setup), [
            'date' => $date,
            'time' => '19:00',
            'party_size' => 4,
            'name' => 'Wartender Gast',
            'email' => 'warte@example.test',
            'phone' => '+49 170 1234567',
            'privacy_accepted' => 1,
        ])->assertOk();

        $entry = WaitlistEntry::withoutGlobalScopes()
            ->where('tenant_id', $setup['tenant']->id)
            ->where('guest_email', 'warte@example.test')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(4, (int) $entry->party_size);
        $this->assertSame('waiting', $entry->status);
        $this->assertSame('online', $entry->source);
    }

    public function test_privacy_consent_is_required(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $this->postJson($this->url($setup), [
            'date' => CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString(),
            'party_size' => 2,
            'name' => 'Ohne Zustimmung',
            'email' => 'nope@example.test',
            // privacy_accepted missing
        ])->assertStatus(302)->assertSessionHasErrors('privacy_accepted');

        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    public function test_honeypot_blocks_bots(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $this->postJson($this->url($setup), [
            'date' => CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString(),
            'party_size' => 2,
            'name' => 'Bot',
            'email' => 'bot@example.test',
            'privacy_accepted' => 1,
            'website' => 'http://spam.example', // honeypot filled
        ])->assertStatus(422);

        $this->assertDatabaseCount('waitlist_entries', 0);
    }
}
