<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Self-service signup: creates a trial tenant with owner account and
 * first location in one transaction. Used by the public registration page.
 */
class TenantSignupService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{restaurant_name: string, name: string, email: string, password: string, timezone?: string|null}  $data
     * @return array{0: Tenant, 1: User}
     */
    public function signup(array $data): array
    {
        $plan = Plan::where('key', 'trial')->where('is_active', true)->firstOrFail();

        [$tenant, $user] = DB::transaction(function () use ($data, $plan) {
            $tenant = Tenant::create([
                'name' => $data['restaurant_name'],
                'slug' => $this->uniqueSlug($data['restaurant_name']),
                'plan_id' => $plan->id,
                'status' => 'active',
                'trial_ends_at' => $plan->trial_days > 0 ? now()->addDays($plan->trial_days) : null,
            ]);

            $user = new User([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => Hash::make($data['password']),
            ]);
            $user->forceFill(['current_tenant_id' => $tenant->id])->save();

            TenantUser::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => 'tenant_owner',
                'all_locations' => true,
            ]);

            $location = Location::create([
                'tenant_id' => $tenant->id,
                'name' => $data['restaurant_name'],
                'slug' => Str::slug($data['restaurant_name']),
                'timezone' => $data['timezone'] ?? 'Europe/Berlin',
            ]);
            $location->settings()->create(['tenant_id' => $tenant->id]);

            return [$tenant, $user];
        });

        $this->audit->log('tenant.signed_up', $tenant, null, ['name' => $tenant->name], null, $user, $tenant->id);

        return [$tenant, $user];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'restaurant';
        $slug = $base;
        $i = 1;
        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
