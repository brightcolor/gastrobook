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
use App\Models\LocationSettings;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Full account export so a business can take all of its data along when
 * moving away — GDPR data portability and a practical migration path.
 *
 * Deliberately excluded: anything that is a credential or an access token
 * (integration credentials, webhook secrets, magic-link/manage tokens,
 * password hashes) and payment-provider references. Those belong to the old
 * installation and would be a security risk in a downloaded file.
 */
class AccountExportService
{
    /** Field names that must never leave the system, whatever table they are on. */
    private const SECRET_FIELDS = [
        'password', 'remember_token', 'secret', 'token', 'manage_token',
        'credentials_encrypted', 'provider_intent_id', 'provider_refund_id',
        'stripe_customer_id', 'gocardless_customer_id', 'gocardless_mandate_id',
        'gocardless_subscription_id', 'api_token', 'access_token',
    ];

    /**
     * Build the full export payload for a tenant.
     *
     * @return array<string, mixed>
     */
    public function export(Tenant $tenant): array
    {
        $locationIds = Location::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->pluck('id')
            ->all();

        return [
            'format' => 'swayy-account-export',
            'format_version' => 1,
            'exported_at' => now()->toIso8601String(),
            'source' => config('app.url'),
            'app_version' => config('version.number'),
            'notice' => 'Zugangsdaten, Integrations-Schlüssel und Zugriffs-Token sind '
                .'aus Sicherheitsgründen NICHT enthalten und müssen im neuen System neu hinterlegt werden.',

            'tenant' => $this->clean($tenant),
            'team' => $this->team($tenant),

            'locations' => $this->rows(Location::withoutGlobalScopes()->where('tenant_id', $tenant->id)),
            'location_settings' => $this->rows(LocationSettings::withoutGlobalScopes()->where('tenant_id', $tenant->id)),

            'rooms' => $this->rows(Room::withoutGlobalScopes()->whereIn('location_id', $locationIds)),
            'tables' => $this->rows(RestaurantTable::withoutGlobalScopes()->withTrashed()->whereIn('location_id', $locationIds)),
            'table_combinations' => $this->combinations($locationIds),
            'floor_zones' => $this->rows(FloorZone::withoutGlobalScopes()->whereIn('location_id', $locationIds)),

            'opening_hours' => $this->rows(OpeningHour::withoutGlobalScopes()->whereIn('location_id', $locationIds)),
            'special_opening_hours' => $this->rows(SpecialOpeningHour::withoutGlobalScopes()->whereIn('location_id', $locationIds)),
            'blackout_periods' => $this->rows(BlackoutPeriod::withoutGlobalScopes()->whereIn('location_id', $locationIds)),

            'guests' => $this->rows(Guest::withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenant->id)),
            'guest_notes' => $this->rows(GuestNote::withoutGlobalScopes()->where('tenant_id', $tenant->id)),
            'guest_consents' => $this->rows(GuestConsent::withoutGlobalScopes()->where('tenant_id', $tenant->id)),

            'reservations' => $this->reservations($tenant),
            'reservation_notes' => $this->rows(ReservationNote::withoutGlobalScopes()->where('tenant_id', $tenant->id)),
            'reservation_status_history' => $this->rows(ReservationStatusHistory::withoutGlobalScopes()->where('tenant_id', $tenant->id)),

            'events' => $this->rows(Event::withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenant->id)),
            'event_bookings' => $this->rows(EventBooking::withoutGlobalScopes()->where('tenant_id', $tenant->id)),

            'waitlist_entries' => $this->rows(WaitlistEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)),

            'services' => $this->rows(Service::withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenant->id)),
            'staff_members' => $this->rows(StaffMember::withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenant->id)),
            'staff_working_hours' => $this->rows(StaffWorkingHour::withoutGlobalScopes()->where('tenant_id', $tenant->id)),
            'staff_absences' => $this->rows(StaffAbsence::withoutGlobalScopes()->where('tenant_id', $tenant->id)),

            'tags' => $this->rows(Tag::withoutGlobalScopes()->where('tenant_id', $tenant->id)),
            'table_blocks' => $this->rows(TableBlock::withoutGlobalScopes()->where('tenant_id', $tenant->id)),
            'deposit_rules' => $this->rows(DepositRule::withoutGlobalScopes()->where('tenant_id', $tenant->id)),
            'marketing_campaigns' => $this->rows(MarketingCampaign::withoutGlobalScopes()->where('tenant_id', $tenant->id)),
            // Ohne die Versandhistorie faengt jede Kampagne nach dem Umzug von
            // vorn an – der Gast bekommt dieselbe Mail ein zweites Mal.
            'marketing_sends' => $this->rows(MarketingSend::withoutGlobalScopes()->where('tenant_id', $tenant->id)),
            'notification_templates' => $this->rows(NotificationTemplate::withoutGlobalScopes()->where('tenant_id', $tenant->id)),

            'feedback_requests' => $this->rows(FeedbackRequest::withoutGlobalScopes()->where('tenant_id', $tenant->id)),
            'feedback_responses' => $this->rows(FeedbackResponse::withoutGlobalScopes()->where('tenant_id', $tenant->id)),

            // Payments: amounts and status for the books — provider references
            // are stripped, they are worthless on another installation anyway.
            'payments' => $this->rows(PaymentIntent::withoutGlobalScopes()->where('tenant_id', $tenant->id)),
            'refunds' => $this->rows(Refund::withoutGlobalScopes()->where('tenant_id', $tenant->id)),
        ];
    }

    /** Team members without any credentials. */
    private function team(Tenant $tenant): array
    {
        return $tenant->memberships()->with('user:id,name,email')->get()
            ->map(fn ($m) => [
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'role' => $m->role,
                'all_locations' => (bool) $m->all_locations,
            ])->all();
    }

    /** Combinations including their member tables. */
    private function combinations(array $locationIds): array
    {
        return TableCombination::withoutGlobalScopes()
            ->whereIn('location_id', $locationIds)
            ->with('tables:id,name')
            ->get()
            ->map(function ($c) {
                $row = $this->clean($c);
                $row['table_names'] = $c->tables->pluck('name')->all();

                return $row;
            })->all();
    }

    /** Reservations carry their table assignment by name so it survives a move. */
    private function reservations(Tenant $tenant): array
    {
        $out = [];

        Reservation::withoutGlobalScopes()
            ->withTrashed()
            ->where('tenant_id', $tenant->id)
            ->with(['tables:restaurant_tables.id,name', 'tags:id,name'])
            ->chunk(500, function ($chunk) use (&$out) {
                foreach ($chunk as $reservation) {
                    $row = $this->clean($reservation);
                    $row['table_names'] = $reservation->tables->pluck('name')->all();
                    $row['tag_names'] = $reservation->tags->pluck('name')->all();
                    $out[] = $row;
                }
            });

        return $out;
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function rows(Builder $query): array
    {
        $out = [];
        $query->chunk(1000, function ($chunk) use (&$out) {
            foreach ($chunk as $model) {
                $out[] = $this->clean($model);
            }
        });

        return $out;
    }

    /** Model to array with all secret-ish fields removed. */
    private function clean(Model $model): array
    {
        $data = $model->attributesToArray();

        foreach (self::SECRET_FIELDS as $field) {
            unset($data[$field]);
        }

        // metadata can carry provider references (e.g. refund_ref)
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            unset($data['metadata']['refund_ref']);
        }

        return $data;
    }
}
