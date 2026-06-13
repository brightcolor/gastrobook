<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class VersionTest extends TestCase
{
    public function test_version_follows_semver(): void
    {
        $version = config('version.number');

        $this->assertIsString($version);
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/',
            $version,
            'config/version.number must be valid SemVer (MAJOR.MINOR.PATCH).'
        );
    }
}
