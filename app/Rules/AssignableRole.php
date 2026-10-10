<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Tenant;
use App\Models\User;
use App\Support\RoleAssignment;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Darf der angemeldete Benutzer diese Rolle vergeben? Die Meldung nennt den
 * Grund und den naechsten Schritt (RoleAssignment::refusal()).
 */
final class AssignableRole implements ValidationRule
{
    public function __construct(
        private readonly User $user,
        private readonly Tenant $tenant,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $refusal = is_string($value)
            ? RoleAssignment::refusal($this->user, $this->tenant, $value)
            : __('Diese Rolle gibt es nicht. Bitte eine Rolle aus der Liste wählen.');

        if ($refusal !== null) {
            $fail($refusal);
        }
    }
}
