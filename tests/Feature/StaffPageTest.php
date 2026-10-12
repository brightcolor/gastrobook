<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\TenantType;
use App\Http\Controllers\Admin\StaffMemberController;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\StaffAbsence;
use App\Models\StaffMember;
use App\Models\StaffWorkingHour;
use App\Services\ReservationLifecycleService;
use App\Services\SalonAvailabilityService;
use App\Services\TimeSlotService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class StaffPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const REMOVED = 'Die gewählte Person steht nicht mehr zur Verfügung. Bitte eine andere Person wählen.';

    /**
     * @return array<string, mixed>
     */
    private function salon(): array
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $setup['owner'] = $this->createMember($setup['tenant'], 'tenant_owner');
        $setup['member'] = StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => 'Anna', 'is_active' => true,
        ]);

        return $setup;
    }

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>  $attributes
     */
    private function person(array $setup, string $name, array $attributes = []): StaffMember
    {
        return StaffMember::create($attributes + [
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => $name, 'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>  $attributes
     */
    private function service(array $setup, string $name = 'Schnitt', array $attributes = []): Service
    {
        return Service::create($attributes + [
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => $name,
            'duration_minutes' => 60, 'price_minor' => 3500, 'currency' => 'EUR', 'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function appointment(array $setup, StaffMember $member, ReservationStatus $status = ReservationStatus::Confirmed, int $days = 1): Reservation
    {
        $start = CarbonImmutable::now($setup['location']->timezone)->addDays($days)->setTime(10, 0);

        return Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 1,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHour()->utc(),
            'timezone' => $setup['location']->timezone, 'status' => $status, 'source' => 'online',
            'guest_name_snapshot' => 'Testgast', 'staff_member_id' => $member->id,
        ]);
    }

    /** Der Tag in so vielen Tagen als Datum (JJJJ-MM-TT); Abwesenheiten liegen im erlaubten Zeitraum um heute. */
    private function in(int $days): string
    {
        return CarbonImmutable::now()->addDays($days)->toDateString();
    }

    /** Inhalt der Seite; die Navigation führt Einträge beider Betriebstypen auf. */
    private function mainContent(string $html): string
    {
        $this->assertSame(1, preg_match('/<main.*?<\/main>/s', $html, $main));

        return $main[0];
    }

    /** Seitenleisten der Seite: Sie stehen hinter dem Hauptbereich. */
    private function drawers(string $html): string
    {
        $start = strpos($html, 'id="person-neu" data-drawer');
        $end = strpos($html, 'id="swayyToast"');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    /** Seitenleiste „Neue Person“: vom Anfang bis zum Ende ihres Formulars. */
    private function newDrawer(string $html): string
    {
        $drawers = $this->drawers($html);

        return substr($drawers, 0, (int) strpos($drawers, '</form>'));
    }

    /** Seitenleiste einer Person: von ihrem Anfang bis zur nächsten Seitenleiste. */
    private function drawerOf(string $html, StaffMember $member): string
    {
        $opening = 'id="person-'.$member->id.'" data-drawer hidden';
        $start = strpos($html, $opening);
        $this->assertNotFalse($start);
        $next = strpos($html, 'data-drawer hidden', $start + strlen($opening));

        return substr($html, $start, $next === false ? null : $next - $start);
    }

    // ── Aufbau der Seite ─────────────────────────────────────────────────

    public function test_salon_owner_sees_people_with_their_drawer_sections(): void
    {
        $setup = $this->salon();
        $service = $this->service($setup);
        $setup['member']->services()->attach($service->id);
        StaffWorkingHour::create([
            'tenant_id' => $setup['tenant']->id, 'staff_member_id' => $setup['member']->id,
            'weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '17:00',
        ]);
        $this->clearTenantContext();

        $response = $this->actingAs($setup['owner'])->get('/admin/staff')->assertOk();

        $id = $setup['member']->id;
        $response->assertSee('Einstellungen › Platz & Zeiten')
            ->assertSee('Gilt für Standort')
            ->assertSee('data-drawer-open="person-'.$id.'"', false)
            ->assertSee('id="person-neu" data-drawer hidden', false)
            ->assertSee('id="person-'.$id.'" data-drawer hidden', false)
            ->assertSee('name="hours[0][starts_at]"', false)
            ->assertSee('value="09:00"', false)
            ->assertSee('name="hours[__INDEX__][weekday]"', false)
            ->assertSee('action="'.route('admin.staff.absences.store', $setup['member']).'"', false)
            ->assertSee('Schnitt · 1 Zeitfenster je Woche')
            ->assertSee('<span class="sr-only">Buchbar</span>', false);
        $this->assertMatchesRegularExpression('/data-nav-key="staff"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_an_empty_list_offers_the_first_person(): void
    {
        $setup = $this->salon();
        $setup['member']->delete();
        $this->clearTenantContext();

        $main = $this->mainContent((string) $this->actingAs($setup['owner'])->get('/admin/staff')->assertOk()->getContent());

        $this->assertStringContainsString('Noch niemand angelegt', $main);
        $this->assertStringNotContainsString('<ul class="divide-y', $main);
        $this->assertSame(2, substr_count($main, 'data-drawer-open="person-neu"'));
    }

    public function test_a_person_without_services_or_hours_reads_naturally(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();

        $main = $this->mainContent((string) $this->actingAs($setup['owner'])->get('/admin/staff')->assertOk()->getContent());

        $this->assertStringContainsString('Noch keine Leistung · Arbeitszeit wie Öffnungszeiten', $main);
        $this->assertStringNotContainsString('0 Zeitfenster', $main);
        $this->assertStringNotContainsString('abwesend', $main);
    }

    public function test_rows_and_drawers_carry_the_hooks_of_the_script(): void
    {
        $setup = $this->salon();
        $setup['member']->update(['color' => '#aa3300', 'bio' => 'Farbe & Schnitt']);
        StaffWorkingHour::create([
            'tenant_id' => $setup['tenant']->id, 'staff_member_id' => $setup['member']->id,
            'weekday' => 2, 'starts_at' => '10:00', 'ends_at' => '14:30',
        ]);
        $this->clearTenantContext();
        $id = $setup['member']->id;

        $html = (string) $this->actingAs($setup['owner'])->get('/admin/staff')->assertOk()->getContent();
        $drawer = $this->drawerOf($html, $setup['member']);

        // Anlegen setzt zurück und lädt neu; Bearbeiten, Arbeitszeiten und Abwesenheit laden neu; keines trägt die Speicherleiste.
        $this->assertSame(1, preg_match('/<form id="form-person-neu"[^>]*>/', $html, $new));
        $this->assertStringContainsString('data-ajax data-reset-after-save data-reload-after-save', $new[0]);
        $this->assertSame(1, preg_match('/<form id="form-person-'.$id.'"[^>]*>/', $html, $edit));
        $this->assertStringContainsString('data-ajax data-reload-after-save', $edit[0]);
        $this->assertStringNotContainsString('data-reset-after-save', $edit[0]);
        $this->assertSame(1, preg_match('/<form [^>]*action="[^"]*working-hours"[^>]*>/', $drawer, $hours));
        $this->assertStringContainsString('data-ajax data-reload-after-save', $hours[0]);
        $this->assertSame(1, preg_match('/<form [^>]*action="[^"]*\/absences"[^>]*>/', $drawer, $absence));
        $this->assertStringContainsString('data-ajax data-reset-after-save data-reload-after-save', $absence[0]);
        $this->assertDoesNotMatchRegularExpression('/<form[^>]*data-savebar/', $html);
        // Der Knopf „Profil speichern“ steht in der Fußzeile und zeigt auf das Hauptformular.
        $this->assertStringContainsString('type="submit" form="form-person-'.$id.'"', $drawer);
        // Löschen fragt in der Zeile nach, lädt danach neu und benennt die Person für Screenreader.
        $this->assertMatchesRegularExpression('/<form id="person-loeschen-'.$id.'"[^>]*data-ajax\s+data-reload-after-save/', $html);
        $this->assertStringContainsString('<span class="sr-only"> „Anna“</span>', $html);
        $this->assertStringContainsString('„Anna“ löschen? Arbeitszeiten und Abwesenheiten gehen mit. Frühere Termine zeigen die Person dann im Terminplan mit dem Vermerk „entfernt“.', $html);
        // Zeilen: Liste, Vorlage, Hinzufügen, Entfernen, Gruppe mit Namen und die Grenze aus den Einstellungen.
        $this->assertStringContainsString('data-rows data-rows-max="28"', $drawer);
        $this->assertStringContainsString('data-rows-list', $drawer);
        $this->assertStringContainsString('<template data-rows-template>', $drawer);
        $this->assertStringContainsString('data-rows-add', $drawer);
        $this->assertStringContainsString('data-rows-remove', $drawer);
        $this->assertMatchesRegularExpression('/<div data-row [^>]*role="group" aria-label="Zeitfenster"/', $drawer);
        $this->assertStringContainsString('<span class="sr-only"> dieses Zeitfenster</span>', $drawer);
        // Die Seitenleiste führt keine Speicherleiste: Die Zeile trägt keinen Änderungspunkt.
        $this->assertStringNotContainsString('data-change-dot', $drawer);
        $this->assertStringNotContainsString('data-change-item', $drawer);
        // Jedes Formular nennt seinen Abschnitt für den Hinweis, wenn nach dem Speichern des Profils noch etwas offen ist.
        $this->assertStringContainsString('data-drawer-section="Profil"', $edit[0]);
        $this->assertStringContainsString('data-drawer-section="Arbeitszeiten"', $hours[0]);
        $this->assertStringContainsString('data-drawer-section="Abwesenheit"', $absence[0]);
        // Die Vorlage trägt __INDEX__ in allen Namen und IDs, die gespeicherte Zeile ihre Werte.
        $this->assertStringContainsString('name="hours[__INDEX__][starts_at]"', $drawer);
        $this->assertStringContainsString('id="wh-'.$id.'-__INDEX__-start"', $drawer);
        $this->assertMatchesRegularExpression('/name="hours\[0\]\[weekday\]"/', $drawer);
        $this->assertMatchesRegularExpression('/<option value="2" selected>Mittwoch<\/option>/', $drawer);
        $this->assertMatchesRegularExpression('/name="hours\[0\]\[ends_at\]"[^>]*value="14:30"/', $drawer);
        // Profil: gespeicherte Werte, Farbe, Vorstellung und der Schalter.
        $this->assertMatchesRegularExpression('/id="person-'.$id.'-name" name="name" type="text" value="Anna"/', $drawer);
        $this->assertMatchesRegularExpression('/type="color" name="color" value="#aa3300"/', $drawer);
        $this->assertStringContainsString('Farbe &amp; Schnitt</textarea>', $drawer);
        $this->assertStringContainsString('<input type="hidden" name="is_active" value="0">', $drawer);
        // Die Person zeigt ihre Farbe als Punkt vor dem Namen.
        $this->assertStringContainsString('background-color: #aa3300', $this->mainContent($html));
    }

    public function test_the_color_field_offers_the_default_and_a_stored_color_outside_the_notation_falls_back(): void
    {
        $setup = $this->salon();
        $alpha = $this->person($setup, 'Ben', ['color' => '#aabbccdd']);
        $style = $this->person($setup, 'Cem', ['color' => 'red;top:0']);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($setup['owner'])->get('/admin/staff')->assertOk()->getContent();

        // Der Vorschlag kommt aus config('swayy.ui.default_staff_color'); nur #rrggbb gelangt in Farbfeld und Stil.
        $this->assertStringNotContainsString('top:0', $html);
        $this->assertStringNotContainsString('#aabbccdd', $html);
        foreach ([$setup['member'], $alpha, $style] as $member) {
            $this->assertMatchesRegularExpression('/id="person-'.$member->id.'-color" type="color" name="color" value="#0f766e"/', $html);
        }
        $this->assertStringContainsString('id="person-neu-color" type="color" name="color" value="#0f766e"', $html);
        $this->assertStringNotContainsString('background-color', $this->mainContent($html));

        config(['swayy.ui.default_staff_color' => '#112233']);
        $this->assertStringContainsString('id="person-neu-color" type="color" name="color" value="#112233"', (string) $this->actingAs($setup['owner'])->get('/admin/staff')->getContent());
    }

    public function test_a_paused_person_says_so_next_to_its_icon(): void
    {
        $setup = $this->salon();
        $this->person($setup, 'Ben', ['is_active' => false]);
        $this->clearTenantContext();

        $main = $this->mainContent((string) $this->actingAs($setup['owner'])->get('/admin/staff')->assertOk()->getContent());

        $this->assertStringContainsString('Arbeitszeit wie Öffnungszeiten · Pausiert', $main);
        // Das Wort steht sichtbar in der Zeile; das Symbol liest es nicht noch einmal vor.
        $this->assertStringNotContainsString('<span class="sr-only">Pausiert</span>', $main);
        $this->assertStringContainsString('title="Pausiert"', $main);
    }

    public function test_people_are_listed_in_natural_order(): void
    {
        $setup = $this->salon();
        $first = $this->person($setup, 'Team 10');
        $second = $this->person($setup, 'Team 2');
        $third = $this->person($setup, 'Zoe', ['sort_order' => 5]);
        $this->clearTenantContext();

        $main = $this->mainContent((string) $this->actingAs($setup['owner'])->get('/admin/staff')->assertOk()->getContent());

        $order = [];
        foreach ([$setup['member'], $first, $second, $third] as $member) {
            $order[$member->name] = strpos($main, 'data-drawer-open="person-'.$member->id.'"');
        }
        asort($order);
        $this->assertSame(['Anna', 'Team 2', 'Team 10', 'Zoe'], array_keys($order));
    }

    public function test_a_name_with_an_umlaut_stands_at_its_base_letter(): void
    {
        $setup = $this->salon();
        $setup['member']->update(['name' => 'Bert']);
        $people = [$setup['member'], $this->person($setup, 'Änne'), $this->person($setup, 'Abel')];
        $this->clearTenantContext();

        $main = $this->mainContent((string) $this->actingAs($setup['owner'])->get('/admin/staff')->assertOk()->getContent());

        $order = [];
        foreach ($people as $member) {
            $order[$member->fresh()->name] = strpos($main, 'data-drawer-open="person-'.$member->id.'"');
        }
        asort($order);
        $this->assertSame(['Abel', 'Änne', 'Bert'], array_keys($order));
    }

    public function test_the_list_names_a_running_and_an_upcoming_absence(): void
    {
        $setup = $this->salon();
        $ben = $this->person($setup, 'Ben');
        $timezone = $setup['location']->timezone;
        $now = CarbonImmutable::now($timezone);
        StaffAbsence::create([
            'tenant_id' => $setup['tenant']->id, 'staff_member_id' => $setup['member']->id,
            'starts_at' => $now->subDay()->utc(), 'ends_at' => $now->addDays(2)->utc(),
        ]);
        StaffAbsence::create([
            'tenant_id' => $setup['tenant']->id, 'staff_member_id' => $ben->id,
            'starts_at' => $now->addDays(5)->utc(), 'ends_at' => $now->addDays(7)->utc(),
        ]);
        $this->clearTenantContext();

        $main = $this->mainContent((string) $this->actingAs($setup['owner'])->get('/admin/staff')->assertOk()->getContent());

        $this->assertStringContainsString('gerade abwesend bis '.$now->addDays(2)->format('d.m.'), $main);
        $this->assertStringContainsString('abwesend ab '.$now->addDays(5)->format('d.m.'), $main);
    }

    public function test_an_absence_in_the_drawer_shows_in_the_location_time_and_can_be_removed_in_the_row(): void
    {
        $setup = $this->salon();
        $absence = StaffAbsence::create([
            'tenant_id' => $setup['tenant']->id, 'staff_member_id' => $setup['member']->id,
            'starts_at' => CarbonImmutable::parse($this->in(30).' 00:00', $setup['location']->timezone)->utc(),
            'ends_at' => CarbonImmutable::parse($this->in(34).' 23:59', $setup['location']->timezone)->utc(),
            'reason' => 'Urlaub <b>',
        ]);
        $this->clearTenantContext();

        $drawer = $this->drawerOf((string) $this->actingAs($setup['owner'])->get('/admin/staff')->assertOk()->getContent(), $setup['member']);

        $span = CarbonImmutable::parse($this->in(30))->format('d.m.Y').' 00:00 – '.CarbonImmutable::parse($this->in(34))->format('d.m.Y').' 23:59';
        $this->assertStringContainsString($span.' · Urlaub &lt;b&gt;', $drawer);
        $this->assertMatchesRegularExpression('/<form id="abwesenheit-loeschen-'.$absence->id.'"[^>]*data-ajax\s+data-reload-after-save/', $drawer);
        $this->assertStringContainsString('Abwesenheit '.$span.' entfernen? Anna ist dann wieder buchbar.', $drawer);
    }

    /** Der Eintrag der Abwesenheit mit diesem Grund in der Seitenleiste. */
    private function absenceItem(string $drawer, string $reason): string
    {
        foreach (explode('<li ', $drawer) as $item) {
            if (str_contains($item, '>'.$reason.'<') || str_contains($item, ' · '.$reason.'<')) {
                return $item;
            }
        }
        $this->fail("Keine Abwesenheit mit dem Grund „{$reason}“ in der Seitenleiste.");
    }

    public function test_the_absence_list_names_the_booked_appointments_of_each_absence(): void
    {
        $setup = $this->salon();
        $anna = $setup['member'];
        $ben = $this->person($setup, 'Ben');
        $timezone = $setup['location']->timezone;
        $day = fn (int $days): CarbonImmutable => CarbonImmutable::now($timezone)->addDays($days)->startOfDay();
        $absence = fn (StaffMember $member, CarbonImmutable $from, CarbonImmutable $to, string $reason) => StaffAbsence::create([
            'tenant_id' => $setup['tenant']->id, 'staff_member_id' => $member->id,
            'starts_at' => $from->utc(), 'ends_at' => $to->utc(), 'reason' => $reason,
        ]);

        // Null Termine: nichts davon zählt (anderer Tag, abgesagt, gelöscht, andere Person).
        $absence($anna, $day(20), $day(21)->endOfDay(), 'Leer');
        $this->appointment($setup, $anna, ReservationStatus::Confirmed, 19);
        // Ein Termin: nur der bestätigte am Tag selbst.
        $absence($anna, $day(3), $day(3)->endOfDay(), 'Eins');
        $this->appointment($setup, $anna, ReservationStatus::Confirmed, 3);
        $this->appointment($setup, $anna, ReservationStatus::CancelledByGuest, 3);
        $this->appointment($setup, $anna, ReservationStatus::Confirmed, 3)->delete();
        $this->appointment($setup, $ben, ReservationStatus::Confirmed, 3);
        // Mehrere Termine über drei Tage.
        $absence($anna, $day(5), $day(7)->endOfDay(), 'Drei');
        foreach ([5, 6, 7] as $days) {
            $this->appointment($setup, $anna, ReservationStatus::Confirmed, $days);
        }
        // Ein Termin, der vor der Abwesenheit beginnt: Der erste betroffene Tag ist der Tag der Abwesenheit.
        $absence($ben, $day(12), $day(12)->endOfDay(), 'Nacht');
        Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 1,
            'reservation_date' => $day(11)->toDateString(), 'start_at' => $day(11)->setTime(23, 30)->utc(), 'end_at' => $day(12)->setTime(0, 30)->utc(),
            'timezone' => $timezone, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Nachtgast', 'staff_member_id' => $ben->id,
        ]);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($setup['owner'])->get('/admin/staff')->assertOk()->getContent();
        $annaDrawer = $this->drawerOf($html, $anna);
        $calendar = fn (CarbonImmutable $date): string => 'href="'.route('admin.staff.calendar', ['date' => $date->toDateString()]).'"';

        $none = $this->absenceItem($annaDrawer, 'Leer');
        $this->assertStringNotContainsString('gebucht', $none);
        $this->assertStringNotContainsString('<svg', $none);

        $one = $this->absenceItem($annaDrawer, 'Eins');
        $this->assertStringContainsString('1 gebuchter Termin in dieser Zeit. Bitte weise ihn einer anderen Person zu oder sage ihn ab.', $one);
        $this->assertStringContainsString($calendar($day(3)), $one);

        $three = $this->absenceItem($annaDrawer, 'Drei');
        $this->assertStringContainsString('3 gebuchte Termine in dieser Zeit. Bitte weise sie anderen Personen zu oder sage sie ab.', $three);
        $this->assertStringContainsString($calendar($day(5)), $three);

        $night = $this->absenceItem($this->drawerOf($html, $ben), 'Nacht');
        $this->assertStringContainsString('1 gebuchter Termin in dieser Zeit.', $night);
        $this->assertStringContainsString($calendar($day(12)), $night);

        // Das Warnsymbol trägt keinen eigenen Vorlesetext: Der Satz steht sichtbar daneben.
        $this->assertStringContainsString('<svg', $three);
        $this->assertStringNotContainsString('<span class="sr-only">Termine in dieser Zeit</span>', $three);
        $this->assertStringContainsString('title="Termine in dieser Zeit"', $three);
        $this->assertStringContainsString('text-amber-700', $three);
    }

    public function test_the_absence_notes_come_from_one_query_for_the_whole_page(): void
    {
        $setup = $this->salon();
        $timezone = $setup['location']->timezone;
        $absenceFor = function (StaffMember $member, int $days) use ($setup, $timezone): void {
            $from = CarbonImmutable::now($timezone)->addDays($days)->startOfDay();
            StaffAbsence::create([
                'tenant_id' => $setup['tenant']->id, 'staff_member_id' => $member->id,
                'starts_at' => $from->utc(), 'ends_at' => $from->endOfDay()->utc(), 'reason' => 'Urlaub',
            ]);
            $this->appointment($setup, $member, ReservationStatus::Confirmed, $days);
        };
        $absenceFor($setup['member'], 2);
        $this->clearTenantContext();

        $measure = function () use ($setup): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($setup['owner'])->get('/admin/staff')->assertOk();
            $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $sql) => strtolower($sql));

            return [
                $queries->count(),
                $queries->filter(fn (string $sql) => str_contains($sql, 'from "reservations"') && str_contains($sql, '"staff_member_id" in'))->count(),
            ];
        };
        // Die erste Anfrage lädt zusätzlich die Mitgliedschaft des Benutzers; sie zählt nicht mit.
        $measure();
        [$few, $fewReservationQueries] = $measure();

        // Mehr Personen mit mehreren Abwesenheiten und Terminen: Die Zahl der Abfragen wächst nicht.
        foreach (['Ben', 'Cem', 'Dora', 'Emil'] as $name) {
            $person = $this->person($setup, $name);
            foreach ([3, 6, 9] as $days) {
                $absenceFor($person, $days);
            }
        }
        $this->clearTenantContext();
        [$many, $manyReservationQueries] = $measure();

        $this->assertSame(1, $fewReservationQueries);
        $this->assertSame(1, $manyReservationQueries);
        $this->assertSame($few, $many);
    }

    public function test_the_page_uses_no_inline_handlers_or_scripts(): void
    {
        foreach (['index', 'partials/fields', 'partials/hour-row'] as $view) {
            $source = (string) file_get_contents(resource_path("views/admin/staff/{$view}.blade.php"));
            $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $source, $view);
            $this->assertStringNotContainsString('<script', $source, $view);
        }
    }

    // ── Zugang ───────────────────────────────────────────────────────────

    public function test_restaurants_get_an_explanation(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $member = $this->person($setup, 'Anna');
        $absence = StaffAbsence::create([
            'tenant_id' => $setup['tenant']->id, 'staff_member_id' => $member->id,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'reason' => 'Urlaub',
        ]);
        $this->clearTenantContext();

        $message = 'Mitarbeiter mit Arbeitszeiten gibt es bei Salons. Den Betriebstyp stellst du unter Einstellungen › Allgemein um.';
        $this->actingAs($owner)->getJson('/admin/staff')->assertForbidden()->assertJsonPath('message', $message);
        $this->actingAs($owner)->get('/admin/staff')->assertForbidden()->assertSee($message);

        // Auch die Schreibwege antworten so; es entsteht, ändert und verschwindet nichts.
        $this->actingAs($owner)->postJson('/admin/staff', ['name' => 'Neu', 'is_active' => '1'])->assertForbidden()->assertJsonPath('message', $message);
        $this->actingAs($owner)->putJson("/admin/staff/{$member->id}", ['name' => 'Anders', 'is_active' => '1'])->assertForbidden()->assertJsonPath('message', $message);
        $this->actingAs($owner)->putJson("/admin/staff/{$member->id}/working-hours", ['hours' => [['weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '17:00']]])->assertForbidden()->assertJsonPath('message', $message);
        $this->actingAs($owner)->postJson("/admin/staff/{$member->id}/absences", ['starts_on' => $this->in(30), 'ends_on' => $this->in(31)])->assertForbidden()->assertJsonPath('message', $message);
        $this->actingAs($owner)->deleteJson("/admin/staff/absences/{$absence->id}")->assertForbidden()->assertJsonPath('message', $message);
        $this->actingAs($owner)->deleteJson("/admin/staff/{$member->id}")->assertForbidden()->assertJsonPath('message', $message);

        $this->assertSame(1, StaffMember::withoutGlobalScopes()->count());
        $this->assertSame('Anna', $member->fresh()->name);
        $this->assertSame(1, StaffAbsence::withoutGlobalScopes()->count());
        $this->assertSame(0, StaffWorkingHour::withoutGlobalScopes()->count());
        $this->assertSame(0, AuditLog::where('action', 'like', 'staff_member.%')->count());
    }

    public function test_the_team_without_the_right_is_turned_away(): void
    {
        $setup = $this->salon();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/staff')->assertForbidden();
        $this->actingAs($host)->postJson('/admin/staff', ['name' => 'Neu', 'is_active' => '1'])->assertForbidden();
        $this->actingAs($host)->deleteJson("/admin/staff/{$setup['member']->id}")->assertForbidden();
        $this->assertSame(1, StaffMember::withoutGlobalScopes()->count());
    }

    // ── Anlegen und Ändern ───────────────────────────────────────────────

    public function test_a_new_person_answers_with_id_and_refuses_foreign_services(): void
    {
        $setup = $this->salon();
        $own = $this->service($setup);
        $other = $this->createTenantSetup();
        $foreign = $this->service($other, 'Fremd');
        $otherLocation = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $elsewhere = $this->service(['tenant' => $setup['tenant'], 'location' => $otherLocation], 'Woanders');
        $gone = $this->service($setup, 'Weg');
        $gone->delete();
        $this->clearTenantContext();
        $reason = 'Mindestens eine gewählte Leistung gehört nicht zu diesem Standort oder wurde entfernt. Bitte die Seite neu laden und die Leistungen neu wählen.';

        foreach ([$foreign->id, $elsewhere->id, $gone->id, 999999] as $unknown) {
            $this->actingAs($setup['owner'])->postJson('/admin/staff', [
                'name' => 'Ben', 'color' => '#0f766e', 'is_active' => '1', 'service_ids' => [$own->id, $unknown],
            ])->assertStatus(422)->assertJsonPath('errors.service_ids.0', $reason);
        }
        $this->assertSame(1, StaffMember::withoutGlobalScopes()->count());

        $response = $this->actingAs($setup['owner'])->postJson('/admin/staff', [
            'name' => 'Ben', 'color' => '#0f766e', 'is_active' => '1', 'service_ids' => [$own->id],
        ])->assertOk()->assertJsonPath('message', '„Ben“ angelegt.');

        $ben = StaffMember::withoutGlobalScopes()->where('name', 'Ben')->firstOrFail();
        $this->assertSame($ben->id, $response->json('id'));
        $this->assertSame($setup['location']->id, $ben->location_id);
        $this->assertSame([$own->id], $ben->services()->withoutGlobalScopes()->pluck('services.id')->all());
        $this->assertSame(0, $foreign->staff()->withoutGlobalScopes()->count());
    }

    public function test_the_setup_assistant_payload_still_creates_a_person(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();

        // Der Einrichtungsassistent sendet formular-kodiert Name, Farbe und Schalter und liest die Kennung der Antwort.
        $response = $this->actingAs($setup['owner'])->post('/admin/staff', ['name' => 'Bea', 'color' => '#0d9488', 'is_active' => '1'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message', '„Bea“ angelegt.');
        $bea = StaffMember::withoutGlobalScopes()->where('name', 'Bea')->firstOrFail();
        $this->assertSame($bea->id, $response->json('id'));
        $this->assertTrue($bea->is_active);
        $this->assertSame('#0d9488', $bea->color);

        // Ohne Skript kommt die Bestätigung auf der Seite an.
        $this->actingAs($setup['owner'])->from('/admin/staff')->post('/admin/staff', ['name' => 'Cem', 'is_active' => '1'])
            ->assertRedirect('/admin/staff')
            ->assertSessionHas('success', '„Cem“ angelegt.');
        $this->assertTrue(StaffMember::withoutGlobalScopes()->where('name', 'Cem')->firstOrFail()->is_active);
    }

    public function test_a_rejected_entry_names_the_cause_and_the_next_step(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();
        $valid = ['name' => 'Ben', 'is_active' => '1'];
        $send = fn (array $payload) => $this->actingAs($setup['owner'])->putJson("/admin/staff/{$setup['member']->id}", $payload + $valid);

        $send(['name' => ''])->assertStatus(422)->assertJsonPath('errors.name.0', 'Bitte einen Namen für die Person eintragen.');
        $send(['name' => str_repeat('a', 121)])->assertJsonPath('errors.name.0', 'Der Name darf höchstens 120 Zeichen lang sein. Bitte kürze ihn.');
        $send(['bio' => str_repeat('a', 501)])->assertJsonPath('errors.bio.0', 'Die Kurzvorstellung darf höchstens 500 Zeichen lang sein. Bitte kürze sie.');
        $send(['bio' => ['x']])->assertJsonPath('errors.bio.0', 'Bitte die Kurzvorstellung als Text eintragen.');
        $send(['color' => 'rot'])->assertJsonPath('errors.color.0', 'Die Farbe ist ungültig. Bitte wähle sie über die Farbwahl oder schreibe sie als #rrggbb, etwa #aa3300.');
        $send(['is_active' => 'vielleicht'])->assertJsonPath('errors.is_active.0', 'Bitte „Buchbar“ ein- oder ausschalten.');
        $send(['service_ids' => 'alle'])->assertJsonPath('errors.service_ids.0', 'Die Auswahl der Leistungen ist ungültig. Bitte die Seite neu laden und die Leistungen neu wählen.');

        $this->assertSame('Anna', $setup['member']->fresh()->name);
    }

    public function test_the_switch_is_required_when_creating_and_when_editing(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();

        // Die Seite und der Einrichtungsassistent senden den Schalter immer mit; fehlt er, wird nicht still auf „buchbar“ gesetzt.
        $this->actingAs($setup['owner'])->postJson('/admin/staff', ['name' => 'Ben'])
            ->assertStatus(422)
            ->assertJsonPath('errors.is_active.0', 'Bitte „Buchbar“ ein- oder ausschalten.');
        $this->assertSame(1, StaffMember::withoutGlobalScopes()->count());
        $this->actingAs($setup['owner'])->postJson('/admin/staff', ['name' => 'Ben', 'is_active' => '0'])->assertOk();
        $this->assertFalse(StaffMember::withoutGlobalScopes()->where('name', 'Ben')->firstOrFail()->is_active);

        $this->actingAs($setup['owner'])->putJson("/admin/staff/{$setup['member']->id}", ['name' => 'Anna'])
            ->assertStatus(422)
            ->assertJsonPath('errors.is_active.0', 'Bitte „Buchbar“ ein- oder ausschalten.');
        $this->actingAs($setup['owner'])->putJson("/admin/staff/{$setup['member']->id}", ['name' => 'Anna', 'is_active' => '0'])->assertOk();
        $this->assertFalse($setup['member']->fresh()->is_active);
        $this->actingAs($setup['owner'])->putJson("/admin/staff/{$setup['member']->id}", ['name' => 'Anna', 'is_active' => '1'])->assertOk();
        $this->assertTrue($setup['member']->fresh()->is_active);
    }

    public function test_limits_come_from_the_settings(): void
    {
        config([
            'swayy.salon.staff_name_max' => 15, 'swayy.salon.staff_bio_max' => 60,
            'swayy.salon.staff_hours_max' => 2, 'swayy.salon.absence_reason_max' => 20,
        ]);
        $setup = $this->salon();
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->postJson('/admin/staff', [
            'name' => str_repeat('a', 16), 'bio' => str_repeat('b', 61), 'is_active' => '1',
        ])->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Der Name darf höchstens 15 Zeichen lang sein. Bitte kürze ihn.')
            ->assertJsonPath('errors.bio.0', 'Die Kurzvorstellung darf höchstens 60 Zeichen lang sein. Bitte kürze sie.');
        $this->actingAs($setup['owner'])->postJson('/admin/staff', [
            'name' => str_repeat('a', 15), 'bio' => str_repeat('b', 60), 'is_active' => '1',
        ])->assertOk();

        $row = fn (int $day) => ['weekday' => $day, 'starts_at' => '09:00', 'ends_at' => '12:00'];
        $this->actingAs($setup['owner'])->putJson("/admin/staff/{$setup['member']->id}/working-hours", ['hours' => [$row(0), $row(1), $row(2)]])
            ->assertStatus(422)
            ->assertJsonPath('errors.hours.0', 'Eine Person kann höchstens 2 Zeitfenster je Woche haben. Bitte Zeitfenster zusammenfassen oder entfernen.');
        $this->actingAs($setup['owner'])->putJson("/admin/staff/{$setup['member']->id}/working-hours", ['hours' => [$row(0), $row(1)]])->assertOk();

        $this->actingAs($setup['owner'])->postJson("/admin/staff/{$setup['member']->id}/absences", [
            'starts_on' => $this->in(30), 'ends_on' => $this->in(31), 'reason' => str_repeat('c', 21),
        ])->assertStatus(422)->assertJsonPath('errors.reason.0', 'Der Grund darf höchstens 20 Zeichen lang sein. Bitte kürze ihn.');

        // Die Felder der Seite tragen dieselben Grenzen.
        $html = (string) $this->actingAs($setup['owner'])->get('/admin/staff')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="name"[^>]*maxlength="15"/', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="bio"[^>]*maxlength="60"/', $html);
        $this->assertMatchesRegularExpression('/name="reason"[^>]*maxlength="20"/', $html);
        $this->assertStringContainsString('data-rows data-rows-max="2"', $html);
    }

    public function test_limits_end_at_the_columns_and_are_documented(): void
    {
        // staff_members.name ist varchar(255), bio text, staff_absences.reason varchar(120).
        [$config, $warnings] = $this->swayyConfig([
            'SWAYY_STAFF_NAME_MAX' => '4000', 'SWAYY_STAFF_BIO_MAX' => '90000', 'SWAYY_STAFF_HOURS_MAX' => '9000', 'SWAYY_ABSENCE_REASON_MAX' => '500',
            'SWAYY_ABSENCE_WINDOW_YEARS' => '5000',
        ]);
        $this->assertSame(255, $config['salon']['staff_name_max']);
        $this->assertSame(5000, $config['salon']['staff_bio_max']);
        $this->assertSame(200, $config['salon']['staff_hours_max']);
        $this->assertSame(120, $config['salon']['absence_reason_max']);
        $this->assertSame(100, $config['salon']['absence_window_years']);
        foreach (['SWAYY_STAFF_NAME_MAX', 'SWAYY_STAFF_BIO_MAX', 'SWAYY_STAFF_HOURS_MAX', 'SWAYY_ABSENCE_REASON_MAX', 'SWAYY_ABSENCE_WINDOW_YEARS'] as $variable) {
            $this->assertStringContainsString($variable, $warnings);
        }

        [$config] = $this->swayyConfig([]);
        $this->assertSame([120, 500, 28, 120, 10], [
            $config['salon']['staff_name_max'], $config['salon']['staff_bio_max'], $config['salon']['staff_hours_max'],
            $config['salon']['absence_reason_max'], $config['salon']['absence_window_years'],
        ]);

        $example = (string) file_get_contents(base_path('.env.example'));
        foreach (['SWAYY_STAFF_NAME_MAX', 'SWAYY_STAFF_BIO_MAX', 'SWAYY_STAFF_HOURS_MAX', 'SWAYY_ABSENCE_REASON_MAX', 'SWAYY_ABSENCE_WINDOW_YEARS'] as $variable) {
            $this->assertStringContainsString("# {$variable}=", $example);
        }
    }

    public function test_the_largest_allowed_values_fit_their_columns(): void
    {
        config([
            'swayy.salon.staff_name_max' => 255, 'swayy.salon.staff_bio_max' => 5000,
            'swayy.salon.staff_hours_max' => 200, 'swayy.salon.absence_reason_max' => 120,
        ]);
        $setup = $this->salon();
        $this->clearTenantContext();

        $name = str_repeat('ä', 255);
        $this->actingAs($setup['owner'])->postJson('/admin/staff', [
            'name' => $name, 'bio' => str_repeat('b', 5000), 'color' => '#ffffff', 'is_active' => '1',
        ])->assertOk();
        $person = StaffMember::withoutGlobalScopes()->where('name', $name)->firstOrFail();
        $this->assertSame(5000, mb_strlen((string) $person->bio));
        $this->actingAs($setup['owner'])->postJson('/admin/staff', ['name' => $name.'x', 'is_active' => '1'])->assertStatus(422);

        // 200 Zeitfenster: 7 Tage zu je 29 Fenstern von 29 Minuten, jedes für sich ohne Überschneidung.
        $hours = [];
        foreach (range(0, 199) as $i) {
            $from = ($i % 29) * 30;
            $hours[] = ['weekday' => intdiv($i, 29), 'starts_at' => sprintf('%02d:%02d', intdiv($from, 60), $from % 60), 'ends_at' => sprintf('%02d:%02d', intdiv($from + 29, 60), ($from + 29) % 60)];
        }
        $this->actingAs($setup['owner'])->putJson("/admin/staff/{$person->id}/working-hours", ['hours' => $hours])->assertOk();
        $this->assertSame(200, StaffWorkingHour::withoutGlobalScopes()->where('staff_member_id', $person->id)->count());

        $reason = str_repeat('ü', 120);
        $this->actingAs($setup['owner'])->postJson("/admin/staff/{$person->id}/absences", [
            'starts_on' => $this->in(30), 'ends_on' => $this->in(31), 'reason' => $reason,
        ])->assertOk();
        $this->assertSame($reason, StaffAbsence::withoutGlobalScopes()->firstOrFail()->reason);
        $this->actingAs($setup['owner'])->postJson("/admin/staff/{$person->id}/absences", [
            'starts_on' => $this->in(30), 'ends_on' => $this->in(31), 'reason' => $reason.'x',
        ])->assertStatus(422);
    }

    public function test_editing_keeps_paused_services_who_the_drawer_does_not_list(): void
    {
        $setup = $this->salon();
        $paused = $this->service($setup, 'Pause', ['is_active' => false]);
        $first = $this->service($setup, 'Schnitt');
        $second = $this->service($setup, 'Färben');
        $setup['member']->services()->attach([$first->id, $paused->id]);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($setup['owner'])->get('/admin/staff')->assertOk()->getContent();
        $this->assertStringNotContainsString('Pause', $this->drawers($html));
        $this->assertStringContainsString('Schnitt', $this->mainContent($html));

        // Die Auswahl der Seite zeigt nur aktive Leistungen; was pausiert, bleibt der Person zugeordnet.
        $this->actingAs($setup['owner'])->putJson("/admin/staff/{$setup['member']->id}", [
            'name' => 'Anna', 'is_active' => '1', 'service_ids' => [$second->id],
        ])->assertOk()->assertJsonPath('message', '„Anna“ gespeichert.');
        $this->assertEqualsCanonicalizing([$second->id, $paused->id], $setup['member']->services()->pluck('services.id')->all());

        // Was aktiv ist und abgewählt wird, fällt weg.
        $this->actingAs($setup['owner'])->putJson("/admin/staff/{$setup['member']->id}", ['name' => 'Anna', 'is_active' => '1'])->assertOk();
        $this->assertSame([$paused->id], $setup['member']->services()->pluck('services.id')->all());
    }

    public function test_the_selection_of_services_has_a_size_limit_and_no_duplicates(): void
    {
        $setup = $this->salon();
        $own = $this->service($setup);
        $this->service($setup, 'Färben');
        $this->clearTenantContext();
        $invalid = 'Die Auswahl der Leistungen ist ungültig. Bitte die Seite neu laden und die Leistungen neu wählen.';

        // Mehr Einträge, als der Standort Leistungen hat, kommen nie von der Seite und gehen nicht in die Abfrage.
        $this->actingAs($setup['owner'])->postJson('/admin/staff', ['name' => 'Ben', 'is_active' => '1', 'service_ids' => range(1, 500)])
            ->assertStatus(422)
            ->assertJsonPath('errors.service_ids.0', 'Mindestens eine gewählte Leistung gehört nicht zu diesem Standort oder wurde entfernt. Bitte die Seite neu laden und die Leistungen neu wählen.');
        $this->actingAs($setup['owner'])->postJson('/admin/staff', ['name' => 'Ben', 'is_active' => '1', 'service_ids' => [$own->id, $own->id]])
            ->assertStatus(422)->assertJsonPath('errors.service_ids.0', $invalid);
        foreach ([['abc'], [[1]], [1.5]] as $unusable) {
            $this->actingAs($setup['owner'])->postJson('/admin/staff', ['name' => 'Ben', 'is_active' => '1', 'service_ids' => $unusable])
                ->assertStatus(422)->assertJsonPath('errors.service_ids.0', $invalid);
        }
        $this->assertSame(1, StaffMember::withoutGlobalScopes()->count());
    }

    // ── Mandanten und Standorte ──────────────────────────────────────────

    public function test_people_of_other_tenants_and_other_locations_answer_404(): void
    {
        $setup = $this->salon();
        $foreign = $this->createTenantSetup();
        $foreignPerson = $this->person($foreign, 'Fremd');
        $foreignAbsence = StaffAbsence::create([
            'tenant_id' => $foreign['tenant']->id, 'staff_member_id' => $foreignPerson->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2),
        ]);
        $otherLocation = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $elsewhere = $this->person(['tenant' => $setup['tenant'], 'location' => $otherLocation], 'Woanders');
        $elsewhereAbsence = StaffAbsence::create([
            'tenant_id' => $setup['tenant']->id, 'staff_member_id' => $elsewhere->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2),
        ]);
        $this->clearTenantContext();
        $own = $this->service($setup);
        $hours = ['hours' => [['weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '17:00']]];

        foreach ([$foreignPerson, $elsewhere] as $person) {
            $this->actingAs($setup['owner'])->putJson("/admin/staff/{$person->id}", ['name' => 'X', 'is_active' => '1', 'service_ids' => [$own->id]])->assertNotFound();
            $this->actingAs($setup['owner'])->putJson("/admin/staff/{$person->id}/working-hours", $hours)->assertNotFound();
            $this->actingAs($setup['owner'])->postJson("/admin/staff/{$person->id}/absences", ['starts_on' => $this->in(30), 'ends_on' => $this->in(31)])->assertNotFound();
            $this->actingAs($setup['owner'])->deleteJson("/admin/staff/{$person->id}")->assertNotFound();
        }
        foreach ([$foreignAbsence, $elsewhereAbsence] as $absence) {
            $this->actingAs($setup['owner'])->deleteJson("/admin/staff/absences/{$absence->id}")->assertNotFound();
        }
        $this->actingAs($setup['owner'])->putJson('/admin/staff/999999', ['name' => 'X', 'is_active' => '1'])->assertNotFound();

        foreach ([$foreignPerson, $elsewhere] as $person) {
            $fresh = StaffMember::withoutGlobalScopes()->find($person->id);
            $this->assertNull($fresh->deleted_at);
            $this->assertNotSame('X', $fresh->name);
            $this->assertSame(0, StaffWorkingHour::withoutGlobalScopes()->where('staff_member_id', $person->id)->count());
            $this->assertSame(0, $fresh->services()->withoutGlobalScopes()->count());
        }
        $this->assertSame(2, StaffAbsence::withoutGlobalScopes()->count());
        $this->assertSame(0, AuditLog::where('action', 'like', 'staff_member.%')->count());

        // Die Seite führt nur die Personen des gewählten Standorts.
        $this->actingAs($setup['owner'])->get('/admin/staff')->assertOk()->assertDontSee('Fremd')->assertDontSee('Woanders');
    }

    // ── Löschen ──────────────────────────────────────────────────────────

    public function test_a_person_with_upcoming_appointments_stays(): void
    {
        $setup = $this->salon();
        $this->appointment($setup, $setup['member']);
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->deleteJson("/admin/staff/{$setup['member']->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.member.0', '„Anna“ hat noch einen kommenden oder laufenden Termin. Bitte den Termin zuerst einer anderen Person zuweisen, stornieren oder bis nach dem Termin warten. Um nur neue Buchungen zu stoppen, entfernst du den Haken bei „Buchbar“.');
        $this->assertNotNull($setup['member']->fresh());
        $this->assertSame(0, AuditLog::where('action', 'staff_member.deleted')->count());

        // Ohne Skript kommt die Ablehnung auf der Seite an.
        $this->actingAs($setup['owner'])->from('/admin/staff')->delete("/admin/staff/{$setup['member']->id}")
            ->assertRedirect('/admin/staff')
            ->assertSessionHasErrors('member');
    }

    public function test_the_delete_check_counts_every_kind_of_appointment_once(): void
    {
        $setup = $this->salon();
        $other = $this->person($setup, 'Ben');
        // Kein Hindernis: vorbei, abgesagt, nicht erschienen, andere Person, gelöschter Termin.
        $this->appointment($setup, $setup['member'], ReservationStatus::Confirmed, -2);
        $this->appointment($setup, $setup['member'], ReservationStatus::CancelledByGuest);
        $this->appointment($setup, $setup['member'], ReservationStatus::NoShow);
        $this->appointment($setup, $other);
        $this->appointment($setup, $setup['member'], ReservationStatus::Confirmed, 3)->delete();
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->deleteJson("/admin/staff/{$setup['member']->id}")
            ->assertOk()
            ->assertJsonPath('message', '„Anna“ gelöscht.');
        $this->assertNull(StaffMember::find($setup['member']->id));
        $this->assertNotNull(StaffMember::withTrashed()->find($setup['member']->id)->deleted_at);
        $this->assertSame(1, AuditLog::where('action', 'staff_member.deleted')->count());

        // Zwei Termine in verschiedenen Zuständen.
        $setup2 = $this->salon();
        $this->appointment($setup2, $setup2['member'], ReservationStatus::Confirmed, 1);
        $this->appointment($setup2, $setup2['member'], ReservationStatus::PendingConfirmation, 2);
        $this->clearTenantContext();

        $this->actingAs($setup2['owner'])->deleteJson("/admin/staff/{$setup2['member']->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.member.0', '„Anna“ hat noch 2 kommende oder laufende Termine. Bitte die Termine zuerst anderen Personen zuweisen, stornieren oder bis nach den Terminen warten. Um nur neue Buchungen zu stoppen, entfernst du den Haken bei „Buchbar“.');
    }

    public function test_a_running_appointment_keeps_the_person(): void
    {
        $setup = $this->salon();
        $now = CarbonImmutable::now($setup['location']->timezone);
        Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 1,
            'reservation_date' => $now->toDateString(), 'start_at' => $now->subMinutes(30)->utc(), 'end_at' => $now->addMinutes(30)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Seated, 'source' => 'manual',
            'guest_name_snapshot' => 'Testgast', 'staff_member_id' => $setup['member']->id,
        ]);
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->deleteJson("/admin/staff/{$setup['member']->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.member.0', fn (string $message) => str_contains($message, 'hat noch einen kommenden oder laufenden Termin'));
        $this->assertNull($setup['member']->fresh()->deleted_at);

        // Ist der Termin vorbei, steht dem Löschen nichts im Weg.
        Reservation::withoutGlobalScopes()->update(['end_at' => $now->subMinute()->utc()]);
        $this->actingAs($setup['owner'])->deleteJson("/admin/staff/{$setup['member']->id}")->assertOk();
        $this->assertNull(StaffMember::find($setup['member']->id));
    }

    public function test_a_second_delete_of_the_same_person_logs_nothing_more(): void
    {
        $setup = $this->salon();
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $this->actingAs($setup['owner']);
        $request = Request::create('/', 'DELETE', server: ['HTTP_ACCEPT' => 'application/json']);
        $request->setUserResolver(fn () => $setup['owner']);
        $controller = app(StaffMemberController::class);

        // Beide Aufrufe halten dasselbe Modell, wie zwei Anfragen, die die Person vor dem Löschen geladen haben.
        foreach (range(1, 2) as $call) {
            $response = $controller->destroy($request, $setup['member']);
            $this->assertSame(200, $response->getStatusCode(), "Aufruf {$call}");
            $this->assertSame('„Anna“ gelöscht.', $response->getData(true)['message']);
        }

        $this->assertSame(1, AuditLog::where('action', 'staff_member.deleted')->count());
    }

    public function test_deleting_locks_the_row_of_the_person_before_the_check_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Zeilensperren gibt es nur unter PostgreSQL (SQLite sperrt die ganze Datei).');
        }

        $setup = $this->salon();
        $this->clearTenantContext();

        DB::enableQueryLog();
        $this->actingAs($setup['owner'])->deleteJson("/admin/staff/{$setup['member']->id}")->assertOk();

        // Die Sperre (FOR UPDATE) hält gleichzeitige Buchungen der Person an, bis die Prüfung auf kommende Termine und das
        // Löschen abgeschlossen sind; sie steht vor der Prüfung.
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $sql) => strtolower($sql))->values();
        $lock = $queries->search(fn (string $sql) => str_contains($sql, 'from "staff_members"') && str_contains($sql, 'for update'));
        $check = $queries->search(fn (string $sql) => str_contains($sql, 'from "reservations"') && str_contains($sql, 'count(*)'));
        $delete = $queries->search(fn (string $sql) => str_contains($sql, 'update "staff_members" set "deleted_at"'));
        $audit = $queries->search(fn (string $sql) => str_starts_with($sql, 'insert into "audit_logs"'));
        $this->assertNotFalse($lock);
        $this->assertNotFalse($check);
        $this->assertNotFalse($delete);
        $this->assertNotFalse($audit);
        $this->assertTrue($lock < $check && $check < $delete, 'Reihenfolge: Sperre, Prüfung, Löschen');
        $this->assertTrue($delete < $audit, 'Das Protokoll folgt nach dem Löschen.');
    }

    // ── Arbeitszeiten ────────────────────────────────────────────────────

    public function test_working_hours_name_the_row_with_a_reversed_window(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();

        $response = $this->actingAs($setup['owner'])->putJson("/admin/staff/{$setup['member']->id}/working-hours", [
            'hours' => [
                0 => ['weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '17:00'],
                'n1' => ['weekday' => 1, 'starts_at' => '18:00', 'ends_at' => '09:00'],
                'n2' => ['weekday' => 2, 'starts_at' => '10:00', 'ends_at' => '10:00'],
            ],
        ])->assertStatus(422);

        $errors = $response->json('errors');
        $this->assertSame('Das Zeitfenster endet vor seinem Beginn. Bitte eine spätere Endzeit eintragen.', $errors['hours.n1.ends_at'][0] ?? null);
        $this->assertSame('Das Zeitfenster beginnt und endet zur selben Uhrzeit. Bitte eine spätere Endzeit eintragen.', $errors['hours.n2.ends_at'][0] ?? null);
        $this->assertArrayNotHasKey('hours.0.ends_at', $errors);
        // Nichts wurde gespeichert.
        $this->assertSame(0, StaffWorkingHour::withoutGlobalScopes()->where('staff_member_id', $setup['member']->id)->count());

        $this->actingAs($setup['owner'])->putJson("/admin/staff/{$setup['member']->id}/working-hours", [
            'hours' => [0 => ['weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '17:00']],
        ])->assertOk()->assertJsonPath('message', 'Arbeitszeiten von „Anna“ gespeichert.');
        $this->assertSame(1, StaffWorkingHour::withoutGlobalScopes()->where('staff_member_id', $setup['member']->id)->count());
    }

    public function test_overlapping_windows_name_the_row_and_the_next_step(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();
        $url = "/admin/staff/{$setup['member']->id}/working-hours";

        $response = $this->actingAs($setup['owner'])->putJson($url, [
            'hours' => [
                0 => ['weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '13:00'],
                1 => ['weekday' => 0, 'starts_at' => '12:30', 'ends_at' => '17:00'],
                // Anderer Wochentag, gleiche Zeiten: erlaubt.
                2 => ['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '13:00'],
                // Überschneidet sich mit beiden früheren Fenstern; die Meldung nennt das, das am längsten läuft.
                3 => ['weekday' => 0, 'starts_at' => '12:45', 'ends_at' => '13:15'],
            ],
        ])->assertStatus(422);

        $errors = $response->json('errors');
        $this->assertSame(
            'Dieses Zeitfenster überschneidet sich mit dem Zeitfenster am Montag von 09:00 bis 13:00 Uhr. Bitte Beginn oder Ende so ändern, dass sich beide nicht überschneiden.',
            $errors['hours.1.starts_at'][0] ?? null,
        );
        $this->assertStringContainsString('am Montag von 12:30 bis 17:00 Uhr', $errors['hours.3.starts_at'][0] ?? '');
        $this->assertArrayNotHasKey('hours.0.starts_at', $errors);
        $this->assertArrayNotHasKey('hours.2.starts_at', $errors);
        $this->assertSame(0, StaffWorkingHour::withoutGlobalScopes()->count());

        // Aneinander anschließende Fenster und Fenster an verschiedenen Tagen sind in Ordnung.
        $this->actingAs($setup['owner'])->putJson($url, [
            'hours' => [
                ['weekday' => 0, 'starts_at' => '13:00', 'ends_at' => '17:00'],
                ['weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '13:00'],
                ['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '13:00'],
            ],
        ])->assertOk();
        $this->assertSame(3, StaffWorkingHour::withoutGlobalScopes()->count());
    }

    public function test_saving_replaces_the_whole_list_and_a_row_key_may_repeat(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();
        $url = "/admin/staff/{$setup['member']->id}/working-hours";

        // Die Seite behält die Schlüssel neuer Zeilen (n1) nach dem Speichern; ein zweites Speichern sendet sie erneut.
        $this->actingAs($setup['owner'])->putJson($url, ['hours' => [
            0 => ['weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '12:00'],
            'n1' => ['weekday' => 2, 'starts_at' => '10:00', 'ends_at' => '15:00'],
        ]])->assertOk();
        $this->actingAs($setup['owner'])->putJson($url, ['hours' => [
            'n1' => ['weekday' => 2, 'starts_at' => '10:00', 'ends_at' => '16:00'],
            'n2' => ['weekday' => 4, 'starts_at' => '08:00', 'ends_at' => '12:00'],
        ]])->assertOk();

        $rows = StaffWorkingHour::withoutGlobalScopes()->where('staff_member_id', $setup['member']->id)->orderBy('weekday')->get();
        $this->assertSame([2, 4], $rows->pluck('weekday')->map(fn ($day) => (int) $day)->all());
        $this->assertSame(['10:00', '08:00'], $rows->map(fn ($row) => substr((string) $row->starts_at, 0, 5))->all());
        $this->assertSame('16:00', substr((string) $rows->first()->ends_at, 0, 5));
        $this->assertSame($setup['tenant']->id, $rows->first()->tenant_id);

        // Eine leere Liste nimmt alle Zeitfenster heraus: Es gelten wieder die Öffnungszeiten.
        $this->actingAs($setup['owner'])->putJson($url, [])->assertOk();
        $this->assertSame(0, StaffWorkingHour::withoutGlobalScopes()->count());
    }

    public function test_working_hours_reject_unusable_rows_with_readable_messages(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();
        $url = "/admin/staff/{$setup['member']->id}/working-hours";
        $send = fn (array $row) => $this->actingAs($setup['owner'])->putJson($url, ['hours' => ['n1' => $row + ['weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '17:00']]]);

        $errors = $send(['starts_at' => ''])->assertStatus(422)->json('errors');
        $this->assertSame('Bitte bei jedem Zeitfenster eintragen, wann es beginnt.', $errors['hours.n1.starts_at'][0]);
        $this->assertSame('Bitte den Beginn als Uhrzeit eintragen, etwa 09:00.', $send(['starts_at' => 'früh'])->json('errors')['hours.n1.starts_at'][0]);
        $this->assertSame('Bitte bei jedem Zeitfenster eintragen, wann es endet.', $send(['ends_at' => ''])->json('errors')['hours.n1.ends_at'][0]);
        $this->assertSame('Bitte das Ende als Uhrzeit eintragen, etwa 18:00.', $send(['ends_at' => '25:00'])->json('errors')['hours.n1.ends_at'][0]);
        $this->assertSame('Bitte bei jedem Zeitfenster einen Wochentag wählen.', $send(['weekday' => 7])->json('errors')['hours.n1.weekday'][0]);
        $this->assertSame('Bitte bei jedem Zeitfenster einen Wochentag wählen.', $send(['weekday' => 'Montag'])->json('errors')['hours.n1.weekday'][0]);
        $this->actingAs($setup['owner'])->putJson($url, ['hours' => 'immer'])->assertStatus(422)
            ->assertJsonPath('errors.hours.0', 'Die Arbeitszeiten sind ungültig. Bitte die Seite neu laden und die Zeitfenster neu eintragen.');
        $this->actingAs($setup['owner'])->putJson($url, ['hours' => ['n1' => 'immer']])->assertStatus(422);
        $this->assertSame(0, StaffWorkingHour::withoutGlobalScopes()->count());
    }

    public function test_a_rejected_working_hours_form_without_script_marks_the_rows(): void
    {
        $setup = $this->salon();
        StaffWorkingHour::create([
            'tenant_id' => $setup['tenant']->id, 'staff_member_id' => $setup['member']->id,
            'weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '17:00',
        ]);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($setup['owner'])->from('/admin/staff')->followingRedirects()
            ->put("/admin/staff/{$setup['member']->id}/working-hours", ['hours' => [0 => ['weekday' => 1, 'starts_at' => '18:00', 'ends_at' => '08:00']]])
            ->assertOk()->getContent();
        $drawer = $this->drawerOf($html, $setup['member']);

        // Die Eingabe bleibt stehen, und das Endfeld nennt seinen Fehler.
        $this->assertMatchesRegularExpression('/<option value="1" selected>Dienstag<\/option>/', $drawer);
        $this->assertMatchesRegularExpression('/name="hours\[0\]\[starts_at\]"[^>]*value="18:00"/', $drawer);
        $this->assertMatchesRegularExpression('/id="wh-'.$setup['member']->id.'-0-end"[^>]*aria-describedby="[^"]*wh-'.$setup['member']->id.'-0-end-error[^"]*"[^>]*aria-invalid="true"/s', $drawer);
        $this->assertStringContainsString('Das Zeitfenster endet vor seinem Beginn. Bitte eine spätere Endzeit eintragen.', $drawer);
    }

    // ── Abwesenheiten ────────────────────────────────────────────────────

    public function test_absences_are_added_and_removed_with_messages(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->postJson("/admin/staff/{$setup['member']->id}/absences", [
            'starts_on' => $this->in(30), 'ends_on' => $this->in(34), 'reason' => 'Urlaub',
        ])->assertOk()->assertJsonPath('message', 'Abwesenheit für „Anna“ eingetragen.');

        // Ohne Uhrzeit ganze Tage in der Zeitzone des Standorts, gespeichert in UTC.
        $absence = StaffAbsence::withoutGlobalScopes()->where('staff_member_id', $setup['member']->id)->firstOrFail();
        $timezone = $setup['location']->timezone;
        $this->assertSame($this->in(30).' 00:00:00', $absence->starts_at->copy()->setTimezone($timezone)->format('Y-m-d H:i:s'));
        $this->assertSame($this->in(34).' 23:59:59', $absence->ends_at->copy()->setTimezone($timezone)->format('Y-m-d H:i:s'));
        $this->assertSame('Urlaub', $absence->reason);

        $this->actingAs($setup['owner'])->deleteJson("/admin/staff/absences/{$absence->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Abwesenheit gelöscht.');
        $this->assertSame(0, StaffAbsence::withoutGlobalScopes()->count());
    }

    public function test_absences_with_times_are_read_in_the_location_time(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->postJson("/admin/staff/{$setup['member']->id}/absences", [
            'starts_on' => $this->in(30), 'starts_time' => '14:00', 'ends_on' => $this->in(30), 'ends_time' => '16:30',
        ])->assertOk();

        $absence = StaffAbsence::withoutGlobalScopes()->firstOrFail();
        $timezone = $setup['location']->timezone;
        $this->assertSame($this->in(30).' 14:00', $absence->starts_at->copy()->setTimezone($timezone)->format('Y-m-d H:i'));
        $this->assertSame($this->in(30).' 16:30', $absence->ends_at->copy()->setTimezone($timezone)->format('Y-m-d H:i'));
        $this->assertSame(150, (int) $absence->starts_at->diffInMinutes($absence->ends_at));
    }

    public function test_absence_dates_are_checked_with_readable_messages(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();
        $url = "/admin/staff/{$setup['member']->id}/absences";
        $send = fn (array $payload) => $this->actingAs($setup['owner'])->postJson($url, $payload)->assertStatus(422)->json('errors');
        $valid = ['starts_on' => $this->in(30), 'ends_on' => $this->in(34)];

        $this->assertSame('Bitte das Datum eintragen, an dem die Abwesenheit beginnt.', $send(['starts_on' => ''] + $valid)['starts_on'][0]);
        $this->assertSame('Bitte das Datum des Beginns über die Datumswahl eintragen.', $send(['starts_on' => 'morgen'] + $valid)['starts_on'][0]);
        $this->assertSame('Bitte das Datum eintragen, an dem die Abwesenheit endet.', $send(['ends_on' => ''] + $valid)['ends_on'][0]);
        $this->assertSame('Bitte das Datum des Endes über die Datumswahl eintragen.', $send(['ends_on' => '06.11.'] + $valid)['ends_on'][0]);
        $this->assertSame('Das Ende der Abwesenheit liegt vor ihrem Beginn. Bitte ein Enddatum am oder nach dem Beginn wählen.', $send(['ends_on' => $this->in(29)] + $valid)['ends_on'][0]);
        $this->assertSame('Bitte die Uhrzeit als Stunden und Minuten eintragen, etwa 09:00.', $send(['starts_time' => '9 Uhr'] + $valid)['starts_time'][0]);
        $this->assertSame('Bitte die Uhrzeit als Stunden und Minuten eintragen, etwa 09:00.', $send(['ends_time' => '24:30'] + $valid)['ends_time'][0]);
        $this->assertSame('Der Grund darf höchstens 120 Zeichen lang sein. Bitte kürze ihn.', $send(['reason' => str_repeat('x', 121)] + $valid)['reason'][0]);

        // Am selben Tag liegt das Ende nach dem Beginn.
        $same = ['starts_on' => $this->in(30), 'ends_on' => $this->in(30)];
        $message = 'Das Ende der Abwesenheit liegt vor dem Beginn. Bitte Datum oder Uhrzeit des Endes später wählen.';
        $this->assertSame($message, $send($same + ['starts_time' => '14:00', 'ends_time' => '09:00'])['ends_time'][0]);
        $this->assertSame($message, $send($same + ['starts_time' => '14:00', 'ends_time' => '14:00'])['ends_time'][0]);
        $this->assertSame($message, $send($same + ['ends_time' => '00:00'])['ends_time'][0]);
        $this->assertSame(0, StaffAbsence::withoutGlobalScopes()->count());

        // Ein Grund ist freiwillig, und ein Tag ohne Uhrzeiten dauert bis zum Tagesende.
        $this->actingAs($setup['owner'])->postJson($url, $same)->assertOk();
        $this->assertNull(StaffAbsence::withoutGlobalScopes()->firstOrFail()->reason);
    }

    public function test_absence_dates_stay_within_the_window_of_the_settings(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();
        $url = "/admin/staff/{$setup['member']->id}/absences";
        $today = CarbonImmutable::now($setup['location']->timezone)->startOfDay();
        $message = fn (int $years) => "Das Datum liegt mehr als {$years} Jahre von heute entfernt. Bitte das Jahr prüfen und ein Datum zwischen "
            .$today->subYears($years)->format('d.m.Y').' und '.$today->addYears($years)->format('d.m.Y').' wählen.';

        // Ein Tippfehler im Jahr (0026) und ein Jahr 0001, das die Zeitstempel-Spalte nach der Umrechnung nicht fasst, ergeben eine Meldung.
        foreach ([['0026-11-02', '0026-11-03'], ['0001-01-01', '0001-01-02']] as [$from, $to]) {
            $errors = $this->actingAs($setup['owner'])->postJson($url, ['starts_on' => $from, 'ends_on' => $to])->assertStatus(422)->json('errors');
            $this->assertSame($message(10), $errors['starts_on'][0]);
            $this->assertSame($message(10), $errors['ends_on'][0]);
        }
        $errors = $this->actingAs($setup['owner'])->postJson($url, ['starts_on' => $today->toDateString(), 'ends_on' => '2200-01-01'])->assertStatus(422)->json('errors');
        $this->assertSame(['ends_on'], array_keys($errors));
        $this->assertSame(0, StaffAbsence::withoutGlobalScopes()->count());

        // Die Grenze kommt aus der Einstellung, und der Tag der Grenze selbst gilt noch.
        config(['swayy.salon.absence_window_years' => 1]);
        $this->actingAs($setup['owner'])->postJson($url, ['starts_on' => $today->subYear()->toDateString(), 'ends_on' => $today->addYear()->toDateString()])->assertOk();
        $errors = $this->actingAs($setup['owner'])->postJson($url, ['starts_on' => $today->subYear()->subDay()->toDateString(), 'ends_on' => $today->addYear()->addDay()->toDateString()])
            ->assertStatus(422)->json('errors');
        $this->assertSame($message(1), $errors['starts_on'][0]);
        $this->assertSame($message(1), $errors['ends_on'][0]);
        $this->assertSame(1, StaffAbsence::withoutGlobalScopes()->count());
    }

    public function test_an_absence_over_booked_appointments_is_entered_and_leaves_them_untouched(): void
    {
        $setup = $this->salon();
        $appointment = $this->appointment($setup, $setup['member'], ReservationStatus::Confirmed, 1);
        $this->clearTenantContext();
        $day = CarbonImmutable::now($setup['location']->timezone)->addDay()->toDateString();

        // Die Abwesenheit sperrt neue Buchungen; vorhandene Termine bleiben unverändert, und die Antwort nennt sie.
        $this->actingAs($setup['owner'])->postJson("/admin/staff/{$setup['member']->id}/absences", ['starts_on' => $day, 'ends_on' => $day])
            ->assertOk()
            ->assertJsonPath('message', 'Abwesenheit für „Anna“ eingetragen. In dieser Zeit ist noch 1 Termin gebucht. Bitte weise ihn einer anderen Person zu oder sage ihn ab.');
        $this->assertSame(ReservationStatus::Confirmed, $appointment->fresh()->status);
        $this->assertSame($setup['member']->id, $appointment->fresh()->staff_member_id);
        $this->assertSame(1, StaffAbsence::withoutGlobalScopes()->count());
    }

    public function test_an_absence_names_how_many_active_appointments_it_covers(): void
    {
        $setup = $this->salon();
        $ben = $this->person($setup, 'Ben');
        $local = fn (int $days): string => CarbonImmutable::now($setup['location']->timezone)->addDays($days)->toDateString();
        $plain = 'Abwesenheit für „Anna“ eingetragen.';
        $url = "/admin/staff/{$setup['member']->id}/absences";
        $enter = fn (array $payload) => $this->actingAs($setup['owner'])->postJson($url, $payload)->assertOk()->json('message');

        // Kein Hindernis: abgesagt, nicht erschienen, gelöscht, eine andere Person, ein anderer Tag, ein bereits beendeter Termin.
        $this->appointment($setup, $setup['member'], ReservationStatus::CancelledByGuest, 1);
        $this->appointment($setup, $setup['member'], ReservationStatus::NoShow, 1);
        $this->appointment($setup, $setup['member'], ReservationStatus::Confirmed, 1)->delete();
        $this->appointment($setup, $ben, ReservationStatus::Confirmed, 1);
        $this->appointment($setup, $setup['member'], ReservationStatus::Confirmed, 2);
        $this->appointment($setup, $setup['member'], ReservationStatus::Confirmed, -3);
        $this->clearTenantContext();

        $this->assertSame($plain, $enter(['starts_on' => $local(1), 'ends_on' => $local(1)]));
        $this->assertSame($plain, $enter(['starts_on' => $local(-3), 'ends_on' => $local(-3)]));

        // Ein Termin zählt einmal, auch wenn die Abwesenheit nur einen Teil seiner Zeit deckt (der Termin liegt von 10:00 bis 11:00).
        $this->appointment($setup, $setup['member'], ReservationStatus::Confirmed, 1);
        $one = $plain.' In dieser Zeit ist noch 1 Termin gebucht. Bitte weise ihn einer anderen Person zu oder sage ihn ab.';
        $this->assertSame($one, $enter(['starts_on' => $local(1), 'ends_on' => $local(1)]));
        $this->assertSame($one, $enter(['starts_on' => $local(1), 'starts_time' => '10:30', 'ends_on' => $local(1), 'ends_time' => '10:45']));
        // Der Termin endet um 11:00; eine Abwesenheit ab 11:00 überschneidet ihn nicht.
        $this->assertSame($plain, $enter(['starts_on' => $local(1), 'starts_time' => '11:00', 'ends_on' => $local(1), 'ends_time' => '12:00']));

        // Mehrere Termine, verschiedene aktive Status.
        $this->appointment($setup, $setup['member'], ReservationStatus::PendingConfirmation, 1);
        $this->appointment($setup, $setup['member'], ReservationStatus::Seated, 2);
        $this->assertSame(
            $plain.' In dieser Zeit sind noch 2 Termine gebucht. Bitte weise sie anderen Personen zu oder sage sie ab.',
            $enter(['starts_on' => $local(1), 'ends_on' => $local(1)]),
        );
        $this->assertSame(
            $plain.' In dieser Zeit sind noch 4 Termine gebucht. Bitte weise sie anderen Personen zu oder sage sie ab.',
            $enter(['starts_on' => $local(1), 'ends_on' => $local(2)]),
        );
    }

    public function test_removing_an_absence_of_a_removed_person_answers_404(): void
    {
        $setup = $this->salon();
        $absence = StaffAbsence::create([
            'tenant_id' => $setup['tenant']->id, 'staff_member_id' => $setup['member']->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2),
        ]);
        $setup['member']->delete();
        $this->clearTenantContext();

        // Eine offene Seite kennt die Abwesenheit noch, die Person ist inzwischen gelöscht.
        $this->actingAs($setup['owner'])->deleteJson("/admin/staff/absences/{$absence->id}")->assertNotFound();
        $this->assertSame(1, StaffAbsence::withoutGlobalScopes()->count());
    }

    // ── Buchung einer entfernten Person ──────────────────────────────────

    /**
     * Setzt SalonAvailabilityService durch eine Fassung, die nach der Prüfung der Person $hook ausführt. Das ist der Moment,
     * nachdem der Controller die Person gelesen hat und bevor er die Buchung anlegt.
     */
    private function afterStaffCheck(Closure $hook): void
    {
        $this->app->bind(SalonAvailabilityService::class, fn ($app) => new class($app->make(TimeSlotService::class), $hook) extends SalonAvailabilityService
        {
            public function __construct(TimeSlotService $slots, private readonly Closure $hook)
            {
                parent::__construct($slots);
            }

            public function isStaffAvailableForServices(StaffMember $staff, Collection $services, CarbonImmutable $startUtc, Location $location): bool
            {
                $available = parent::isStaffAvailableForServices($staff, $services, $startUtc, $location);
                ($this->hook)();

                return $available;
            }

            public function firstAvailableStaffForServices(Collection $services, CarbonImmutable $startUtc, Location $location): ?StaffMember
            {
                $staff = parent::firstAvailableStaffForServices($services, $startUtc, $location);
                ($this->hook)();

                return $staff;
            }
        });
    }

    /**
     * Hook für afterStaffCheck: entfernt bei jedem Aufruf die nächste Person aus $queue und zählt in $ran mit. Die Fassung bleibt
     * für die ganze Testmethode gebunden (der Router hält den Controller), spätere Anfragen füllen $queue neu.
     *
     * @param  list<StaffMember>  $queue
     */
    private function removeNext(array &$queue, int &$ran): Closure
    {
        return function () use (&$queue, &$ran): void {
            $member = array_shift($queue);
            if ($member !== null) {
                $ran++;
                StaffMember::withoutGlobalScopes()->whereKey($member->id)->first()->delete();
            }
        };
    }

    /**
     * Salon mit einer Leistung und einer Person, die sie anbietet.
     *
     * @return array{0: array<string, mixed>, 1: Service, 2: string} Einrichtung, Leistung und ein Tag in der Zukunft
     */
    private function bookableSalon(): array
    {
        $setup = $this->salon();
        $service = $this->service($setup);
        $service->staff()->attach($setup['member']->id);
        $this->clearTenantContext();

        return [$setup, $service, CarbonImmutable::now($setup['location']->timezone)->addDays(2)->toDateString()];
    }

    public function test_the_public_booking_refuses_a_person_removed_after_it_was_read(): void
    {
        Mail::fake();
        [$setup, $service, $day] = $this->bookableSalon();
        $url = '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug;
        $guest = ['service_ids' => [$service->id], 'date' => $day, 'time' => '14:00', 'name' => 'Frau Kessler', 'email' => 'kessler@example.test', 'privacy_accepted' => '1'];
        $queue = [$setup['member']];
        $ran = 0;
        $this->afterStaffCheck($this->removeNext($queue, $ran));

        // Gewählte Person: Sie wird nach der Prüfung entfernt.
        $this->from($url)->post($url, $guest + ['staff_member_id' => $setup['member']->id])
            ->assertRedirect($url)
            ->assertSessionHasErrors(['staff_member_id' => self::REMOVED]);
        $this->assertSame(1, $ran);
        $this->assertSame(0, Reservation::withoutGlobalScopes()->count());

        // Wer es nicht wählt, bekommt die Person zugeteilt, die inzwischen entfernt wurde.
        $second = $this->person($setup, 'Ben');
        $service->staff()->attach($second->id);
        $queue = [$second];
        $this->from($url)->post($url, $guest)
            ->assertRedirect($url)
            ->assertSessionHasErrors(['staff_member_id' => self::REMOVED]);
        $this->assertSame(2, $ran);
        $this->assertSame(0, Reservation::withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('reservation_service')->count());
    }

    public function test_the_admin_booking_refuses_a_person_removed_after_it_was_read(): void
    {
        [$setup, $service, $day] = $this->bookableSalon();
        $payload = ['service_ids' => [$service->id], 'staff_member_id' => $setup['member']->id, 'date' => $day, 'time' => '14:00', 'party_size' => 1, 'name' => 'Bea'];
        $queue = [$setup['member']];
        $ran = 0;
        $this->afterStaffCheck($this->removeNext($queue, $ran));

        $this->actingAs($setup['owner'])->from('/admin/reservations/create')->post('/admin/reservations', $payload)
            ->assertRedirect('/admin/reservations/create')
            ->assertSessionHasErrors(['staff_member_id' => self::REMOVED]);
        $this->assertSame(1, $ran);
        $this->assertSame(0, Reservation::withoutGlobalScopes()->count());

        // Mit JSON antwortet die Verwaltung mit 422 und derselben Meldung.
        $second = $this->person($setup, 'Ben');
        $service->staff()->attach($second->id);
        $queue = [$second];
        $this->actingAs($setup['owner'])->postJson('/admin/reservations', ['staff_member_id' => $second->id] + $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.staff_member_id.0', self::REMOVED);
        $this->assertSame(2, $ran);
        $this->assertSame(0, Reservation::withoutGlobalScopes()->count());
    }

    public function test_the_admin_booking_names_the_next_step_for_a_person_who_is_not_available(): void
    {
        [$setup, $service, $day] = $this->bookableSalon();
        $timezone = $setup['location']->timezone;
        $from = CarbonImmutable::parse($day, $timezone)->startOfDay();
        StaffAbsence::create([
            'tenant_id' => $setup['tenant']->id, 'staff_member_id' => $setup['member']->id,
            'starts_at' => $from->utc(), 'ends_at' => $from->endOfDay()->utc(), 'reason' => 'Urlaub',
        ]);

        $this->actingAs($setup['owner'])->from('/admin/reservations/create')->post('/admin/reservations', [
            'service_ids' => [$service->id], 'staff_member_id' => $setup['member']->id, 'date' => $day, 'time' => '14:00', 'party_size' => 1, 'name' => 'Bea',
        ])
            ->assertRedirect('/admin/reservations/create')
            ->assertSessionHasErrors(['staff_member_id' => 'Diese Person ist zu dem Zeitpunkt nicht verfügbar. Bitte eine andere Person oder eine andere Zeit wählen.']);
        $this->assertSame(0, Reservation::withoutGlobalScopes()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function booking(StaffMember $member, Service $service, CarbonImmutable $start, bool $force = false): array
    {
        return [
            'party_size' => 1, 'start_local' => $start, 'duration_minutes' => 60, 'source' => 'manual', 'guest_name' => 'Bea',
            'service_id' => $service->id, 'service_ids' => [$service->id], 'staff_member_id' => $member->id,
            'skip_availability_check' => true, 'allow_staff_conflict' => $force,
        ];
    }

    public function test_a_booking_for_a_removed_person_is_refused_even_when_overbooking_is_allowed(): void
    {
        $setup = $this->salon();
        $service = $this->service($setup);
        $elsewhere = $this->person(['tenant' => $setup['tenant'], 'location' => Location::factory()->create(['tenant_id' => $setup['tenant']->id])], 'Woanders');
        $removed = $this->person($setup, 'Weg');
        $removed->delete();
        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(14, 0);

        // Das ausdrückliche Überbuchen schaltet nur die Prüfung der Zeiten ab; die Person muss es geben.
        foreach ([$removed, $elsewhere] as $unknown) {
            foreach ([false, true] as $force) {
                try {
                    app(ReservationLifecycleService::class)->create($setup['location'], $this->booking($unknown, $service, $start, $force));
                    $this->fail('Die Buchung ist mit einer Person durchgegangen, die es hier nicht gibt.');
                } catch (ValidationException $e) {
                    $this->assertSame(self::REMOVED, $e->errors()['staff_member_id'][0]);
                }
            }
        }
        $this->assertSame(0, Reservation::withoutGlobalScopes()->count());

        // Die eigene Person geht durch, mit und ohne Überbuchen.
        foreach ([false, true] as $force) {
            app(ReservationLifecycleService::class)->create($setup['location'], $this->booking($setup['member'], $service, $start->addHours($force ? 3 : 0), $force));
        }
        $this->assertSame(2, Reservation::withoutGlobalScopes()->where('staff_member_id', $setup['member']->id)->count());
    }

    public function test_a_booking_locks_the_person_in_a_shared_way_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Zeilensperren gibt es nur unter PostgreSQL (SQLite sperrt die ganze Datei).');
        }

        $setup = $this->salon();
        $service = $this->service($setup);
        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(14, 0);

        foreach ([false, true] as $force) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            app(ReservationLifecycleService::class)->create($setup['location'], $this->booking($setup['member'], $service, $start->addHours($force ? 3 : 0), $force));

            // FOR SHARE hält ein gleichzeitiges Löschen (FOR UPDATE) an, bis die Buchung festgeschrieben ist; Buchungen untereinander
            // warten nicht. Die Sperre steht vor dem Anlegen und vor den Leistungen: Tag, Person, Leistungen.
            $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $sql) => strtolower($sql))->values();
            $person = $queries->search(fn (string $sql) => str_contains($sql, 'from "staff_members"') && str_contains($sql, 'for share'));
            $services = $queries->search(fn (string $sql) => str_contains($sql, 'from "services"') && str_contains($sql, 'for share'));
            $insert = $queries->search(fn (string $sql) => str_starts_with($sql, 'insert into "reservations"'));
            $day = $queries->search(fn (string $sql) => str_contains($sql, 'pg_advisory_xact_lock'));
            $this->assertNotFalse($day, 'force='.(int) $force);
            $this->assertNotFalse($person, 'force='.(int) $force);
            $this->assertNotFalse($services);
            $this->assertNotFalse($insert);
            $this->assertTrue($day < $person && $person < $services && $services < $insert, 'Reihenfolge: Tag, Person, Leistungen, Anlegen');
        }
    }

    public function test_entering_an_absence_locks_the_person_before_the_insert_and_the_count_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Zeilensperren gibt es nur unter PostgreSQL (SQLite sperrt die ganze Datei).');
        }

        $setup = $this->salon();
        $this->appointment($setup, $setup['member'], ReservationStatus::Confirmed, 1);
        $this->clearTenantContext();
        $day = CarbonImmutable::now($setup['location']->timezone)->addDay()->toDateString();

        DB::enableQueryLog();
        $this->actingAs($setup['owner'])->postJson("/admin/staff/{$setup['member']->id}/absences", ['starts_on' => $day, 'ends_on' => $day])->assertOk();

        // Die Sperre (FOR UPDATE) hält gleichzeitige Buchungen der Person an (sie lesen sie mit FOR SHARE). Eintrag und Zählung
        // laufen unter ihr, das Protokoll folgt nach dem Festschreiben; eine Buchung, die schon läuft, steht in der Zählung.
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $sql) => strtolower($sql))->values();
        $lock = $queries->search(fn (string $sql) => str_contains($sql, 'from "staff_members"') && str_contains($sql, 'for update'));
        $insert = $queries->search(fn (string $sql) => str_starts_with($sql, 'insert into "staff_absences"'));
        $count = $queries->search(fn (string $sql) => str_contains($sql, 'from "reservations"') && str_contains($sql, 'count(*)'));
        $audit = $queries->search(fn (string $sql) => str_starts_with($sql, 'insert into "audit_logs"'));
        $this->assertNotFalse($lock);
        $this->assertNotFalse($insert);
        $this->assertNotFalse($count);
        $this->assertNotFalse($audit);
        $this->assertTrue($lock < $insert && $insert < $count && $count < $audit, 'Reihenfolge: Sperre, Eintrag, Zählung, Protokoll');
    }

    public function test_assigning_a_person_later_refuses_a_removed_one(): void
    {
        [$setup] = $this->bookableSalon();
        $reservation = $this->appointment($setup, $setup['member'], ReservationStatus::Confirmed, 3);
        $reservation->update(['staff_member_id' => null]);
        $ben = $this->person($setup, 'Ben');
        $gone = $this->person($setup, 'Weg');
        $gone->delete();
        $this->clearTenantContext();
        $url = "/admin/reservations/{$reservation->id}/staff";

        $this->actingAs($setup['owner'])->post($url, ['staff_member_id' => $gone->id])
            ->assertSessionHasErrors(['staff_member_id' => 'Diese Person gibt es hier nicht. Bitte die Seite neu laden und eine andere Person wählen.']);
        $this->assertNull($reservation->fresh()->staff_member_id);

        // Eine Person, die zu dem Zeitpunkt abwesend ist, lehnt die Zuweisung mit dem nächsten Schritt ab.
        $away = $this->person($setup, 'Urlaub');
        StaffAbsence::create(['tenant_id' => $setup['tenant']->id, 'staff_member_id' => $away->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(10)]);
        $this->actingAs($setup['owner'])->post($url, ['staff_member_id' => $away->id])
            ->assertSessionHasErrors(['staff_member_id' => 'Diese Person ist zu dem Zeitpunkt nicht verfügbar. Bitte eine andere Person oder eine andere Zeit wählen.']);
        $this->assertNull($reservation->fresh()->staff_member_id);

        $this->actingAs($setup['owner'])->post($url, ['staff_member_id' => $ben->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Ben zugewiesen.');
        $this->assertSame($ben->id, $reservation->fresh()->staff_member_id);
        $this->assertSame(1, AuditLog::where('action', 'reservation.staff_assigned')->count());

        // Der Termin gehört jetzt zu Ben: Er bleibt, solange der Termin ansteht.
        $this->actingAs($setup['owner'])->deleteJson("/admin/staff/{$ben->id}")->assertStatus(422);
    }

    public function test_assigning_a_person_later_reads_it_under_a_shared_lock_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Zeilensperren gibt es nur unter PostgreSQL (SQLite sperrt die ganze Datei).');
        }

        [$setup, $service] = $this->bookableSalon();
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $reservation = $this->appointment($setup, $setup['member'], ReservationStatus::Confirmed, 3);
        $reservation->update(['staff_member_id' => null]);
        $ben = $this->person($setup, 'Ben');
        $this->clearTenantContext();

        DB::enableQueryLog();
        $this->actingAs($setup['owner'])->post("/admin/reservations/{$reservation->id}/staff", ['staff_member_id' => $ben->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($ben->id, $reservation->fresh()->staff_member_id);

        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $sql) => strtolower($sql))->values();
        $lock = $queries->search(fn (string $sql) => str_contains($sql, 'from "staff_members"') && str_contains($sql, 'for share'));
        $update = $queries->search(fn (string $sql) => str_starts_with($sql, 'update "reservations" set "staff_member_id"'));
        $this->assertNotFalse($lock);
        $this->assertNotFalse($update);
        $this->assertTrue($lock < $update, 'Die Sperre steht vor dem Zuweisen.');
    }

    // ── Protokoll ────────────────────────────────────────────────────────

    public function test_changes_are_logged_with_the_stored_values(): void
    {
        $setup = $this->salon();
        $service = $this->service($setup);
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->postJson('/admin/staff', [
            'name' => 'Ben', 'bio' => 'Färbt gern', 'color' => '#aa3300', 'is_active' => '1', 'service_ids' => [$service->id],
        ])->assertOk();
        $ben = StaffMember::withoutGlobalScopes()->where('name', 'Ben')->firstOrFail();

        $created = AuditLog::where('action', 'staff_member.created')->firstOrFail();
        $this->assertSame('Ben', $created->new_values['name']);
        $this->assertSame('#aa3300', $created->new_values['color']);
        $this->assertSame([$service->id], $created->new_values['service_ids']);
        $this->assertTrue($created->new_values['is_active']);

        $this->actingAs($setup['owner'])->putJson("/admin/staff/{$ben->id}", ['name' => 'Ben B.', 'is_active' => '0'])
            ->assertOk()->assertJsonPath('message', '„Ben B.“ gespeichert.');
        $updated = AuditLog::where('action', 'staff_member.updated')->firstOrFail();
        $this->assertSame('Ben', $updated->old_values['name']);
        $this->assertSame([$service->id], $updated->old_values['service_ids']);
        $this->assertSame('Ben B.', $updated->new_values['name']);
        $this->assertFalse($updated->new_values['is_active']);
        $this->assertSame([], $updated->new_values['service_ids']);
        $labels = collect($updated->fieldChanges())->pluck('label', 'field');
        $this->assertSame('Leistungen', $labels['service_ids']);
        $this->assertSame('Kurzvorstellung', $labels['bio']);

        $this->actingAs($setup['owner'])->putJson("/admin/staff/{$ben->id}/working-hours", ['hours' => [
            ['weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '15:00'], ['weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '12:00'],
        ]])->assertOk();
        $this->actingAs($setup['owner'])->putJson("/admin/staff/{$ben->id}/working-hours", ['hours' => [
            ['weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '12:00'],
        ]])->assertOk();
        $hours = AuditLog::where('action', 'staff_member.working_hours_updated')->orderBy('id')->get();
        $this->assertSame([], $hours[0]->old_values['hours']);
        $this->assertSame(['Montag 09:00–12:00', 'Dienstag 10:00–15:00'], $hours[0]->new_values['hours']);
        $this->assertSame(['Montag 09:00–12:00', 'Dienstag 10:00–15:00'], $hours[1]->old_values['hours']);
        $this->assertSame(['Montag 09:00–12:00'], $hours[1]->new_values['hours']);
        $this->assertSame('Arbeitszeiten', collect($hours[1]->fieldChanges())->pluck('label', 'field')['hours']);

        $this->actingAs($setup['owner'])->postJson("/admin/staff/{$ben->id}/absences", [
            'starts_on' => $this->in(30), 'starts_time' => '08:00', 'ends_on' => $this->in(31), 'reason' => 'Urlaub',
        ])->assertOk();
        $absence = StaffAbsence::withoutGlobalScopes()->firstOrFail();
        $logged = AuditLog::where('action', 'staff_member.absence_created')->firstOrFail();
        // Die Zeiten stehen so im Protokoll, wie sie der Betrieb eingetragen hat (Ortszeit), mit lesbaren Feldnamen.
        $this->assertSame(['starts_at' => $this->in(30).' 08:00', 'ends_at' => $this->in(31).' 23:59', 'reason' => 'Urlaub'], $logged->new_values);
        $this->assertSame('Beginn', collect($logged->fieldChanges())->pluck('label', 'field')['starts_at']);
        $this->actingAs($setup['owner'])->deleteJson("/admin/staff/absences/{$absence->id}")->assertOk();
        $this->assertSame(['starts_at' => $this->in(30).' 08:00', 'ends_at' => $this->in(31).' 23:59', 'reason' => 'Urlaub'], AuditLog::where('action', 'staff_member.absence_deleted')->firstOrFail()->old_values);
    }

    // ── Ohne Skript ──────────────────────────────────────────────────────

    public function test_a_rejected_form_without_script_keeps_the_input_and_marks_the_fields(): void
    {
        $setup = $this->salon();
        $service = $this->service($setup);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($setup['owner'])->from('/admin/staff')->followingRedirects()->post('/admin/staff', [
            'name' => 'Ben', 'bio' => str_repeat('b', 501), 'color' => '#112233', 'is_active' => '0', 'service_ids' => [$service->id],
        ])->assertOk()->getContent();
        $new = $this->newDrawer($html);

        $this->assertMatchesRegularExpression('/type="color" name="color" value="#112233"/', $new);
        $this->assertMatchesRegularExpression('/name="service_ids\[\]" value="'.$service->id.'" checked/', $new);
        $this->assertMatchesRegularExpression('/id="person-neu-active" type="checkbox" name="is_active" value="1"(?! checked)/', $new);
        // Die Kurzvorstellung ist als fehlerhaft markiert und verweist auf den Text.
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="bio"[^>]*aria-describedby="[^"]*person-neu-bio-error[^"]*"[^>]*aria-invalid="true"/s', $new);
        $this->assertStringContainsString('id="person-neu-bio-error"', $new);
        $this->assertStringContainsString('Die Kurzvorstellung darf höchstens 500 Zeichen lang sein.', $new);
    }

    public function test_hand_built_fields_point_to_their_errors(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();
        $valid = ['name' => 'Ben', 'is_active' => '1'];
        $post = fn (array $payload): string => $this->newDrawer((string) $this->actingAs($setup['owner'])->from('/admin/staff')
            ->followingRedirects()->post('/admin/staff', $valid + $payload)->assertOk()->getContent());

        $new = $post(['color' => 'rot']);
        $this->assertMatchesRegularExpression('/type="color"[^>]*aria-describedby="[^"]*person-neu-color-error[^"]*"[^>]*aria-invalid="true"/s', $new);

        // Eine unbekannte Leistung lehnt der Server nach der Prüfung der Felder ab; der Text hängt an der Gruppe der Leistungen.
        $new = $post(['service_ids' => [999999]]);
        $this->assertMatchesRegularExpression('/<fieldset id="person-neu-leistungen"[^>]*aria-describedby="[^"]*person-neu-leistungen-error/s', $new);
        $this->assertStringContainsString('id="person-neu-leistungen-error"', $new);
    }

    /**
     * Lädt config/swayy.php mit den gesetzten Umgebungsvariablen.
     *
     * @param  array<string, string>  $env
     * @return array{0: array<string, mixed>, 1: string} Konfiguration und Warnungen im Fehlerprotokoll
     */
    private function swayyConfig(array $env): array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'swayy-config');
        $previous = ini_set('error_log', $file);
        foreach ($env as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
        }

        try {
            $config = require config_path('swayy.php');
        } finally {
            foreach (array_keys($env) as $name) {
                unset($_SERVER[$name], $_ENV[$name]);
            }
            ini_set('error_log', $previous === false ? '' : $previous);
            $warnings = (string) file_get_contents($file);
            @unlink($file);
        }

        return [$config, $warnings];
    }
}
