<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BlackoutPeriod;
use App\Models\Room;
use App\Models\TableBlock;
use App\Models\User;
use App\Support\CapacityModes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Räume und Tische wirken auf Buchungen nur, wo Swayy Tische vergibt: in den Zählweisen „Nach Tischen“ und
 * „Beides“. In „Nach Personen“ gilt eine Grenze für den ganzen Betrieb; Seite, Prüfung und Links sagen das.
 */
class ExceptionsCapacityModeTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const PERSON_LOCK = 'Im Modus „Nach Personen“ vergibt Swayy keine Tische. Nutze „Weniger Gäste“ für den ganzen Betrieb oder stelle unter Kapazität & Dauer auf „Nach Tischen“ um.';

    private const PERSON_ROOM = 'Im Modus „Nach Personen“ zählt Swayy die Gäste im ganzen Betrieb und vergibt keine Räume. Wähle „Ganzer Betrieb“ oder stelle unter Kapazität & Dauer auf „Nach Tischen“ um.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Berlin'));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function modes(): array
    {
        return ['Nach Tischen' => ['table', true], 'Beides' => ['hybrid', true], 'Nach Personen' => ['person', false]];
    }

    /** @return array{0: array, 1: User} */
    private function owner(string $mode): array
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['capacity_mode' => $mode]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        return [$setup, $owner];
    }

    #[DataProvider('modes')]
    public function test_the_form_offers_room_and_table_effects_only_when_swayy_assigns_tables(string $mode, bool $assigns): void
    {
        [, $owner] = $this->owner($mode);

        $response = $this->actingAs($owner)->get('/admin/settings/exceptions')->assertOk()->assertSee('Weniger Gäste');

        if ($assigns) {
            $response->assertSee('Räume oder Tische sperren')
                ->assertSee('id="ausnahme-neu-effects-lock"', false)
                ->assertSee('id="ausnahme-neu-limit_room_id"', false)
                ->assertSee('id="ausnahme-neu-lock_tables-', false)
                ->assertDontSee('Im Modus „Nach Personen“ gilt die Grenze');
        } else {
            $response->assertDontSee('Räume oder Tische sperren')
                ->assertDontSee('id="ausnahme-neu-effects-lock"', false)
                ->assertDontSee('ausnahme-neu-limit_room_id', false)
                ->assertDontSee('ausnahme-neu-lock_tables-', false)
                ->assertSee('Im Modus „Nach Personen“ gilt die Grenze für den ganzen Betrieb.');
        }
    }

    #[DataProvider('modes')]
    public function test_the_server_accepts_room_and_table_effects_only_when_swayy_assigns_tables(string $mode, bool $assigns): void
    {
        [$setup, $owner] = $this->owner($mode);
        $lock = ['from' => '2026-12-01', 'effects' => ['lock'], 'lock_tables' => [$setup['tables'][0]->id]];
        $roomLimit = ['from' => '2026-12-02', 'effects' => ['limit'], 'limit_covers' => 8, 'limit_room_id' => $setup['room']->id];
        $wholeLimit = ['from' => '2026-12-03', 'effects' => ['limit'], 'limit_covers' => 8];

        $lockResponse = $this->actingAs($owner)->postJson('/admin/settings/exceptions', $lock);
        $roomResponse = $this->actingAs($owner)->postJson('/admin/settings/exceptions', $roomLimit);
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', $wholeLimit)->assertOk();
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => '2026-12-04', 'effects' => ['closed']])->assertOk();

        if ($assigns) {
            $lockResponse->assertOk();
            $roomResponse->assertOk();
            $this->assertSame(1, TableBlock::where('location_id', $setup['location']->id)->count());

            return;
        }
        $lockResponse->assertStatus(422)->assertJsonPath('errors.effects.0', self::PERSON_LOCK);
        $roomResponse->assertStatus(422)->assertJsonPath('errors.limit_room_id.0', self::PERSON_ROOM);
        $this->assertSame(0, TableBlock::where('location_id', $setup['location']->id)->count());
        // Die Grenze für den ganzen Betrieb steht als einzige Sperrzeile.
        $this->assertSame([null], BlackoutPeriod::where('location_id', $setup['location']->id)->pluck('room_id')->all());
    }

    #[DataProvider('modes')]
    public function test_the_preview_follows_the_same_rule(string $mode, bool $assigns): void
    {
        [$setup, $owner] = $this->owner($mode);

        $response = $this->actingAs($owner)->postJson('/admin/settings/exceptions/preview', [
            'from' => '2026-12-01', 'effects' => ['lock'], 'lock_rooms' => [$setup['room']->id],
        ]);

        if ($assigns) {
            $response->assertOk();
        } else {
            $response->assertStatus(422)->assertJsonPath('errors.effects.0', self::PERSON_LOCK);
        }
    }

    #[DataProvider('modes')]
    public function test_the_lock_links_follow_the_mode(string $mode, bool $assigns): void
    {
        [$setup, $owner] = $this->owner($mode);
        $table = $setup['tables'][0];

        $rooms = $this->actingAs($owner)->get('/admin/settings/rooms')->assertOk();
        $floorplan = $this->actingAs($owner)->get('/admin/floorplan')->assertOk();
        $preset = (string) $this->actingAs($owner)->get('/admin/settings/exceptions?tisch='.$table->id)->assertOk()->getContent();

        if ($assigns) {
            $rooms->assertSee('Sperren…');
            $floorplan->assertSee('data-lock-url="'.route('admin.settings.exceptions.show').'"', false);
            $this->assertMatchesRegularExpression('/id="ausnahme-neu-effects-lock"[^>]*checked/', $preset);
        } else {
            $rooms->assertDontSee('Sperren…');
            $floorplan->assertDontSee('data-lock-url=', false);
            // Die Vorbelegung über ?tisch= bleibt aus: Es gibt weder Karte noch Kästchen.
            $this->assertStringNotContainsString('data-preset', $preset);
            $this->assertStringNotContainsString('lock_tables-', $preset);
        }
    }

    public function test_entries_with_room_effects_say_so_in_person_mode_and_stay_editable_without_them(): void
    {
        [$setup, $owner] = $this->owner('table');
        $other = Room::factory()->create(['location_id' => $setup['location']->id, 'tenant_id' => $setup['tenant']->id, 'name' => 'Terrasse']);
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-12-05', 'label' => 'Feier', 'effects' => ['limit', 'lock'],
            'limit_covers' => 8, 'limit_room_id' => $other->id, 'lock_tables' => [$setup['tables'][0]->id],
        ])->assertOk();
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => '2026-12-06', 'label' => 'Ruhetag', 'effects' => ['closed']])->assertOk();
        $key = 'g-'.BlackoutPeriod::where('location_id', $setup['location']->id)->value('exception_group');

        $note = 'Räume und Tische wirken auf Buchungen in den Zählweisen „Nach Tischen“ und „Beides“. Aktuell gilt „Nach Personen“ (Kapazität & Dauer).';
        $this->actingAs($owner)->get('/admin/settings/exceptions')->assertOk()->assertDontSee($note);

        $setup['location']->settings()->update(['capacity_mode' => 'person']);
        $content = (string) $this->actingAs($owner)->get('/admin/settings/exceptions')->assertOk()->getContent();

        // Der Hinweis steht einmal, beim Eintrag mit Raum und Sperre; „Ruhetag“ trägt keinen.
        $this->assertSame(1, substr_count($content, e($note)));
        $this->assertLessThan(strpos($content, '>Ruhetag<'), strpos($content, e($note)));
        // Die Seitenleiste zeigt die Karte und den Raum weiter an, damit nichts stumm verloren geht.
        $prefix = preg_quote('ausnahme-'.$key, '/');
        $this->assertMatchesRegularExpression('/id="'.$prefix.'-effects-lock"[^>]*checked/', $content);
        $this->assertMatchesRegularExpression('/<option value="'.$other->id.'" selected>/', $content);

        // Speichern mit Sperre und Raum lehnt der Server ab; ohne beide klappt es.
        $fields = ['from' => '2026-12-05', 'label' => 'Feier', 'effects' => ['limit', 'lock'], 'limit_covers' => 8, 'limit_room_id' => $other->id, 'lock_tables' => [$setup['tables'][0]->id]];
        $this->actingAs($owner)->putJson(route('admin.settings.exceptions.update', $key), $fields)
            ->assertStatus(422)->assertJsonPath('errors.effects.0', self::PERSON_LOCK)->assertJsonPath('errors.limit_room_id.0', self::PERSON_ROOM);
        $this->actingAs($owner)->putJson(route('admin.settings.exceptions.update', $key), ['from' => '2026-12-05', 'label' => 'Feier', 'effects' => ['limit'], 'limit_covers' => 8])->assertOk();
    }

    public function test_the_mode_labels_come_from_one_place(): void
    {
        $this->assertSame('Nach Tischen', CapacityModes::label('table'));
        $this->assertSame('Nach Personen', CapacityModes::label('person'));
        $this->assertSame('Beides', CapacityModes::label('hybrid'));
        $this->assertSame('„Nach Tischen“ und „Beides“', CapacityModes::assigningList());

        // Die Seite Kapazität & Dauer zeigt dieselben Bezeichnungen.
        [, $owner] = $this->owner('table');
        $page = $this->actingAs($owner)->get('/admin/settings/capacity')->assertOk();
        foreach (CapacityModes::LABELS as $label) {
            $page->assertSee($label);
        }
    }
}
