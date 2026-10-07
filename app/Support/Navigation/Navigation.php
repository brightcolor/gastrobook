<?php

declare(strict_types=1);

namespace App\Support\Navigation;

use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Baut die Navigation der Verwaltung aus config/navigation.php.
 *
 * Filtert nach Rechten und Betriebstyp und wendet die Regel an, dass eine
 * Gruppenüberschrift erst ab zwei sichtbaren Einträgen erscheint.
 */
final class Navigation
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('navigation', []));
    }

    /**
     * @return list<NavGroup>
     */
    public function main(User $user, Tenant $tenant, ?Location $location, ?string $currentRoute): array
    {
        return $this->groups((array) ($this->config['main'] ?? []), $user, $tenant, $location, $currentRoute);
    }

    /**
     * @return list<NavGroup>
     */
    public function settings(User $user, Tenant $tenant, ?Location $location, ?string $currentRoute): array
    {
        return $this->groups((array) ($this->config['settings'] ?? []), $user, $tenant, $location, $currentRoute);
    }

    /**
     * @return list<NavItem>
     */
    public function mobileBar(User $user, Tenant $tenant, ?Location $location, ?string $currentRoute): array
    {
        $items = [];
        foreach ((array) ($this->config['main'] ?? []) as $group) {
            foreach ((array) ($group['items'] ?? []) as $item) {
                if (($item['mobile_bar'] ?? false) === true && $this->visible($item, $user, $tenant, $location)) {
                    $items[] = $this->item($item, $tenant, $currentRoute);
                }
            }
        }

        if (! isset($this->config['mobile_bar_max'])) {
            return $items;
        }

        return array_slice($items, 0, max(0, (int) $this->config['mobile_bar_max']));
    }

    public function firstSettingsUrl(User $user, Tenant $tenant, ?Location $location): ?string
    {
        foreach ($this->settings($user, $tenant, $location, null) as $group) {
            foreach ($group->items as $item) {
                return $item->url;
            }
        }

        return null;
    }

    public function isSettingsRoute(?string $currentRoute): bool
    {
        if ($currentRoute === null) {
            return false;
        }

        $settingsRoutes = array_values(array_map('strval', (array) ($this->config['settings_routes'] ?? [])));
        if (Str::is($settingsRoutes, $currentRoute)) {
            return true;
        }

        foreach ((array) ($this->config['settings'] ?? []) as $group) {
            foreach ((array) ($group['items'] ?? []) as $item) {
                if (Str::is($this->activePatterns($item), $currentRoute)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return list<NavGroup>
     */
    private function groups(array $groups, User $user, Tenant $tenant, ?Location $location, ?string $currentRoute): array
    {
        // Ohne Eintrag heading_min_items zeigt jede Gruppe ihre Überschrift.
        $headingMinItems = (int) ($this->config['heading_min_items'] ?? 0);
        $result = [];
        foreach ($groups as $group) {
            $items = [];
            foreach ((array) ($group['items'] ?? []) as $item) {
                if ($this->visible($item, $user, $tenant, $location)) {
                    $items[] = $this->item($item, $tenant, $currentRoute);
                }
            }

            if ($items === []) {
                continue;
            }

            $label = count($items) >= $headingMinItems ? ($group['label'] ?? null) : null;
            $result[] = new NavGroup($label, $items);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function visible(array $item, User $user, Tenant $tenant, ?Location $location): bool
    {
        $only = $item['only'] ?? null;
        if ($only === 'salon' && ! $tenant->isSalon()) {
            return false;
        }
        if ($only === 'restaurant' && $tenant->isSalon()) {
            return false;
        }

        foreach ((array) ($item['can'] ?? []) as $permission) {
            if (! $user->canInTenant((string) $permission, $tenant, $location)) {
                return false;
            }
        }

        $any = (array) ($item['can_any'] ?? []);
        if ($any === []) {
            return true;
        }

        foreach ($any as $permission) {
            if ($user->canInTenant((string) $permission, $tenant, $location)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function item(array $item, Tenant $tenant, ?string $currentRoute): NavItem
    {
        $label = $tenant->isSalon() && isset($item['label_salon']) ? $item['label_salon'] : $item['label'];
        $url = route((string) $item['route']).(isset($item['fragment']) ? '#'.$item['fragment'] : '');

        return new NavItem(
            key: (string) $item['key'],
            label: (string) $label,
            url: $url,
            icon: (string) ($item['icon'] ?? ''),
            active: $currentRoute !== null && Str::is($this->activePatterns($item), $currentRoute),
            scope: isset($item['scope']) ? (string) $item['scope'] : null,
            keywords: array_values(array_map('strval', (array) ($item['keywords'] ?? []))),
        );
    }

    /**
     * Routenmuster, bei denen ein Eintrag als aktuell gilt. Vorgabe ist die
     * eigene Route; eine leere Liste heißt: nie (Verweise auf Sprungmarken).
     *
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function activePatterns(array $item): array
    {
        return array_values(array_map('strval', (array) ($item['active'] ?? [$item['route']])));
    }
}
