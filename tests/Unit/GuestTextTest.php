<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mail\Layout\GuestText;
use PHPUnit\Framework\TestCase;

class GuestTextTest extends TestCase
{
    public function test_guest_values_become_one_line_without_web_addresses(): void
    {
        $this->assertSame('Fenster bitte. Reservierung bestätigen: evil.example/x', GuestText::inline("Fenster bitte.\r\n\r\nReservierung bestätigen: https://evil.example/x"));
        $this->assertSame('Buchung bestätigen: evil.example\@swayy.test Muster', GuestText::inline('Buchung bestätigen: HTTPS://evil.example\@swayy.test   Muster'));
        $this->assertSame('Frau Kessler', GuestText::inline('  Frau Kessler '));
        $this->assertSame('', GuestText::inline(null));
    }
}
