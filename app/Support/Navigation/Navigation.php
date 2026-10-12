<?php

declare(strict_types=1);

namespace App\Support\Navigation;

use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Baut die Navigation der Verwaltung aus config/navigation.php.
 *
 * Filtert nach Rechten, Rollen und Betriebstyp und wendet die Regel an, dass
 * eine Gruppenüberschrift erst ab heading_min_items sichtbaren Einträgen
 * erscheint. Jedes Recht wird je Instanz einmal geprüft, der Zugriff auf den
 * Standort einmal je Standort.
 */
final class Navigation
{
    /** @var array<string, bool> Recht im Betrieb, Schlüssel Benutzer|Betrieb|Recht */
    private array $allowed = [];

    /** @var array<string, bool> Zugriff auf den Standort, Schlüssel Benutzer|Betrieb|Standort */
    private array $locationAccess = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('navigation', []));
    }

    /**
     * Eine Instanz je Anfrage, gemeinsam für Controller und Layout: Rechte
     * und Standortzugriff werden so je Anfrage nur einmal geprüft.
     */
    public static function forRequest(Request $request): self
    {
        $navigation = $request->attributes->get(self::class);
        if (! $navigation instanceof self) {
            $navigation = self::fromConfig();
            $request->attributes->set(self::class, $navigation);
        }

        return $navigation;
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

    /**
     * Adresse der ersten erlaubten Einstellungsseite; die übrigen Einträge
     * werden dafür nicht geprüft.
     */
    public function firstSettingsUrl(User $user, Tenant $tenant, ?Location $location): ?string
    {
        foreach ((array) ($this->config['settings'] ?? []) as $group) {
            foreach ((array) ($group['items'] ?? []) as $item) {
                if ($this->visible($item, $user, $tenant, $location)) {
                    return $this->item($item, $tenant, null)->url;
                }
            }
        }

        return null;
    }

    /**
     * Alte Sprungmarken der früheren Einstellungsseite (config legacy_anchors)
     * mit der Adresse der Seite, die den Bereich übernommen hat. Aufgenommen
     * wird nur ein Ziel, das der Benutzer öffnen darf (Rechte, Betriebstyp wie
     * bei settings()); nennt eine Marke mehrere Ziele, gilt das erste erlaubte.
     * Ein Sprungziel (#popup) hängt an der Adresse.
     *
     * @return array<string, string> Marke ohne # => Adresse
     */
    public function legacyAnchors(User $user, Tenant $tenant, ?Location $location): array
    {
        $items = [];
        foreach ((array) ($this->config['settings'] ?? []) as $group) {
            foreach ((array) ($group['items'] ?? []) as $item) {
                $items[(string) $item['key']] = $item;
            }
        }

        $map = [];
        foreach ((array) ($this->config['legacy_anchors'] ?? []) as $anchor => $targets) {
            foreach (explode('|', (string) $targets) as $target) {
                [$key, $fragment] = array_pad(explode('#', $target, 2), 2, null);
                $item = $items[$key] ?? null;
                if ($item === null || ! $this->visible($item, $user, $tenant, $location)) {
                    continue;
                }

                $map[(string) $anchor] = route((string) $item['route']).($fragment !== null ? '#'.$fragment : '');
                break;
            }
        }

        return $map;
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

            $groupLabel = $tenant->isSalon() && isset($group['label_salon']) ? $group['label_salon'] : ($group['label'] ?? null);
            $label = count($items) >= $headingMinItems ? $groupLabel : null;
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

        $roles = array_map('strval', (array) ($item['roles'] ?? []));
        if ($roles !== [] && ! in_array($user->roleIn($tenant), $roles, true)) {
            return false;
        }

        foreach ((array) ($item['can'] ?? []) as $permission) {
            if (! $this->allows($user, (string) $permission, $tenant, $location)) {
                return false;
            }
        }

        $any = (array) ($item['can_any'] ?? []);
        if ($any === []) {
            return true;
        }

        foreach ($any as $permission) {
            if ($this->allows($user, (string) $permission, $tenant, $location)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gleiches Ergebnis wie User::canInTenant($permission, $tenant, $location),
     * mit Zwischenspeicher: Das Recht wird je Benutzer und Betrieb einmal
     * geprüft, der Standortzugriff (eine Abfrage bei Mitgliedern mit
     * freigegebenen Standorten) einmal je Standort. Plattform-Zugänge haben
     * Zugriff auf jeden Standort.
     */
    private function allows(User $user, string $permission, Tenant $tenant, ?Location $location): bool
    {
        $key = $user->id.'|'.$tenant->id.'|'.$permission;
        $this->allowed[$key] ??= $user->canInTenant($permission, $tenant);

        if (! $this->allowed[$key] || $location === null || $user->isSaasAdmin()) {
            return $this->allowed[$key];
        }

        $locationKey = $user->id.'|'.$tenant->id.'|'.$location->id;

        return $this->locationAccess[$locationKey] ??= $user->canAccessLocation($tenant, $location);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function item(array $item, Tenant $tenant, ?string $currentRoute): NavItem
    {
        $label = $tenant->isSalon() && isset($item['label_salon']) ? $item['label_salon'] : $item['label'];
        $url = route((string) $item['route']).(isset($item['fragment']) ? '#'.$item['fragment'] : '');
        // keywords_restaurant: Begriffe, deren Ziel nur die Seite eines Restaurants zeigt.
        $keywords = (array) ($item['keywords'] ?? []);
        if (! $tenant->isSalon()) {
            $keywords = array_merge($keywords, (array) ($item['keywords_restaurant'] ?? []));
        }

        return new NavItem(
            key: (string) $item['key'],
            label: (string) $label,
            url: $url,
            icon: (string) ($item['icon'] ?? ''),
            active: $currentRoute !== null && Str::is($this->activePatterns($item), $currentRoute),
            scope: isset($item['scope']) ? (string) $item['scope'] : null,
            keywords: $this->keywords($keywords),
        );
    }

    /**
     * Suchbegriffe als Liste; Begriff ⇒ Ziel wird zu „Begriff#ziel“.
     *
     * @param  array<int|string, mixed>  $keywords
     * @return list<string>
     */
    private function keywords(array $keywords): array
    {
        $result = [];
        foreach ($keywords as $word => $target) {
            $result[] = is_string($word) ? $word.'#'.$target : (string) $target;
        }

        return $result;
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
