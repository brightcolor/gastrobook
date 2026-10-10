<?php

declare(strict_types=1);

namespace App\Mail\Layout;

/**
 * Alles, was der Rahmen einer Mail braucht, ohne Modelle: nur Text, Farbe
 * und Pfade. So bleibt eine Mail in der Queue klein, und der Rahmen sieht
 * beim Versand so aus wie beim Erzeugen.
 */
final class MailFrame
{
    public const LOOK_TENANT = 'tenant';

    public const LOOK_SWAYY = 'swayy';

    /**
     * @param  array{name: string, street: ?string, city: ?string, phone: ?string, email: ?string, imprint: ?string, privacy: ?string, help: ?string}  $footer
     */
    public function __construct(
        public readonly string $look,
        public readonly string $type,
        public readonly bool $du,
        public readonly ?string $headline,
        public readonly string $brandColor,
        public readonly string $wordmark,
        public readonly ?string $logoPath,
        public readonly ?int $logoWidth,
        public readonly ?int $logoHeight,
        public readonly array $footer,
        public readonly string $reason,
        public readonly ?string $unsubscribeUrl = null,
        public readonly bool $poweredBy = false,
        public readonly bool $structured = true,
    ) {}

    public function theme(): MailTheme
    {
        return MailTheme::fromBrand($this->brandColor);
    }

    public function isSwayy(): bool
    {
        return $this->look === self::LOOK_SWAYY;
    }
}
