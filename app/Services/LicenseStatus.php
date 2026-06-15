<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

final class LicenseStatus
{
    public function __construct(
        public readonly bool $valid,
        public readonly bool $selfHosted,
        public readonly string $plan = 'unknown',
        public readonly string $licensee = '',
        public readonly string $licenseId = '',
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly bool $inGracePeriod = false,
        public readonly bool $revoked = false,
        public readonly ?string $error = null,
    ) {}

    /** Days until expiry, null if no expiry date. Negative = already expired. */
    public function daysLeft(): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }

        return (int) CarbonImmutable::now()->diffInDays($this->expiresAt, false);
    }

    public function warningShouldShow(): bool
    {
        if (! $this->selfHosted || $this->inGracePeriod) {
            return false;
        }

        $days = $this->daysLeft();

        return $days !== null && $days <= config('license.warn_days', 30) && $days >= 0;
    }

    public function isHardLocked(): bool
    {
        return $this->selfHosted && (! $this->valid && ! $this->inGracePeriod);
    }
}
