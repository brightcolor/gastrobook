<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BlackoutPeriod;
use App\Models\DepositRule;
use App\Models\Event;
use App\Models\EventBooking;
use App\Models\FeedbackRequest;
use App\Models\FeedbackResponse;
use App\Models\FloorZone;
use App\Models\Guest;
use App\Models\GuestConsent;
use App\Models\GuestNote;
use App\Models\Location;
use App\Models\MarketingCampaign;
use App\Models\MarketingSend;
use App\Models\NotificationTemplate;
use App\Models\OpeningHour;
use App\Models\PaymentIntent;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\ReservationNote;
use App\Models\ReservationStatusHistory;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Models\Service;
use App\Models\SpecialOpeningHour;
use App\Models\StaffAbsence;
use App\Models\StaffMember;
use App\Models\StaffWorkingHour;
use App\Models\TableBlock;
use App\Models\TableCombination;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Counterpart to AccountExportService: reads an export file back in so a
 * business can actually move — or restore a backup.
 *
 * The import is additive (nothing existing is deleted) and runs in a single
 * transaction: either everything lands or nothing does. Internal ids from the
 * source system are never reused; every record gets a fresh id and references
 * are remapped. Access tokens and booking codes are regenerated, because the
 * export deliberately contains none.
 */
class AccountImportService
{
    /** Columns that must never be taken from the file. */
    private const DROP = [
        'id', 'tenant_id', 'created_at', 'updated_at', 'deleted_at',
        'created_by', 'user_id', 'approved_by', 'requested_by',
        'code', 'manage_token', 'token', 'secret',
    ];

    /** @var array<string, array<int|string, int>> old id → new id, per entity */
    private array $map = [];

    /**
     * Validate the payload and report what an import would create.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int>
     */
    public function preview(array $data): array
    {
        $this->assertValid($data);

        $counts = [];
        foreach ($this->importableSections() as $section) {
            $rows = $data[$section] ?? [];
            if (is_array($rows) && $rows !== []) {
                $counts[$section] = count($rows);
            }
        }

        return $counts;
    }

