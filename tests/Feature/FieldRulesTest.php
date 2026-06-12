<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class FieldRulesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_admin_can_change_field_rules(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->put('/admin/settings/field-rules', [
            'fields' => [
                'email' => 'required',
                'phone' => 'hidden',
                'occasion' => 'hidden',
                'note' => 'optional',
                'allergies' => 'required',
            ],
        ])->assertRedirect()->assertSessionHas('success');

        $settings = $setup['location']->settings()->withoutGlobalScopes()->first();
        $this->assertSame('hidden', $settings->fresh()->field_rules['phone']);
    }

    public function test_hidden_fields_are_not_rendered_in_widget(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['field_rules' => [
            'email' => 'required',
            'phone' => 'hidden',
            'occasion' => 'hidden',
            'note' => 'optional',
            'allergies' => 'optional',
        ]]);
        $this->clearTenantContext();

        $response = $this->get('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug);

        $response->assertOk()
            ->assertDontSee('name="phone"', false)
            ->assertDontSee('name="occasion"', false)
            ->assertSee('name="email"', false);
    }

    public function test_booking_works_without_hidden_phone_field(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['field_rules' => [
            'email' => 'required',
            'phone' => 'hidden',
        ]]);
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString();

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => $date,
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Ohne Telefon',
            'email' => 'ohnetelefon@example.test',
            'privacy_accepted' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('reservations', ['guest_name_snapshot' => 'Ohne Telefon']);
    }

    public function test_required_phone_is_enforced(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['field_rules' => [
            'email' => 'optional',
            'phone' => 'required',
        ]]);
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString();

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => $date,
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Pflicht Telefon',
            'privacy_accepted' => '1',
        ])->assertSessionHasErrors('phone');
    }

    public function test_embed_script_is_served(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $this->get('/embed/'.$setup['tenant']->slug.'/'.$setup['location']->slug.'.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertSee('iframe', false);
    }
}
