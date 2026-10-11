<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\MoneyInput;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyInputTest extends TestCase
{
    /**
     * @return array<string, array{0: string|int|float, 1: int}>
     */
    public static function amounts(): array
    {
        return [
            'ein Nachkomma' => ['12.5', 1250],
            'zwei Nachkommastellen mit führender Null' => ['0.10', 10],
            'null' => ['0', 0],
            'null mit Nachkommastellen' => ['0.00', 0],
            'ganze Zahl' => ['12', 1200],
            'höchster Betrag der Vorgabe' => ['10000', 1000000],
            'größter Betrag, den die Spalte fasst' => ['21474836', 2147483600],
            'Fließkomma aus JSON' => [12.5, 1250],
            'Fließkomma mit Rundungsfehler' => [1.15, 115],
            'Fließkomma ohne Nachkommastellen' => [10.0, 1000],
            'Fließkomma null' => [0.0, 0],
            'Ganzzahl aus JSON' => [7, 700],
            'Cent-genau bei 0.07' => ['0.07', 7],
            'Cent-genau bei 19.99' => ['19.99', 1999],
        ];
    }

    #[DataProvider('amounts')]
    public function test_it_converts_euros_to_cents_with_digits(string|int|float $euro, int $cents): void
    {
        $this->assertSame($cents, MoneyInput::toMinor($euro));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedInputs(): array
    {
        return [
            'Komma' => ['12,5'],
            'Exponent' => ['1e2'],
            'drei Nachkommastellen' => ['12.345'],
            'negativ' => ['-1'],
            'leer' => [''],
            'Text' => ['zwölf'],
            'Punkt ohne Ziffern' => ['12.'],
            'Zeilenumbruch am Ende' => ["12.5\n"],
        ];
    }

    #[DataProvider('rejectedInputs')]
    public function test_the_pattern_rejects_what_is_not_an_amount_with_a_point(string $input): void
    {
        $this->assertSame(0, preg_match(MoneyInput::PATTERN.'D', $input));
    }

    public function test_the_pattern_accepts_whole_euros_and_one_or_two_decimals(): void
    {
        foreach (['0', '12', '12.5', '12.50', '0.10'] as $input) {
            $this->assertSame(1, preg_match(MoneyInput::PATTERN, $input), $input);
        }
    }

    #[DataProvider('rejectedInputs')]
    public function test_a_text_that_is_no_amount_is_refused_instead_of_guessed(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        MoneyInput::toMinor($input);
    }

    public function test_a_negative_number_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MoneyInput::toMinor(-5);
    }

    public function test_cents_are_written_back_with_digits(): void
    {
        $this->assertSame('12.50', MoneyInput::fromMinor(1250));
        $this->assertSame('0.10', MoneyInput::fromMinor(10));
        $this->assertSame('0.00', MoneyInput::fromMinor(0));
        $this->assertSame('21474836.00', MoneyInput::fromMinor(2147483600));
    }

    public function test_cents_are_shown_the_german_way(): void
    {
        $this->assertSame('12,50 €', MoneyInput::format(1250));
        $this->assertSame('0,05 €', MoneyInput::format(5));
        $this->assertSame('1.234,56 €', MoneyInput::format(123456));
        $this->assertSame('21.474.836,00 €', MoneyInput::format(2147483600));
    }
}
