<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\DepositRule;
use App\Models\Service;
use App\Services\PaymentRequirementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Deposits could be tied to party size, time, room and event – never to a
 * service. In a salon that is the handle that matters: an advance payment for
 * the balayage, none for the fringe trim.
 */
class ServiceDepositRuleTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** @return array{0: array<string, mixed>, 1: Service, 2: Service} */
    private function salonWithServices(): array
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);

        $balayage = Service::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'name' => 'Balayage', 'duration_minutes' => 180, 'price_minor' => 18000, 'is_active' => true,
        ]);
        $trim = Service::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'name' => 'Pony schneiden', 'duration_minutes' => 15, 'price_minor' => 1200, 'is_active' => true,
        ]);

        return [$setup, $balayage, $trim];
    }

    private function rule(array $setup, ?int $serviceId, string $name = 'Anzahlung'): DepositRule
    {
        return DepositRule::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'name' => $name,
            'type' => 'deposit',
            'service_id' => $serviceId,
            'amount_per_person_minor' => 0,
            'flat_amount_minor' => 3000,
            'currency' => 'EUR',
            'payment_deadline_minutes' => 60,
            'is_active' => true,
        ]);
    }

    public function test_a_service_rule_only_fires_for_that_service(): void
    {
        [$setup, $balayage, $trim] = $this->salonWithServices();
        $this->rule($setup, $balayage->id);
        $this->clearTenantContext();

        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(14, 0);
        $service = app(PaymentRequirementService::class);

        $this->assertNotNull($service->requirementFor($setup['location'], $start, 1, null, null, [$balayage->id]));
        $this->assertNull($service->requirementFor($setup['location'], $start, 1, null, null, [$trim->id]));
    }

    public function test_it_also_fires_inside_a_combination(): void
    {
        [$setup, $balayage, $trim] = $this->salonWithServices();
        $this->rule($setup, $balayage->id);
        $this->clearTenantContext();

        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(14, 0);

        $rule = app(PaymentRequirementService::class)
            ->requirementFor($setup['location'], $start, 1, null, null, [$trim->id, $balayage->id]);

        $this->assertNotNull($rule);
    }

    public function test_a_rule_without_a_service_still_applies_to_everything(): void
    {
        [$setup, , $trim] = $this->salonWithServices();
        $this->rule($setup, null);
        $this->clearTenantContext();

        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(14, 0);
        $service = app(PaymentRequirementService::class);

        $this->assertNotNull($service->requirementFor($setup['location'], $start, 2));
        $this->assertNotNull($service->requirementFor($setup['location'], $start, 1, null, null, [$trim->id]));
    }

    public function test_the_service_rule_beats_the_blanket_rule(): void
    {
        [$setup, $balayage] = $this->salonWithServices();
        $this->rule($setup, null, 'Für alle');
        $this->rule($setup, $balayage->id, 'Nur Balayage');
        $this->clearTenantContext();

        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(14, 0);

        $rule = app(PaymentRequirementService::class)
            ->requirementFor($setup['location'], $start, 1, null, null, [$balayage->id]);

        $this->assertSame('Nur Balayage', $rule?->name);
    }

    public function test_the_rule_can_be_set_in_the_admin(): void
    {
        [$setup, $balayage] = $this->salonWithServices();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/settings/deposit-rules', [
            'name' => 'Balayage-Anzahlung',
            'service_id' => $balayage->id,
            'amount_per_person' => 0,
            'flat_amount' => 30,
        ])->assertRedirect();

        $this->assertDatabaseHas('deposit_rules', [
            'name' => 'Balayage-Anzahlung',
            'service_id' => $balayage->id,
            'flat_amount_minor' => 3000,
        ]);
    }

    public function test_a_service_of_another_business_is_not_accepted(): void
    {
        [$setup] = $this->salonWithServices();
        [, $foreignService] = $this->salonWithServices();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/settings/deposit-rules', [
            'name' => 'Fremde Leistung',
            'service_id' => $foreignService->id,
            'amount_per_person' => 5,
        ])->assertRedirect();

        // Saved, but without the foreign service – never silently attached.
        $this->assertDatabaseHas('deposit_rules', [
            'name' => 'Fremde Leistung',
            'service_id' => null,
        ]);
    }
}
