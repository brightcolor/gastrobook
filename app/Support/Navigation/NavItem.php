<?php

declare(strict_types=1);

namespace App\Support\Navigation;

/**
 * Ein sichtbarer Eintrag der Navigation, fertig für die Ansicht.
 */
final readonly class NavItem
{
    /**
     * @param  list<string>  $keywords  Suchbegriffe (nur Einstellungen)
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $url,
        public string $icon,
        public bool $active,
        public ?string $scope,
        public array $keywords,
    ) {}
}
