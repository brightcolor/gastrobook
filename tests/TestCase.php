<?php

namespace Tests;

use App\Support\OutboundUrlGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Ein Test, der die Namensaufloesung ersetzt, soll den naechsten nicht
        // beeinflussen.
        OutboundUrlGuard::resolveUsing(null);
    }

    protected function tearDown(): void
    {
        OutboundUrlGuard::resolveUsing(null);

        parent::tearDown();
    }
}
