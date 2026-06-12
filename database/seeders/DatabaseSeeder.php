<?php

namespace Database\Seeders;

use App\Enums\ReservationStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Location;
use App\Models\OpeningHour;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Models\SpecialOpeningHour;
use App\Models\TableCombination;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanSeeder::class);
        $plans = Plan::all()->keyBy('key');

        // SaaS super admin (local development only — see README)
        User::factory()->create([
            'name' => 'SaaS Admin',
            'email' => 'admin@gastrobook.test',
            'password' => Hash::make('password'),
            'saas_role' => 'super_admin',
        ]);

        // Demo tenant
        $tenant = Tenant::create([
            'name' => 'Demo Restaurantgruppe',
            'slug' => 'demo',
            'plan_id' => $plans['professional']->id,
            'status' => 'active',
        ]);

        $owner = User::factory()->create([
            'name' => 'Olivia Owner',
            'email' => 'owner@demo.test',
            'password' => Hash::make('password'),
            'current_tenant_id' => $tenant->id,
        ]);
        TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'role' => 'tenant_owner', 'all_locations' => true]);

        $host = User::factory()->create([
            'name' => 'Hans Host',
            'email' => 'host@demo.test',
            'password' => Hash::make('password'),
            'current_tenant_id' => $tenant->id,
        ]);
        TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $host->id, 'role' => 'host', 'all_locations' => true]);

        // Locations
        $sonne = $this->seedLocation($tenant, 'Restaurant Sonne', 'sonne');
        $this->seedLocation($tenant, 'Trattoria Luna', 'luna', small: true);

        // Tags
        foreach ([
            ['VIP', '#f59e0b'], ['Stammgast', '#10b981'], ['Vegan', '#84cc16'],
            ['Glutenfrei', '#06b6d4'], ['Terrasse bevorzugt', '#8b5cf6'], ['No-Show-Risiko', '#ef4444'],
        ] as [$name, $color]) {
            Tag::create(['tenant_id' => $tenant->id, 'name' => $name, 'color' => $color, 'scope' => 'guest']);
        }

        // Guests
        $guests = collect([
            ['Max', 'Mustermann', 'max@example.test', '+49 170 1111111', true, 12, 0],
            ['Erika', 'Beispiel', 'erika@example.test', '+49 170 2222222', false, 5, 1],
            ['Jonas', 'Schmidt', 'jonas@example.test', '+49 170 3333333', false, 0, 0],
            ['Aylin', 'Kaya', 'aylin@example.test', '+49 170 4444444', false, 3, 0],
            ['Pierre', 'Dubois', 'pierre@example.test', '+49 170 5555555', false, 1, 2],
        ])->map(fn ($g) => Guest::create([
            'tenant_id' => $tenant->id,
            'first_name' => $g[0], 'last_name' => $g[1], 'email' => $g[2], 'phone' => $g[3],
            'is_vip' => $g[4], 'visit_count' => $g[5], 'no_show_count' => $g[6],
            'source' => 'online_booking',
        ]));

        $guests[0]->tags()->attach(Tag::where('tenant_id', $tenant->id)->where('name', 'VIP')->first());
        $guests[1]->tags()->attach(Tag::where('tenant_id', $tenant->id)->where('name', 'Stammgast')->first());

        // Reservations for today and tomorrow at Restaurant Sonne
        $tz = $sonne->timezone;
        $tables = RestaurantTable::withoutGlobalScope('tenant')->where('location_id', $sonne->id)->get();
        $today = CarbonImmutable::now($tz)->startOfDay();

        $slots = [
            [$today->setTime(12, 0), 2, $guests[0], ReservationStatus::Completed],
            [$today->setTime(18, 0), 4, $guests[1], ReservationStatus::Confirmed],
            [$today->setTime(19, 0), 2, $guests[2], ReservationStatus::Confirmed],
            [$today->setTime(19, 30), 6, $guests[3], ReservationStatus::Requested],
            [$today->addDay()->setTime(19, 0), 2, $guests[4], ReservationStatus::Confirmed],
        ];

        foreach ($slots as $i => [$start, $party, $guest, $status]) {
            $reservation = Reservation::create([
                'tenant_id' => $tenant->id,
                'location_id' => $sonne->id,
                'guest_id' => $guest->id,
                'party_size' => $party,
                'reservation_date' => $start->toDateString(),
                'start_at' => $start->utc(),
                'end_at' => $start->utc()->addMinutes(120),
                'timezone' => $tz,
                'status' => $status,
                'source' => $i % 2 === 0 ? 'online' : 'phone',
                'guest_name_snapshot' => $guest->fullName(),
                'guest_email_snapshot' => $guest->email,
                'guest_phone_snapshot' => $guest->phone,
                'confirmed_at' => $status === ReservationStatus::Confirmed ? now() : null,
            ]);
            $table = $tables->first(fn ($t) => $t->max_capacity >= $party && $t->min_capacity <= $party);
            if ($table) {
                $reservation->tables()->sync([$table->id]);
            }
        }

        // Waitlist entry
        WaitlistEntry::create([
            'tenant_id' => $tenant->id,
            'location_id' => $sonne->id,
            'guest_name' => 'Sabine Warteliste',
            'guest_email' => 'sabine@example.test',
            'party_size' => 4,
            'desired_date' => $today->toDateString(),
            'desired_start_at' => $today->setTime(20, 0)->utc(),
            'status' => 'waiting',
            'source' => 'online',
            'expires_at' => $today->endOfDay()->utc(),
        ]);

        // Special hours + event example
        SpecialOpeningHour::create([
            'tenant_id' => $tenant->id,
            'location_id' => $sonne->id,
            'date' => $today->addDays(14)->toDateString(),
            'closed' => true,
            'label' => 'Betriebsferien',
        ]);

        Event::create([
            'tenant_id' => $tenant->id,
            'location_id' => $sonne->id,
            'title' => 'Weinprobe mit 5-Gänge-Menü',
            'slug' => 'weinprobe',
            'description' => 'Begleitete Weinprobe mit saisonalem Menü.',
            'starts_at' => $today->addDays(21)->setTime(19, 0)->utc(),
            'ends_at' => $today->addDays(21)->setTime(23, 0)->utc(),
            'capacity' => 30,
            'price_minor' => 8900,
            'currency' => 'EUR',
            'is_public' => true,
        ]);
    }

    private function seedLocation(Tenant $tenant, string $name, string $slug, bool $small = false): Location
    {
        $location = Location::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'slug' => $slug,
            'timezone' => 'Europe/Berlin',
            'phone' => '+49 30 1234567',
            'email' => $slug.'@demo.test',
            'city' => 'Berlin',
            'public_intro' => 'Wir freuen uns auf Ihre Reservierung!',
        ]);
        $location->settings()->create(['tenant_id' => $tenant->id]);

        // Opening hours: Tue-Sun lunch + dinner (0 = Monday closed)
        foreach (range(1, 6) as $weekday) {
            OpeningHour::create([
                'tenant_id' => $tenant->id, 'location_id' => $location->id,
                'weekday' => $weekday, 'opens_at' => '12:00', 'closes_at' => '14:30', 'service_name' => 'Mittag',
            ]);
            OpeningHour::create([
                'tenant_id' => $tenant->id, 'location_id' => $location->id,
                'weekday' => $weekday, 'opens_at' => '17:30', 'closes_at' => '23:00', 'service_name' => 'Abend',
            ]);
        }

        $roomDefs = $small
            ? [['Innenraum', false]]
            : [['Innenraum', false], ['Terrasse', true], ['Bar', false], ['Wintergarten', false]];

        foreach ($roomDefs as $ri => [$roomName, $outdoor]) {
            $room = Room::create([
                'tenant_id' => $tenant->id, 'location_id' => $location->id,
                'name' => $roomName, 'is_outdoor' => $outdoor, 'sort_order' => $ri,
            ]);

            $tableCount = $roomName === 'Innenraum' ? 8 : 4;
            $created = [];
            for ($i = 1; $i <= $tableCount; $i++) {
                $capacity = match (true) {
                    $i <= 2 => [1, 2],
                    $i <= 5 => [2, 4],
                    $i <= 7 => [4, 6],
                    default => [6, 8],
                };
                $created[] = RestaurantTable::create([
                    'tenant_id' => $tenant->id,
                    'location_id' => $location->id,
                    'room_id' => $room->id,
                    'name' => strtoupper(substr($roomName, 0, 1)).$i,
                    'min_capacity' => $capacity[0],
                    'max_capacity' => $capacity[1],
                    'preferred_capacity' => $capacity[1] - 1,
                    'outdoor' => $outdoor,
                    'accessible' => $i === 1,
                    'pos_x' => 60 + (($i - 1) % 4) * 200,
                    'pos_y' => 60 + intdiv($i - 1, 4) * 200,
                    'width' => 110,
                    'height' => 110,
                    'shape' => $i % 3 === 0 ? 'round' : 'rect',
                ]);
            }

            // One combination per indoor room: last two tables joined
            if (! $outdoor && count($created) >= 2) {
                $a = $created[count($created) - 2];
                $b = $created[count($created) - 1];
                $combo = TableCombination::create([
                    'tenant_id' => $tenant->id,
                    'location_id' => $location->id,
                    'name' => $a->name.'+'.$b->name,
                    'min_capacity' => $a->max_capacity + 1,
                    'max_capacity' => $a->max_capacity + $b->max_capacity,
                ]);
                $combo->tables()->sync([$a->id, $b->id]);
            }
        }

        return $location;
    }
}