    /**
     * Import an export payload into the given tenant.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int> imported record counts
     */
    public function import(Tenant $tenant, array $data): array
    {
        $this->assertValid($data);
        $this->map = [];

        return DB::transaction(function () use ($tenant, $data) {
            $done = [];

            $done['locations'] = $this->locations($tenant, $data);
            $done['rooms'] = $this->rooms($tenant, $data);
            $done['tables'] = $this->tables($tenant, $data);
            $done['table_combinations'] = $this->combinations($tenant, $data);
            $done['floor_zones'] = $this->simple($tenant, $data, 'floor_zones', FloorZone::class, ['location_id' => 'locations', 'room_id' => 'rooms']);
            $done['opening_hours'] = $this->simple($tenant, $data, 'opening_hours', OpeningHour::class, ['location_id' => 'locations']);
            $done['special_opening_hours'] = $this->simple($tenant, $data, 'special_opening_hours', SpecialOpeningHour::class, ['location_id' => 'locations']);
            $done['blackout_periods'] = $this->simple($tenant, $data, 'blackout_periods', BlackoutPeriod::class, ['location_id' => 'locations', 'room_id' => 'rooms']);
            $done['tags'] = $this->simple($tenant, $data, 'tags', Tag::class, [], ['name', 'scope']);
            $done['table_blocks'] = $this->simple($tenant, $data, 'table_blocks', TableBlock::class, ['location_id' => 'locations', 'restaurant_table_id' => 'tables']);
            $done['notification_templates'] = $this->simple($tenant, $data, 'notification_templates', NotificationTemplate::class, ['location_id' => 'locations'], ['location_id', 'key', 'locale']);
            $done['services'] = $this->simple($tenant, $data, 'services', Service::class, ['location_id' => 'locations']);
            $done['staff_members'] = $this->simple($tenant, $data, 'staff_members', StaffMember::class, ['location_id' => 'locations']);
            $done['staff_working_hours'] = $this->simple($tenant, $data, 'staff_working_hours', StaffWorkingHour::class, ['staff_member_id' => 'staff_members']);
            $done['staff_absences'] = $this->simple($tenant, $data, 'staff_absences', StaffAbsence::class, ['staff_member_id' => 'staff_members']);

            // Reihenfolge = Abhaengigkeiten. Alles, worauf spaeter gezeigt wird,
            // muss vorher importiert sein – sonst steht in der Fremdschluessel-
            // spalte die ID der QUELLinstallation: entweder eine Verletzung, die
            // die ganze Transaktion abbricht, oder eine stille Falschverknuepfung.
            $done['events'] = $this->simple($tenant, $data, 'events', Event::class, ['location_id' => 'locations']);
            $done['deposit_rules'] = $this->simple($tenant, $data, 'deposit_rules', DepositRule::class, [
                'location_id' => 'locations',
                'room_id' => 'rooms',
                'event_id' => 'events',
                'service_id' => 'services',
            ]);

            $done['guests'] = $this->simple($tenant, $data, 'guests', Guest::class);
            $done['guest_notes'] = $this->simple($tenant, $data, 'guest_notes', GuestNote::class, ['guest_id' => 'guests']);
            $done['guest_consents'] = $this->simple($tenant, $data, 'guest_consents', GuestConsent::class, ['guest_id' => 'guests']);

            $done['reservations'] = $this->reservations($tenant, $data);
            $done['reservation_notes'] = $this->simple($tenant, $data, 'reservation_notes', ReservationNote::class, ['reservation_id' => 'reservations']);
            $done['reservation_status_history'] = $this->simple($tenant, $data, 'reservation_status_history', ReservationStatusHistory::class, ['reservation_id' => 'reservations']);

            $done['event_bookings'] = $this->simple($tenant, $data, 'event_bookings', EventBooking::class, [
                'event_id' => 'events',
                'reservation_id' => 'reservations',
                'guest_id' => 'guests',
            ]);
            $done['waitlist_entries'] = $this->simple($tenant, $data, 'waitlist_entries', WaitlistEntry::class, [
                'location_id' => 'locations',
                'guest_id' => 'guests',
                'reservation_id' => 'reservations',
            ]);
            $done['payments'] = $this->simple($tenant, $data, 'payments', PaymentIntent::class, ['reservation_id' => 'reservations', 'event_booking_id' => 'event_bookings']);
            $done['refunds'] = $this->refunds($tenant, $data);
            $done['feedback_requests'] = $this->simple($tenant, $data, 'feedback_requests', FeedbackRequest::class, ['location_id' => 'locations', 'reservation_id' => 'reservations']);
            $done['feedback_responses'] = $this->simple($tenant, $data, 'feedback_responses', FeedbackResponse::class, ['location_id' => 'locations', 'feedback_request_id' => 'feedback_requests']);

            $done['marketing_campaigns'] = $this->simple($tenant, $data, 'marketing_campaigns', MarketingCampaign::class, ['location_id' => 'locations']);
            // Die Versandhistorie wandert mit, sonst bekommt jeder Gast nach dem
            // Umzug seinen Geburtstagsgruss oder die Win-back-Mail ein zweites Mal.
            $done['marketing_sends'] = $this->simple($tenant, $data, 'marketing_sends', MarketingSend::class, [
                'marketing_campaign_id' => 'marketing_campaigns',
                'guest_id' => 'guests',
            ]);

            return array_filter($done);
        });
    }

    /** @return array<int, string> */
    private function importableSections(): array
    {
        return [
            'locations', 'rooms', 'tables', 'table_combinations', 'floor_zones',
            'opening_hours', 'special_opening_hours', 'blackout_periods',
            'tags', 'table_blocks', 'deposit_rules', 'notification_templates',
            'services', 'staff_members', 'staff_working_hours', 'staff_absences',
            'guests', 'guest_notes', 'guest_consents',
            'reservations', 'reservation_notes', 'reservation_status_history',
            'events', 'event_bookings', 'waitlist_entries',
            'payments', 'refunds', 'feedback_requests', 'feedback_responses',
            'marketing_campaigns', 'marketing_sends',
        ];
    }

    /** @param array<string, mixed> $data */
    private function assertValid(array $data): void
    {
        if (($data['format'] ?? null) !== 'swayy-account-export') {
            throw new RuntimeException('Diese Datei ist kein Swayy-Account-Export.');
        }
        if ((int) ($data['format_version'] ?? 0) !== 1) {
            throw new RuntimeException('Diese Export-Datei stammt aus einer nicht unterstützten Version.');
        }
        if (! isset($data['locations']) || ! is_array($data['locations']) || $data['locations'] === []) {
            throw new RuntimeException('Die Datei enthält keine Standorte – ohne Standort kann nicht importiert werden.');
        }
    }

