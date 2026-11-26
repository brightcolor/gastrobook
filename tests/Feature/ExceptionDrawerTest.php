<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BlackoutPeriod;
use App\Models\SpecialOpeningHour;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Die Seitenleiste „Ausnahme bearbeiten“ entsteht erst beim Öffnen.
 *
 * Vorher legte die Seite für jede bearbeitbare Ausnahme eine eigene
 * Seitenleiste samt vollständigem Formular mit allen Räumen und Tischen an.
 * Mit vielen Ausnahmen wuchs die Seite entsprechend. Jetzt trägt sie nur die
 * Seitenleiste „Neue Ausnahme“; die zum Bearbeiten liefert
 * GET /admin/settings/exceptions/{key}/drawer, wenn jemand sie öffnet.
 */
class ExceptionDrawerTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Berlin'));
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function closedDay(array $setup, string $date, string $label): string
    {
        $group = (string) Str::uuid();
        SpecialOpeningHour::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'date' => $date, 'closed' => true, 'label' => $label, 'exception_group' => $group,
        ]);

        return 'g-'.$group;
    }

    public function test_the_page_carries_only_the_drawer_for_a_new_exception(): void
    {
        $setup = $this->createTenantSetup();
        $keys = [];
        foreach (range(1, 12) as $day) {
            $keys[] = $this->closedDay($setup, sprintf('2026-11-%02d', $day), 'Ruhetag '.$day);
        }
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $content = (string) $this->actingAs($owner)->get('/admin/settings/exceptions')->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, 'data-drawer hidden'));
        $this->assertSame(1, substr_count($content, 'data-preview-url='));
        $this->assertStringContainsString('id="ausnahme-neu"', $content);
        foreach ($keys as $key) {
            $this->assertStringNotContainsString('id="ausnahme-'.$key.'"', $content);
            $this->assertStringContainsString(
                'data-drawer-open="ausnahme-'.$key.'" data-drawer-src="'.route('admin.settings.exceptions.drawer', $key).'"',
                $content,
            );
        }
    }

    public function test_the_drawer_of_an_entry_comes_with_its_form_and_values(): void
    {
        $setup = $this->createTenantSetup();
        $key = $this->closedDay($setup, '2026-12-24', 'Heiligabend');
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get(route('admin.settings.exceptions.drawer', $key))->assertOk();

        $content = (string) $response->getContent();
        $this->assertStringStartsWith('<div id="ausnahme-'.$key.'" data-drawer hidden', ltrim($content));
        $this->assertStringContainsString('Ausnahme bearbeiten', $content);
        $this->assertStringContainsString('Heiligabend', $content);
        $this->assertStringContainsString('action="'.route('admin.settings.exceptions.update', $key).'"', $content);
        $this->assertStringContainsString('name="_method" value="PUT"', $content);
        $this->assertStringContainsString('data-preview-url="'.route('admin.settings.exceptions.preview').'"', $content);
        $this->assertMatchesRegularExpression('/id="ausnahme-'.preg_quote($key, '/').'-effects-closed"[^>]*checked/', $content);
        $this->assertStringContainsString('form="form-ausnahme-'.$key.'"', $content);
        // Nur die Seitenleiste, ohne Layout drumherum.
        $this->assertStringNotContainsString('<html', $content);
    }

    public function test_an_unknown_or_foreign_entry_answers_with_a_readable_404(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $foreign = $this->closedDay($other, '2026-12-24', 'Fremd');
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        foreach (['g-'.Str::uuid(), $foreign] as $key) {
            $this->actingAs($owner)->get(route('admin.settings.exceptions.drawer', $key))
                ->assertNotFound()
                ->assertExactJson(['message' => 'Diese Ausnahme gibt es nicht mehr. Bitte die Seite neu laden.']);
        }
    }

    public function test_past_and_old_entries_have_no_drawer(): void
    {
        $setup = $this->createTenantSetup();
        $past = $this->closedDay($setup, '2026-10-03', 'Feiertag');
        $blackout = BlackoutPeriod::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'starts_at' => CarbonImmutable::parse('2026-11-20 18:00', 'Europe/Berlin')->utc(),
            'ends_at' => CarbonImmutable::parse('2026-11-22 12:00', 'Europe/Berlin')->utc(),
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get(route('admin.settings.exceptions.drawer', $past))
            ->assertNotFound()
            ->assertExactJson(['message' => 'Diese Ausnahme ist schon vorbei und lässt sich nicht mehr bearbeiten.']);

        // Eine ältere Sperre nennt, warum sie sich nicht bearbeiten lässt, wie in der Liste.
        $message = (string) $this->actingAs($owner)->get(route('admin.settings.exceptions.drawer', 'b-'.$blackout->id))
            ->assertNotFound()->json('message');
        $this->assertStringContainsString('Zum Ändern bitte löschen', $message);
    }

    public function test_the_drawer_needs_the_same_rights_as_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $key = $this->closedDay($setup, '2026-12-24', 'Heiligabend');
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->get(route('admin.settings.exceptions.drawer', $key))->assertForbidden();
    }
}
