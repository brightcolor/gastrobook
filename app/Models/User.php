<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
        'saas_role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function tenantMemberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->withPivot(['role', 'all_locations'])
            ->withTimestamps();
    }

    public function allowedLocations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'location_user');
    }

    public function isSuperAdmin(): bool
    {
        return $this->saas_role === 'super_admin';
    }

    public function isSaasAdmin(): bool
    {
        return $this->saas_role !== null;
    }

    public function membershipFor(Tenant $tenant): ?TenantUser
    {
        return $this->tenantMemberships->firstWhere('tenant_id', $tenant->id);
    }

    public function roleIn(Tenant $tenant): ?string
    {
        return $this->membershipFor($tenant)?->role;
    }

    /**
     * Tenant-level permission check, optionally restricted to a location.
     */
    public function canInTenant(string $permission, Tenant $tenant, ?Location $location = null): bool
    {
        if ($this->isSuperAdmin() || $this->saas_role === 'support_admin') {
            return true;
        }

        if ($this->saas_role === 'readonly_admin') {
            return str_ends_with($permission, '.view');
        }

        $membership = $this->membershipFor($tenant);
        if ($membership === null) {
            return false;
        }

        $rolePermissions = config('permissions.roles.'.$membership->role, []);
        $hasPermission = in_array('*', $rolePermissions, true)
            || in_array($permission, $rolePermissions, true);

        if (! $hasPermission) {
            return false;
        }

        if ($location !== null) {
            return $this->canAccessLocation($tenant, $location);
        }

        return true;
    }

    public function canAccessLocation(Tenant $tenant, Location $location): bool
    {
        if ($location->tenant_id !== $tenant->id) {
            return false;
        }

        if ($this->isSaasAdmin()) {
            return true;
        }

        $membership = $this->membershipFor($tenant);
        if ($membership === null) {
            return false;
        }

        if ($membership->all_locations) {
            return true;
        }

        return $this->allowedLocations()
            ->where('locations.id', $location->id)
            ->exists();
    }
}