    /** Locations need unique slugs within the installation. */
    private function locations(Tenant $tenant, array $data): int
    {
        $count = 0;
        foreach ($data['locations'] as $row) {
            $attrs = $this->attrs($row);
            $attrs['slug'] = $this->uniqueLocationSlug($attrs['slug'] ?? Str::slug($attrs['name'] ?? 'standort'));

            $location = new Location($attrs);
            $location->tenant_id = $tenant->id;
            $location->save();

            $this->remember('locations', $row['id'] ?? null, $location->id);

            // Settings belong to exactly one location.
            $settings = collect($data['location_settings'] ?? [])
                ->firstWhere('location_id', $row['id'] ?? null);
            $location->settings()->create(
                array_merge($this->attrs($settings ?? []), ['tenant_id' => $tenant->id])
            );

            $count++;
        }

        return $count;
    }

    private function uniqueLocationSlug(string $base): string
    {
        $slug = Str::slug($base) ?: 'standort';
        $candidate = $slug;
        $i = 2;
        while (Location::withoutGlobalScopes()->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$i++;
        }

        return $candidate;
    }

    private function rooms(Tenant $tenant, array $data): int
    {
        return $this->simple($tenant, $data, 'rooms', Room::class, ['location_id' => 'locations']);
    }

    /** Tables additionally keep a name→id lookup so reservations can be linked. */
    private function tables(Tenant $tenant, array $data): int
    {
        $count = 0;
        foreach ($data['tables'] ?? [] as $row) {
            $attrs = $this->attrs($row);
            $attrs['location_id'] = $this->mapped('locations', $row['location_id'] ?? null);
            $attrs['room_id'] = $this->mapped('rooms', $row['room_id'] ?? null);
            if ($attrs['location_id'] === null) {
                continue;
            }

            $table = new RestaurantTable($attrs);
            $table->tenant_id = $tenant->id;
            $table->save();

            $this->remember('tables', $row['id'] ?? null, $table->id);
            // name lookup (per location) for reservation assignment
            $this->map['table_names'][$attrs['location_id'].'|'.$table->name] = $table->id;
            $count++;
        }

        return $count;
    }

