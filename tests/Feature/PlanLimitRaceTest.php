<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AccountImportException;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\Location;
use App\Models\Plan;
use App\Models\RestaurantTable;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\AccountExportService;
use App\Services\AccountImportService;
use App\Services\PlanLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Tarifgrenzen halten auch dann, wenn zwei Anfragen gleichzeitig kommen oder
 * ein Import viele Einträge auf einmal bringt.
 *
 * Die Vorprüfung „noch Platz?“ am Anfang einer Anfrage reicht dafür nicht:
 * Zwei Anfragen sehen beide den letzten freien Platz und legen beide an. Das
 * Anlegen selbst läuft deshalb über PlanLimitService::createWithinLimit, das
 * den Betrieb sperrt und neu zählt. Die Tests simulieren die zweite Anfrage:
 * Die Vorprüfung meldet Platz, beim Anlegen ist die Grenze erreicht.
 */
class PlanLimitRaceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * Die erste Prüfung meldet Platz, wie bei einer Anfrage, die gleichzeitig
     * mit einer anderen kam. Jede weitere Prüfung zählt echt.
     */
    private function gateSeesRoom(): void
    {
        $real = new PlanLimitService;
        $calls = 0;
        $this->partialMock(PlanLimitService::class, function ($mock) use ($real, &$calls) {
            $mock->shouldReceive('canAdd')->andReturnUsing(
                function (Tenant $tenant, string $key) use ($real, &$calls): bool {
                    return ++$calls === 1 ? true : $real->canAdd($tenant, $key);
                }
            );
        });
    }

    /**
     * @param  array<string, int|null>  $limits
     */
    private function limit(Tenant $tenant, array $limits): void
    {
        $tenant->update(['plan_id' => Plan::factory()->create(['limits' => $limits])->id]);
    }

    public function test_a_second_location_is_not_created_when_the_limit_filled_meanwhile(): void
    {
        $setup = $this->createTenantSetup([]);
        $this->limit($setup['tenant'], ['max_locations' => 1]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $this->gateSeesRoom();

        $this->actingAs($owner)->post('/admin/locations', [
            'name' => 'Zweiter Standort',
            'timezone' => 'Europe/Berlin',
        ])->assertSessionHasErrors(['name' => 'Dein Tarif umfasst einen Standort, und der ist schon angelegt. Für einen weiteren Standort den Tarif unter „Abo & Rechnungen“ wechseln.']);

        $this->assertSame(1, Location::withoutGlobalScopes()->where('tenant_id', $setup['tenant']->id)->count());
    }

    public function test_an_event_is_not_created_when_the_limit_filled_meanwhile(): void
    {
        $setup = $this->createTenantSetup([]);
        $this->limit($setup['tenant'], ['max_events' => 1]);
        Event::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'title' => 'Weinabend', 'slug' => 'weinabend', 'capacity' => 20,
            'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(5)->addHours(3),
            'status' => 'published', 'currency' => 'EUR',
        ]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();
        $this->gateSeesRoom();

        $this->actingAs($admin)->post('/admin/events', [
            'title' => 'Silvester-Menü',
            'date' => now()->addDays(30)->toDateString(),
            'start_time' => '19:00',
            'end_time' => '23:00',
            'capacity' => 60,
        ])->assertSessionHasErrors(['title' => 'Dein Tarif umfasst ein Event, und das ist schon angelegt. Für ein weiteres Event den Tarif unter „Abo & Rechnungen“ wechseln.']);

        $this->assertSame(1, Event::withoutGlobalScopes()->where('tenant_id', $setup['tenant']->id)->count());
    }

    public function test_an_existing_account_is_not_added_when_the_limit_filled_meanwhile(): void
    {
        $setup = $this->createTenantSetup([]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->limit($setup['tenant'], ['max_users' => 1]);
        $other = User::factory()->create(['email' => 'service@example.test']);
        $this->clearTenantContext();
        $this->gateSeesRoom();

        $this->actingAs($owner)->postJson('/admin/users/invite', [
            'email' => 'service@example.test',
            'role' => 'staff',
            'all_locations' => '1',
        ])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Dein Tarif umfasst einen Zugang, und der ist vergeben. Für weitere Personen den Tarif unter „Abo & Rechnungen“ wechseln oder einen Zugang entfernen.');

        $this->assertFalse(TenantUser::where('user_id', $other->id)->exists());
    }

    public function test_accepting_an_invitation_respects_the_user_limit(): void
    {
        $setup = $this->createTenantSetup([]);
        $this->createMember($setup['tenant'], 'tenant_owner');
        $this->limit($setup['tenant'], ['max_users' => 1]);
        $invitation = Invitation::create([
            'tenant_id' => $setup['tenant']->id,
            'email' => 'neu@example.test',
            'role' => 'staff',
            'all_locations' => true,
        ]);
        $this->clearTenantContext();

        $this->from('/invitation/'.$invitation->token)->post('/invitation/'.$invitation->token, [
            'name' => 'Neue Kollegin',
            'password' => 'ein-langes-passwort-1',
            'password_confirmation' => 'ein-langes-passwort-1',
        ])->assertRedirect('/invitation/'.$invitation->token)
            ->assertSessionHasErrors(['name' => 'Im Team sind alle Zugänge des Tarifs vergeben. Bitte die Person, die dich eingeladen hat, einen Zugang freizugeben oder den Tarif zu wechseln. Danach funktioniert dieser Link wieder.']);

        $this->assertGuest();
        $this->assertFalse(User::where('email', 'neu@example.test')->exists());
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->assertSame(1, TenantUser::where('tenant_id', $setup['tenant']->id)->count());
    }

    public function test_an_existing_account_accepting_an_invitation_respects_the_user_limit(): void
    {
        $setup = $this->createTenantSetup([]);
        $this->createMember($setup['tenant'], 'tenant_owner');
        $this->limit($setup['tenant'], ['max_users' => 1]);
        $existing = User::factory()->create(['email' => 'vorhanden@example.test']);
        $invitation = Invitation::create([
            'tenant_id' => $setup['tenant']->id,
            'email' => 'vorhanden@example.test',
            'role' => 'staff',
            'all_locations' => true,
        ]);
        $this->clearTenantContext();

        $this->from('/invitation/'.$invitation->token)->post('/invitation/'.$invitation->token, [
            'name' => 'Egal',
            'password' => 'ein-langes-passwort-1',
            'password_confirmation' => 'ein-langes-passwort-1',
        ])->assertSessionHasErrors('name');

        $this->assertFalse(TenantUser::where('user_id', $existing->id)->exists());
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    /**
     * Ein Quellbetrieb mit einem Standort, einem Raum und $tables Tischen als
     * Export-Datei.
     *
     * @return array<string, mixed>
     */
    private function exportWithTables(int $tables): array
    {
        $setup = $this->createTenantSetup(array_fill(0, $tables, ['min' => 1, 'max' => 4]));
        $export = app(AccountExportService::class)->export($setup['tenant']);
        $setup['tenant']->forceDelete();
        $this->clearTenantContext();

        return $export;
    }

    public function test_an_import_that_exceeds_the_table_limit_is_rejected_completely(): void
    {
        $export = $this->exportWithTables(3);
        $target = Tenant::factory()->create(['plan_id' => Plan::factory()->create(['limits' => ['max_locations' => 5, 'max_tables' => 2]])]);

        try {
            app(AccountImportService::class)->import($target, $export);
            $this->fail('Der Import hätte an der Tischgrenze scheitern müssen.');
        } catch (AccountImportException $e) {
            $this->assertSame(
                'Mit dieser Datei hätte der Betrieb mehr, als der Tarif erlaubt: 3 Tische, erlaubt sind 2. Die Datei wurde nicht übernommen. Bitte den Tarif unter „Abo & Rechnungen“ wechseln oder die Datei vor dem Import kürzen.',
                $e->getMessage(),
            );
        }

        $this->assertSame(0, Location::withoutGlobalScopes()->where('tenant_id', $target->id)->count());
        $this->assertSame(0, RestaurantTable::withoutGlobalScopes()->where('tenant_id', $target->id)->count());
    }

    public function test_an_import_names_every_exceeded_limit(): void
    {
        $export = $this->exportWithTables(3);
        $target = Tenant::factory()->create(['plan_id' => Plan::factory()->create(['limits' => ['max_locations' => 0, 'max_tables' => 1]])]);

        $this->expectException(AccountImportException::class);
        $this->expectExceptionMessage('1 Standort, erlaubt sind 0; 3 Tische, erlaubt ist 1.');

        app(AccountImportService::class)->import($target, $export);
    }

    public function test_an_import_within_the_limit_still_works(): void
    {
        $export = $this->exportWithTables(3);
        $target = Tenant::factory()->create(['plan_id' => Plan::factory()->create(['limits' => ['max_locations' => 1, 'max_tables' => 3]])]);

        $imported = app(AccountImportService::class)->import($target, $export);

        $this->assertSame(1, $imported['locations']);
        $this->assertSame(3, $imported['tables']);
    }

    public function test_a_business_already_over_a_limit_can_import_what_does_not_add_to_it(): void
    {
        // Der Zielbetrieb hat drei Tische, der Tarif erlaubt nur einen (etwa
        // nach einem Wechsel in einen kleineren Tarif). Eine Datei ohne Tische
        // ändert daran nichts und geht durch.
        $target = $this->createTenantSetup();
        $this->limit($target['tenant'], ['max_locations' => 5, 'max_tables' => 1]);
        $export = $this->exportWithTables(0);

        $imported = app(AccountImportService::class)->import($target['tenant']->fresh(), $export);

        $this->assertSame(1, $imported['locations']);
        $this->assertSame(3, RestaurantTable::withoutGlobalScopes()->where('tenant_id', $target['tenant']->id)->count());
    }

    public function test_the_upload_shows_the_limit_message(): void
    {
        Storage::fake();
        $export = $this->exportWithTables(3);
        $setup = $this->createTenantSetup([]);
        $this->limit($setup['tenant'], ['max_locations' => 5, 'max_tables' => 2]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $file = UploadedFile::fake()->createWithContent('export.json', (string) json_encode($export));

        $this->actingAs($owner)
            ->post('/admin/account/import', ['file' => $file, 'confirm' => '1'])
            ->assertSessionHasErrors(['file' => 'Mit dieser Datei hätte der Betrieb mehr, als der Tarif erlaubt: 3 Tische, erlaubt sind 2. Die Datei wurde nicht übernommen. Bitte den Tarif unter „Abo & Rechnungen“ wechseln oder die Datei vor dem Import kürzen.']);

        $this->assertSame(1, Location::withoutGlobalScopes()->where('tenant_id', $setup['tenant']->id)->count());
    }
}
