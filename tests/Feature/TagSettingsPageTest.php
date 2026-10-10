<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\TenantType;
use App\Models\AuditLog;
use App\Models\Reservation;
use App\Models\Tag;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TagSettingsPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     */
    private function reservationWith(array $setup, Tag $tag): void
    {
        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Testgast',
        ]);
        $reservation->tags()->attach($tag->id);
    }

    /** Inhalt der Seite; die Navigation nennt die Seiten beider Betriebstypen. */
    private function mainContent(string $html): string
    {
        $this->assertSame(1, preg_match('/<main.*?<\/main>/s', $html, $main));

        return $main[0];
    }

    /** Seitenleisten der Seite: Sie stehen hinter dem Hauptbereich. */
    private function drawers(string $html): string
    {
        $start = strpos($html, 'id="tag-neu" data-drawer');
        $end = strpos($html, 'id="swayyToast"');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    public function test_owner_sees_tags_with_their_use_and_drawers(): void
    {
        $setup = $this->createTenantSetup();
        $vip = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#aa3300', 'scope' => 'reservation']);
        Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'Gast-Tag', 'color' => '#334455', 'scope' => 'guest']);
        $this->reservationWith($setup, $vip);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/tags')->assertOk();

        $response->assertSee('Einstellungen › Buchungen')
            ->assertSee('Gilt für alle Standorte')
            ->assertSee('An 1 Reservierung')
            ->assertSee('data-drawer-open="tag-'.$vip->id.'"', false)
            ->assertSee('id="tag-neu" data-drawer hidden', false)
            ->assertSee('Tag „VIP“ löschen? Er verschwindet von 1 Reservierung.')
            ->assertSee('data-reload-after-save', false)
            ->assertDontSee('Gast-Tag');
        $this->assertMatchesRegularExpression('/data-nav-key="tags"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_an_empty_list_offers_one_action(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/tags')
            ->assertOk()
            ->assertSee('Noch keine Tags');
    }

    public function test_the_page_needs_settings_rights_and_the_tag_picker_stays_open_for_the_team(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/settings/tags')->assertForbidden();
        $this->actingAs($host)->getJson('/admin/tags')->assertOk();
    }

    public function test_renaming_to_an_existing_name_explains_the_conflict(): void
    {
        $setup = $this->createTenantSetup();
        Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#aa3300', 'scope' => 'reservation']);
        $other = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'Stammgast', 'color' => '#334455', 'scope' => 'reservation']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson("/admin/tags/{$other->id}", ['name' => 'VIP', 'color' => '#334455'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Einen Tag „VIP“ gibt es schon. Bitte einen anderen Namen wählen.');

        $this->assertSame('Stammgast', $other->fresh()->name);
    }

    public function test_creating_an_existing_name_takes_the_tag_and_says_so(): void
    {
        $setup = $this->createTenantSetup();
        $vip = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#aa3300', 'scope' => 'reservation']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/tags', ['name' => 'VIP', 'color' => '#00aa00'])
            ->assertOk()
            ->assertJsonPath('id', $vip->id)
            ->assertJsonPath('message', 'Einen Tag „VIP“ gab es schon; er hat jetzt die gewählte Farbe.');

        $this->assertSame('#00aa00', $vip->fresh()->color);
    }

    public function test_answers_keep_the_fields_of_the_tag_picker_and_add_a_message(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $created = $this->actingAs($owner)->postJson('/admin/tags', ['name' => 'Geburtstag', 'color' => '#aa3300'])
            ->assertCreated()
            ->assertJsonPath('name', 'Geburtstag')
            ->assertJsonPath('color', '#aa3300')
            ->assertJsonPath('message', 'Tag „Geburtstag“ angelegt.');
        $id = $created->json('id');

        $this->actingAs($owner)->putJson("/admin/tags/{$id}", ['name' => 'Jubiläum', 'color' => '#aa3300'])
            ->assertOk()
            ->assertJsonPath('name', 'Jubiläum')
            ->assertJsonPath('message', 'Tag „Jubiläum“ gespeichert.');

        $this->actingAs($owner)->deleteJson("/admin/tags/{$id}")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Tag „Jubiläum“ gelöscht.');
    }

    public function test_a_system_tag_is_shown_without_actions_and_explains_the_refusal(): void
    {
        $setup = $this->createTenantSetup();
        $system = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'Allergie', 'color' => '#aa3300', 'scope' => 'both', 'is_system' => true]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/tags')
            ->assertOk()
            ->assertSee('fest eingerichtet')
            ->assertDontSee('data-drawer-open="tag-'.$system->id.'"', false);

        $this->actingAs($owner)->putJson("/admin/tags/{$system->id}", ['name' => 'X', 'color' => '#222222'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Dieser Tag ist fest eingerichtet und lässt sich nicht ändern.');
    }

    public function test_a_system_tag_has_no_delete_question_and_refuses_deleting_with_a_reason(): void
    {
        $setup = $this->createTenantSetup();
        $system = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'Allergie', 'color' => '#aa3300', 'scope' => 'both', 'is_system' => true]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/tags')
            ->assertOk()
            ->assertDontSee('tag-loeschen-'.$system->id, false);

        $this->actingAs($owner)->deleteJson("/admin/tags/{$system->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Dieser Tag ist fest eingerichtet und lässt sich nicht löschen.');
        $this->assertNotNull(Tag::withoutGlobalScopes()->find($system->id));
    }

    public function test_creating_the_name_of_a_system_tag_keeps_its_color(): void
    {
        $setup = $this->createTenantSetup();
        $system = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'Allergie', 'color' => '#aa3300', 'scope' => 'reservation', 'is_system' => true]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/tags', ['name' => 'Allergie', 'color' => '#00aa00'])
            ->assertOk()
            ->assertJsonPath('id', $system->id)
            ->assertJsonPath('color', '#aa3300')
            ->assertJsonPath('message', 'Einen Tag „Allergie“ gibt es schon. Er ist fest eingerichtet und behält seine Farbe.');

        $this->assertSame('#aa3300', Tag::withoutGlobalScopes()->find($system->id)->color);
    }

    public function test_a_tag_of_another_tenant_answers_404_and_stays_off_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $foreign = Tag::create(['tenant_id' => $other['tenant']->id, 'name' => 'Fremd-Tag', 'color' => '#aa3300', 'scope' => 'reservation']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/tags')
            ->assertOk()
            ->assertDontSee('Fremd-Tag')
            ->assertDontSee('tag-'.$foreign->id, false);
        $this->actingAs($owner)->putJson("/admin/tags/{$foreign->id}", ['name' => 'Eigen', 'color' => '#222222'])->assertNotFound();
        $this->actingAs($owner)->deleteJson("/admin/tags/{$foreign->id}")->assertNotFound();
        // Der Name eines fremden Tags zählt nicht als vorhanden: Der Betrieb bekommt seinen eigenen.
        $this->actingAs($owner)->postJson('/admin/tags', ['name' => 'Fremd-Tag', 'color' => '#00aa00'])->assertCreated();

        $foreign = Tag::withoutGlobalScopes()->find($foreign->id);
        $this->assertSame('Fremd-Tag', $foreign->name);
        $this->assertSame('#aa3300', $foreign->color);
        $this->assertSame(2, Tag::withoutGlobalScopes()->where('name', 'Fremd-Tag')->count());
    }

    public function test_the_count_per_tag_covers_only_reservations_of_the_current_tenant(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $mine = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#aa3300', 'scope' => 'reservation']);
        $theirs = Tag::create(['tenant_id' => $other['tenant']->id, 'name' => 'VIP', 'color' => '#aa3300', 'scope' => 'reservation']);
        $this->reservationWith($setup, $mine);
        $this->reservationWith($other, $theirs);
        $this->reservationWith($other, $theirs);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/tags')
            ->assertOk()
            ->assertSee('An 1 Reservierung')
            ->assertDontSee('An 2 Reservierungen');
    }

    public function test_a_salon_reads_in_salon_words(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $vip = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#aa3300', 'scope' => 'reservation']);
        $this->reservationWith($setup, $vip);
        $this->reservationWith($setup, $vip);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/tags')->assertOk()->getContent();
        $main = $this->mainContent($html);
        $drawers = $this->drawers($html);

        $this->assertStringContainsString('An 2 Terminen', $main);
        $this->assertStringContainsString('Tag „VIP“ löschen? Er verschwindet von 2 Terminen.', $main);
        $this->assertStringContainsString('Farbige Markierungen für Termine', $main);
        $this->assertStringContainsString('Name und Farbe, wie sie an Terminen erscheinen.', $drawers);
        $this->assertStringContainsString('Änderungen gelten an allen Terminen mit diesem Tag.', $drawers);
        foreach (['Reservier', 'Tisch'] as $restaurantOnly) {
            $this->assertStringNotContainsString($restaurantOnly, $main);
            $this->assertStringNotContainsString($restaurantOnly, $drawers);
        }
    }

    public function test_an_empty_salon_list_speaks_of_appointments(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $main = $this->mainContent((string) $this->actingAs($owner)->get('/admin/settings/tags')->assertOk()->getContent());

        $this->assertStringContainsString('Noch keine Tags', $main);
        $this->assertStringContainsString('besondere Termine', $main);
        $this->assertStringNotContainsString('Reservier', $main);
    }

    public function test_restaurants_read_in_restaurant_words_and_only_name_places_that_show_tags(): void
    {
        $setup = $this->createTenantSetup();
        Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#aa3300', 'scope' => 'reservation']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/tags')->assertOk()->getContent();
        $main = $this->mainContent($html);
        $drawers = $this->drawers($html);

        $this->assertStringContainsString('Sie erscheinen in den Reservierungsdetails und im Tischplan.', $main);
        $this->assertStringNotContainsString('Live-Board', $main);
        $this->assertStringContainsString('Name und Farbe, wie sie an Reservierungen erscheinen.', $drawers);
        $this->assertStringContainsString('Änderungen gelten an allen Reservierungen mit diesem Tag.', $drawers);
    }

    public function test_the_forms_are_wired_for_saving_and_name_their_fields(): void
    {
        $setup = $this->createTenantSetup();
        $vip = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#aa3300', 'scope' => 'reservation']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/tags')->assertOk();

        $response->assertSee('id="form-tag-neu" method="POST" action="'.route('admin.tags.store').'" data-ajax data-reset-after-save data-reload-after-save', false)
            ->assertSee('id="form-tag-'.$vip->id.'" method="POST" action="'.route('admin.tags.update', $vip).'" data-ajax data-reload-after-save', false)
            ->assertSee('id="tag-loeschen-'.$vip->id.'"', false)
            ->assertSee('action="'.route('admin.tags.destroy', $vip).'"', false)
            ->assertSee('for="tag-neu-name"', false)
            ->assertSee('for="tag-'.$vip->id.'-color"', false)
            ->assertSee('id="tag-'.$vip->id.'-color" type="color" name="color" value="#aa3300"', false)
            ->assertSee('aria-describedby="tag-'.$vip->id.'-color-help"', false)
            ->assertSee('value="VIP"', false)
            ->assertDontSee('data-savebar', false);
        $this->assertStringNotContainsString('onclick=', (string) $response->getContent());
    }

    public function test_a_new_tag_starts_with_the_default_color_from_the_settings(): void
    {
        config(['swayy.ui.default_label_color' => '#123456']);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/tags')
            ->assertOk()
            ->assertSee('id="tag-neu-color" type="color" name="color" value="#123456"', false);
    }

    public function test_the_name_limit_comes_from_the_settings(): void
    {
        $this->assertSame(40, config('swayy.ui.tag_name_max'));

        config(['swayy.ui.tag_name_max' => 12]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/tags')
            ->assertOk()
            ->assertSee('name="name"', false)
            ->assertSee('maxlength="12"', false)
            ->assertDontSee('maxlength="40"', false);

        $this->actingAs($owner)->postJson('/admin/tags', ['name' => str_repeat('a', 12), 'color' => '#aa3300'])->assertCreated();
        $this->actingAs($owner)->postJson('/admin/tags', ['name' => str_repeat('b', 13), 'color' => '#aa3300'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Der Name darf höchstens 12 Zeichen lang sein. Bitte kürze ihn.');

        config(['swayy.ui.tag_name_max' => 20]);
        $this->actingAs($owner)->postJson('/admin/tags', ['name' => str_repeat('b', 13), 'color' => '#aa3300'])->assertCreated();
    }

    public function test_invalid_input_names_the_cause_and_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $tag = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#aa3300', 'scope' => 'reservation']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $nameMissing = 'Bitte gib dem Tag einen Namen.';
        $colorMissing = 'Bitte wähle eine Farbe für den Tag.';
        $colorInvalid = 'Die Farbe ist ungültig. Bitte wähle sie über die Farbwahl oder schreibe sie als #rrggbb, etwa #aa3300.';

        foreach ([['post', '/admin/tags'], ['put', "/admin/tags/{$tag->id}"]] as [$method, $url]) {
            $this->actingAs($owner)->{$method.'Json'}($url, ['name' => '', 'color' => '#aa3300'])
                ->assertStatus(422)->assertJsonPath('errors.name.0', $nameMissing);
            $this->actingAs($owner)->{$method.'Json'}($url, ['name' => 'Neu'])
                ->assertStatus(422)->assertJsonPath('errors.color.0', $colorMissing);
            $this->actingAs($owner)->{$method.'Json'}($url, ['name' => 'Neu', 'color' => 'rot'])
                ->assertStatus(422)->assertJsonPath('errors.color.0', $colorInvalid);
            $this->actingAs($owner)->{$method.'Json'}($url, ['name' => ['VIP'], 'color' => '#aa3300'])
                ->assertStatus(422)->assertJsonPath('errors.name.0', $nameMissing);
        }

        $this->assertSame(1, Tag::withoutGlobalScopes()->count());
    }

    public function test_a_form_without_javascript_comes_back_with_the_reason(): void
    {
        $setup = $this->createTenantSetup();
        Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#aa3300', 'scope' => 'reservation']);
        $other = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'Stammgast', 'color' => '#334455', 'scope' => 'reservation']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->from('/admin/settings/tags')
            ->put("/admin/tags/{$other->id}", ['name' => 'VIP', 'color' => '#334455'])
            ->assertRedirect('/admin/settings/tags')
            ->assertSessionHasErrors(['name' => 'Einen Tag „VIP“ gibt es schon. Bitte einen anderen Namen wählen.']);
    }

    public function test_the_same_name_in_another_scope_does_not_block_a_rename(): void
    {
        $setup = $this->createTenantSetup();
        Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#aa3300', 'scope' => 'guest']);
        $tag = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'Stammgast', 'color' => '#334455', 'scope' => 'reservation']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson("/admin/tags/{$tag->id}", ['name' => 'VIP', 'color' => '#334455'])
            ->assertOk()
            ->assertJsonPath('name', 'VIP');
    }

    public function test_saving_a_tag_without_changing_the_name_is_no_conflict(): void
    {
        $setup = $this->createTenantSetup();
        $tag = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#aa3300', 'scope' => 'reservation']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson("/admin/tags/{$tag->id}", ['name' => 'VIP', 'color' => '#00aa00'])
            ->assertOk()
            ->assertJsonPath('message', 'Tag „VIP“ gespeichert.');
        $this->assertSame('#00aa00', Tag::withoutGlobalScopes()->find($tag->id)->color);
    }

    public function test_the_tag_names_are_unique_per_tenant_in_the_database(): void
    {
        $setup = $this->createTenantSetup();
        $columns = ['tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#aa3300', 'scope' => 'reservation',
            'is_system' => false, 'created_at' => now(), 'updated_at' => now()];
        DB::table('tags')->insert($columns);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('tags')->insert($columns);
    }

    public function test_two_requests_with_a_new_name_create_one_tag(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $tenantId = $setup['tenant']->id;

        // Die zweite Anfrage legt den Tag an, nachdem die erste ihn vergeblich gesucht hat.
        $raced = false;
        DB::listen(function ($query) use (&$raced, $tenantId): void {
            if ($raced || ! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'tags')) {
                return;
            }
            $raced = true;
            DB::table('tags')->insert(['tenant_id' => $tenantId, 'name' => 'VIP', 'color' => '#111111', 'scope' => 'reservation',
                'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
        });

        $response = $this->actingAs($owner)->postJson('/admin/tags', ['name' => 'VIP', 'color' => '#00aa00']);

        $this->assertTrue($raced);
        $response->assertOk()->assertJsonPath('message', 'Einen Tag „VIP“ gab es schon; er hat jetzt die gewählte Farbe.');
        $this->assertSame(1, Tag::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('name', 'VIP')->count());
        $this->assertSame('#00aa00', Tag::withoutGlobalScopes()->where('name', 'VIP')->value('color'));
    }

    public function test_a_rename_that_loses_the_race_for_a_name_explains_the_conflict(): void
    {
        $setup = $this->createTenantSetup();
        $tag = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'Stammgast', 'color' => '#334455', 'scope' => 'reservation']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $tenantId = $setup['tenant']->id;

        // Ein anderer Tag bekommt den Namen, nachdem die Prüfung ihn noch frei fand.
        $raced = false;
        DB::listen(function ($query) use (&$raced, $tenantId): void {
            if ($raced || ! str_contains(strtolower($query->sql), 'exists')) {
                return;
            }
            $raced = true;
            DB::table('tags')->insert(['tenant_id' => $tenantId, 'name' => 'VIP', 'color' => '#111111', 'scope' => 'reservation',
                'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
        });

        $this->actingAs($owner)->putJson("/admin/tags/{$tag->id}", ['name' => 'VIP', 'color' => '#334455'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Einen Tag „VIP“ gibt es schon. Bitte einen anderen Namen wählen.');

        $this->assertTrue($raced);
        $this->assertSame('Stammgast', Tag::withoutGlobalScopes()->find($tag->id)->name);
    }

    public function test_the_actions_are_logged_once_each(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $id = $this->actingAs($owner)->postJson('/admin/tags', ['name' => 'VIP', 'color' => '#aa3300'])->json('id');
        $this->actingAs($owner)->postJson('/admin/tags', ['name' => 'VIP', 'color' => '#00aa00'])->assertOk();
        $this->actingAs($owner)->putJson("/admin/tags/{$id}", ['name' => 'Stammgast', 'color' => '#00aa00'])->assertOk();
        $this->actingAs($owner)->deleteJson("/admin/tags/{$id}")->assertOk();
        $this->actingAs($owner)->deleteJson("/admin/tags/{$id}")->assertNotFound();

        $this->assertSame(1, AuditLog::where('action', 'tag.created')->count());
        $this->assertSame(2, AuditLog::where('action', 'tag.updated')->count());
        $this->assertSame(1, AuditLog::where('action', 'tag.deleted')->count());
    }

    public function test_a_delete_that_loses_the_race_leaves_no_second_log_entry(): void
    {
        $setup = $this->createTenantSetup();
        $tag = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#aa3300', 'scope' => 'reservation']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        // Ein zweites Fenster löscht den Tag, nachdem diese Anfrage ihn geladen hat.
        $raced = false;
        DB::listen(function ($query) use (&$raced, $tag): void {
            if ($raced || ! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'tags')) {
                return;
            }
            $raced = true;
            DB::table('tags')->where('id', $tag->id)->delete();
        });

        $this->actingAs($owner)->deleteJson("/admin/tags/{$tag->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Tag „VIP“ gelöscht.');

        $this->assertTrue($raced);
        $this->assertSame(0, AuditLog::where('action', 'tag.deleted')->count());
    }

    public function test_a_stored_color_in_another_notation_shows_the_default_in_the_color_field(): void
    {
        config(['swayy.ui.default_label_color' => '#123456']);
        $setup = $this->createTenantSetup();
        $tag = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#aabbccdd', 'scope' => 'reservation']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/tags')
            ->assertOk()
            ->assertSee('id="tag-'.$tag->id.'-color" type="color" name="color" value="#123456"', false);
    }

    public function test_a_rejected_color_is_marked_and_linked_to_its_message(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->from('/admin/settings/tags')->followingRedirects()
            ->post('/admin/tags', ['name' => 'VIP', 'color' => 'rot'])
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input id="tag-neu-color" type="color"[^>]*aria-describedby="tag-neu-color-help tag-neu-color-error"[^>]*aria-invalid="true"/s', $html);
        $this->assertStringContainsString('id="tag-neu-color-error"', $html);
        $this->assertStringContainsString('Die Farbe ist ungültig.', $html);
    }

    public function test_deleting_a_tag_removes_it_from_its_reservations(): void
    {
        $setup = $this->createTenantSetup();
        $vip = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#aa3300', 'scope' => 'reservation']);
        $this->reservationWith($setup, $vip);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson("/admin/tags/{$vip->id}")->assertOk();

        $this->assertSame(0, DB::table('taggables')->where('tag_id', $vip->id)->count());
        $this->assertSame(1, Reservation::withoutGlobalScopes()->count());
    }
}