    private function combinations(Tenant $tenant, array $data): int
    {
        $count = 0;
        foreach ($data['table_combinations'] ?? [] as $row) {
            $locationId = $this->mapped('locations', $row['location_id'] ?? null);
            if ($locationId === null) {
                continue;
            }

            $attrs = $this->attrs($row);
            $attrs['location_id'] = $locationId;
            $combo = new TableCombination($attrs);
            $combo->tenant_id = $tenant->id;
            $combo->save();

            $ids = [];
            foreach ($row['table_names'] ?? [] as $name) {
                $id = $this->map['table_names'][$locationId.'|'.$name] ?? null;
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
            if ($ids !== []) {
                $combo->tables()->sync($ids);
            }
            $count++;
        }

        return $count;
    }

    /** Reservations relink tables and tags by name. */
    private function reservations(Tenant $tenant, array $data): int
    {
        $tagIds = Tag::withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('id', 'name');
        $count = 0;

        foreach ($data['reservations'] ?? [] as $row) {
            $locationId = $this->mapped('locations', $row['location_id'] ?? null);
            if ($locationId === null) {
                continue;
            }

            $attrs = $this->attrs($row);
            $attrs['location_id'] = $locationId;
            $attrs['guest_id'] = $this->mapped('guests', $row['guest_id'] ?? null);
            $attrs['event_id'] = $this->mapped('events', $row['event_id'] ?? null);
            $attrs['service_id'] = $this->mapped('services', $row['service_id'] ?? null);
            $attrs['staff_member_id'] = $this->mapped('staff_members', $row['staff_member_id'] ?? null);
            $attrs['deposit_rule_id'] = $this->mapped('deposit_rules', $row['deposit_rule_id'] ?? null);

            $reservation = new Reservation($attrs);
            $reservation->tenant_id = $tenant->id;
            // code + manage_token are regenerated by the model's creating hook.
            $reservation->save();

            $this->remember('reservations', $row['id'] ?? null, $reservation->id);

            $tableIds = [];
            foreach ($row['table_names'] ?? [] as $name) {
                $id = $this->map['table_names'][$locationId.'|'.$name] ?? null;
                if ($id !== null) {
                    $tableIds[] = $id;
                }
            }
            if ($tableIds !== []) {
                $reservation->tables()->sync($tableIds);
            }

            $names = array_filter($row['tag_names'] ?? [], fn ($n) => $tagIds->has($n));
            if ($names !== []) {
                $reservation->tags()->sync($tagIds->only($names)->values()->all());
            }

            $count++;
        }

        return $count;
    }

    /**
     * Refunds come along as history, but never as pending work.
     *
     * The export deliberately carries no provider references, and the target
     * installation has its own payment accounts. An imported refund that is
     * still open would be picked up by the scheduler and tried against a
     * provider that knows nothing about it — so open refunds are closed with a
     * clear reason and have to be handled by hand in the old system.
     */
    private function refunds(Tenant $tenant, array $data): int
    {
        $open = ['pending', 'approved', 'processing'];
        $count = 0;

        foreach ($data['refunds'] ?? [] as $row) {
            $attrs = $this->attrs($row);
            $attrs['reservation_id'] = $this->mapped('reservations', $row['reservation_id'] ?? null);
            $attrs['event_booking_id'] = $this->mapped('event_bookings', $row['event_booking_id'] ?? null);
            $attrs['payment_intent_id'] = $this->mapped('payments', $row['payment_intent_id'] ?? null);

            if (in_array($attrs['status'] ?? null, $open, true)) {
                $attrs['status'] = 'failed';
                $attrs['scheduled_for'] = null;
                $attrs['error'] = 'Beim Umzug übernommen – im alten System abschließen.';
            }

            $refund = new Refund($attrs);
            $refund->tenant_id = $tenant->id;
            $refund->save();

            $this->remember('refunds', $row['id'] ?? null, $refund->id);
            $count++;
        }

        return $count;
    }

    /**
     * Generic importer for a section.
     *
     * @param  class-string  $modelClass
     * @param  array<string, string>  $relations  column => entity key in the id map
     * @param  array<int, string>  $unique  columns forming a natural key within the tenant.
     *                                      A match reuses the existing record instead of
     *                                      creating a duplicate — the target business may
     *                                      already have tags or templates of its own.
     */
    private function simple(Tenant $tenant, array $data, string $section, string $modelClass, array $relations = [], array $unique = []): int
    {
        $count = 0;
        foreach ($data[$section] ?? [] as $row) {
            $attrs = $this->attrs($row);

            $skip = false;
            foreach ($relations as $column => $entity) {
                $new = $this->mapped($entity, $row[$column] ?? null);
                // A required parent that cannot be resolved means the row is orphaned.
                if ($new === null && ! empty($row[$column])) {
                    $skip = true;
                    break;
                }
                $attrs[$column] = $new;
            }
            if ($skip) {
                continue;
            }

            if ($unique !== []) {
                $existing = $modelClass::withoutGlobalScopes()->where('tenant_id', $tenant->id);
                foreach ($unique as $column) {
                    $existing->where($column, $attrs[$column] ?? null);
                }
                $found = $existing->first();
                if ($found !== null) {
                    $this->remember($section, $row['id'] ?? null, $found->getKey());

                    continue;
                }
            }

            /** @var Model $model */
            $model = new $modelClass($attrs);
            $model->tenant_id = $tenant->id;
            $model->save();

            $this->remember($section, $row['id'] ?? null, $model->getKey());
            $count++;
        }

        return $count;
    }

    /** Strip everything that must not be carried over. */
    private function attrs(array $row): array
    {
        foreach (self::DROP as $key) {
            unset($row[$key]);
        }
        // export-only helper fields
        unset($row['table_names'], $row['tag_names']);

        return $row;
    }

    private function remember(string $entity, mixed $oldId, int $newId): void
    {
        if ($oldId !== null) {
            $this->map[$entity][(int) $oldId] = $newId;
        }
    }

    private function mapped(string $entity, mixed $oldId): ?int
    {
        if ($oldId === null) {
            return null;
        }

        return $this->map[$entity][(int) $oldId] ?? null;
    }
}
