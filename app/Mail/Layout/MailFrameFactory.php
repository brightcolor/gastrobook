<?php

declare(strict_types=1);

namespace App\Mail\Layout;

use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * Baut den Rahmen einer Mail: im Auftritt des Betriebs (Gast-Mails) oder im
 * Auftritt von Swayy (Mails an Betriebe, Swayy-Mails).
 */
final class MailFrameFactory
{
    /**
     * @param  array<string, string>  $vars  weitere Platzhalter für Überschrift und Grund
     */
    public function forGuest(string $type, ?Tenant $tenant, ?Location $location, array $vars = [], ?string $unsubscribeUrl = null): MailFrame
    {
        $du = $location?->effectiveSettings()->du() ?? false;
        $name = trim((string) ($location?->name ?: ($tenant?->name ?: config('mail.from.name'))));
        $vars = ['location' => $name, 'tenant' => (string) ($tenant?->name ?: $name), 'guest' => '', 'subject' => '', ...$vars];
        [$logoPath, $logoWidth, $logoHeight] = $this->logo($location?->brand_logo_path ?: $tenant?->brand_logo_path, (int) config('swayy.mail.logo_height', 44));

        return new MailFrame(
            look: MailFrame::LOOK_TENANT,
            type: $type,
            du: $du,
            headline: $this->headline($type, $du, $vars),
            brandColor: (string) ($tenant?->brand_primary_color ?: config('swayy.ui.default_brand_color')),
            wordmark: $name,
            logoPath: $logoPath,
            logoWidth: $logoWidth,
            logoHeight: $logoHeight,
            footer: [
                'name' => $name,
                'street' => self::filled($location?->address_line1),
                'city' => self::filled(trim(($location?->postal_code ?? '').' '.($location?->city ?? ''))),
                'phone' => self::filled($location?->phone),
                'email' => self::email($location?->email),
                'imprint' => self::url($tenant?->imprint_url),
                'privacy' => self::url($tenant?->privacy_url),
                'help' => null,
            ],
            reason: $this->reason($type, $du, $vars),
            unsubscribeUrl: $unsubscribeUrl,
            poweredBy: (bool) config('swayy.mail.powered_by', true),
            listId: $unsubscribeUrl !== null ? self::listId($name, $tenant) : null,
        );
    }

    /**
     * Kennung der Kampagnen-Liste eines Betriebs (RFC 2919). Gehört zum
     * Abmelde-Kopf; fehlt sie, werten Spamfilter die Liste als unvollständig.
     */
    private static function listId(string $name, ?Tenant $tenant): string
    {
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)) ?: 'localhost';

        return 'Neuigkeiten von '.$name.' <marketing-'.($tenant?->id ?? 0).'.'.$host.'>';
    }

    /**
     * @param  array<string, string>  $vars
     */
    public function forSwayy(string $type, array $vars = [], bool $structured = true): MailFrame
    {
        $du = self::ownerDu();
        $vars = ['location' => '', 'tenant' => '', 'guest' => '', 'subject' => '', ...$vars];
        [$logoPath, $logoWidth, $logoHeight] = $this->swayyLogo();

        return new MailFrame(
            look: MailFrame::LOOK_SWAYY,
            type: $type,
            du: $du,
            headline: $this->headline($type, $du, $vars),
            brandColor: (string) config('swayy.ui.default_brand_color'),
            wordmark: 'Swayy',
            logoPath: $logoPath,
            logoWidth: $logoWidth,
            logoHeight: $logoHeight,
            footer: [
                'name' => 'Swayy',
                'street' => null,
                'city' => null,
                'phone' => null,
                'email' => null,
                'imprint' => route('legal.imprint'),
                'privacy' => route('legal.privacy'),
                'help' => route('contact'),
            ],
            reason: $this->reason($type, $du, $vars),
            structured: $structured,
        );
    }

    public static function ownerDu(): bool
    {
        return config('swayy.mail.owner_address', 'du') === 'du';
    }

    /**
     * Hosts, auf die ein Knopf im Swayy-Auftritt zeigen darf.
     *
     * @return list<string>
     */
    public static function buttonHosts(): array
    {
        $hosts = [];
        // Eigene Adresse aus der Einstellung, die Adresse, unter der die
        // Anwendung gerade läuft (Links aus derselben Anfrage), und das Kundenpanel.
        foreach ([config('app.url'), url('/'), config('provisioning.panel_base_url')] as $url) {
            $host = is_string($url) ? strtolower((string) parse_url($url, PHP_URL_HOST)) : '';
            if ($host !== '' && ! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function headline(string $type, bool $du, array $vars): ?string
    {
        $key = "mails.headlines.{$type}.".($du ? 'du' : 'Sie');
        if (! trans()->has($key, 'de')) {
            return null;
        }
        $text = trim((string) __($key, $vars, 'de'));

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function reason(string $type, bool $du, array $vars): string
    {
        $group = trans()->has("mails.reasons.{$type}", 'de') ? $type : match (true) {
            str_starts_with($type, 'operator_') => 'operator',
            str_starts_with($type, 'swayy_') => 'swayy',
            default => 'guest',
        };

        return (string) __("mails.reasons.{$group}.".($du ? 'du' : 'Sie'), $vars, 'de');
    }

    /**
     * @return array{0: ?string, 1: ?int, 2: ?int}
     */
    private function logo(?string $stored, int $height): array
    {
        if ($stored === null || $stored === '') {
            return [null, null, null];
        }

        $disk = Storage::disk('public');
        $extension = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
        if (! in_array($extension, (array) config('swayy.mail.logo_types'), true) || ! $disk->exists($stored)
            || $disk->size($stored) > (int) config('swayy.mail.logo_max_kb', 200) * 1024) {
            return [null, null, null];
        }

        return $this->measured($disk->path($stored), $height);
    }

    /**
     * @return array{0: ?string, 1: ?int, 2: ?int}
     */
    private function swayyLogo(): array
    {
        $path = (string) config('swayy.mail.swayy_logo');

        return is_file($path) ? $this->measured($path, (int) config('swayy.mail.swayy_logo_height', 32)) : [null, null, null];
    }

    /**
     * @return array{0: ?string, 1: ?int, 2: ?int}
     */
    private function measured(string $path, int $height): array
    {
        $size = @getimagesize($path);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return [null, null, null];
        }
        $width = min((int) config('swayy.mail.logo_max_width', 220), (int) round($height * $size[0] / $size[1]));

        return [$path, $width, $height];
    }

    private static function filled(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function email(?string $value): ?string
    {
        $value = trim((string) $value);

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    private static function url(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('~^https?://\S+$~i', $value) === 1 ? $value : null;
    }
}
