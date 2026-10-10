<?php

declare(strict_types=1);

namespace App\Mail\Layout;

/**
 * Ein Baustein im Rahmen einer Mail. Der Text bleibt roh; maskiert wird erst
 * beim Zeichnen (resources/views/mail/layout/_block.blade.php).
 */
final class Block
{
    public const PARAGRAPH = 'paragraph';

    public const DETAILS = 'details';

    public const BUTTON = 'button';

    public const DIVIDER = 'divider';

    /**
     * @param  list<array{label: string, value: string}>  $rows
     */
    private function __construct(
        public readonly string $type,
        public readonly string $text = '',
        public readonly array $rows = [],
        public readonly string $label = '',
        public readonly string $url = '',
        public readonly bool $primary = false,
    ) {}

    public static function paragraph(string $text): self
    {
        return new self(self::PARAGRAPH, text: $text);
    }

    /**
     * @param  list<array{label: string, value: string}>  $rows
     */
    public static function details(array $rows): self
    {
        return new self(self::DETAILS, rows: $rows);
    }

    public static function button(string $label, string $url, bool $primary): self
    {
        return new self(self::BUTTON, label: $label, url: $url, primary: $primary);
    }

    public static function divider(): self
    {
        return new self(self::DIVIDER);
    }
}
