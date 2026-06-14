<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ValidationMessagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('de');
    }

    public function test_numeric_max_message_is_plain_german(): void
    {
        $v = Validator::make(['party_size' => 99], ['party_size' => ['integer', 'max:8']]);

        $msg = $v->errors()->first('party_size');
        // No technical English like "maximum numeric violation"
        $this->assertStringNotContainsStringIgnoringCase('violation', $msg);
        $this->assertStringNotContainsStringIgnoringCase('numeric', $msg);
        // Friendly attribute + clear hint
        $this->assertStringContainsString('Personenzahl', $msg);
    }

    public function test_required_uses_friendly_attribute_name(): void
    {
        $v = Validator::make(['email' => ''], ['email' => ['required']]);

        $this->assertSame('E-Mail-Adresse ist ein Pflichtfeld.', $v->errors()->first('email'));
    }

    public function test_email_message_includes_example(): void
    {
        $v = Validator::make(['email' => 'keine-mail'], ['email' => ['email']]);

        $this->assertStringContainsString('gültige E-Mail-Adresse', $v->errors()->first('email'));
    }

    public function test_generic_numeric_max_field_reads_naturally(): void
    {
        // A field without a custom attribute still reads in German
        $v = Validator::make(['betrag' => 5000], ['betrag' => ['numeric', 'max:100']]);

        $this->assertStringContainsString('höchstens 100', $v->errors()->first('betrag'));
    }
}
