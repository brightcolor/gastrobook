<?php

declare(strict_types=1);

namespace App\Support\Navigation;

/**
 * Gruppe der Navigation. $label ist null, wenn weniger als zwei Einträge
 * sichtbar sind: Eine Überschrift über einem einzelnen Eintrag ordnet nichts.
 */
final readonly class NavGroup
{
    /**
     * @param  list<NavItem>  $items
     */
    public function __construct(
        public ?string $label,
        public array $items,
    ) {}
}
