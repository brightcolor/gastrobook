<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Guest;
use App\Models\GuestConsent;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\GuestProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Stellt Newsletter-Einwilligungen wieder her, die vor 1.136.0 nur durch eine
 * spätere Online-Buchung ohne Haken verloren gingen. Betroffen ist ein Gast,
 * dessen letzter Eintrag zu Newsletter oder Werbung genau dieser Widerruf ist
 * und der vorher eingewilligt hatte. Wer sich über den Abmeldelink abgemeldet
 * hat, steht danach mit einem eigenen Eintrag in der Historie und bleibt
 * abgemeldet. Betriebe, die den leeren Haken als Abmeldung eingestellt haben,
 * bleiben unberührt.
 *
 * Ohne --apply zeigt der Befehl nur, wen es betrifft.
 */
class RestoreNewsletterConsent extends Command
{
    public const CHANNEL = 'correction';

    protected $signature = 'swayy:newsletter-consent-restore
        {--tenant= : Nur diesen Betrieb (ID oder Slug)}
        {--apply : Einwilligungen wirklich wiederherstellen}';

    protected $description = 'Stellt Newsletter-Einwilligungen wieder her, die eine spätere Buchung ohne Haken widerrufen hatte.';

    public function handle(GuestProfileService $guests, AuditLogger $audit): int
    {
        $tenants = Tenant::query()->where('newsletter_unticked_revokes', false)->orderBy('id');
        if (($option = $this->option('tenant')) !== null) {
            $tenants->where(is_numeric($option) ? 'id' : 'slug', $option);
            if (! $tenants->exists()) {
                $this->components->error("Betrieb „{$option}“ nicht gefunden, oder er zählt den leeren Haken als Abmeldung. Bitte ID oder Kürzel aus „php artisan swayy:tenants“ nehmen.");

                return self::FAILURE;
            }
        }

        $total = 0;
        foreach ($tenants->get() as $tenant) {
            $affected = $this->affected($tenant);
            if ($affected === []) {
                continue;
            }
            $total += count($affected);
            $this->components->info("Betrieb #{$tenant->id}: ".count($affected).' Einwilligung(en), Gäste '.implode(', ', array_map(fn (Guest $g) => '#'.$g->id, $affected)));

            if (! $this->option('apply')) {
                continue;
            }
            foreach ($affected as $guest) {
                DB::transaction(function () use ($guests, $audit, $guest, $tenant) {
                    $guests->recordConsent($guest, 'newsletter', true, self::CHANNEL);
                    $audit->log('guest.newsletter_consent_restored', $guest, ['marketing_consent' => false], ['marketing_consent' => true],
                        ['reason' => 'Widerruf durch eine Buchung ohne Haken vor 1.136.0'], null, $tenant->id);
                });
            }
        }

        if ($total === 0) {
            $this->components->info('Keine Einwilligung zum Wiederherstellen gefunden.');
        } elseif (! $this->option('apply')) {
            $this->components->warn("{$total} Einwilligung(en) gefunden. Mit --apply werden sie wiederhergestellt.");
        } else {
            $this->components->info("{$total} Einwilligung(en) wiederhergestellt, Kanal „".self::CHANNEL.'“ in der Einwilligungshistorie.');
        }

        return self::SUCCESS;
    }

    /**
     * @return list<Guest>
     */
    private function affected(Tenant $tenant): array
    {
        $guests = Guest::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('marketing_consent', false)
            ->where('anonymized', false)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        $result = [];
        foreach ($guests as $guest) {
            $history = GuestConsent::withoutGlobalScopes()
                ->where('guest_id', $guest->id)
                ->whereIn('type', ['newsletter', 'marketing'])
                ->orderByDesc('recorded_at')->orderByDesc('id')
                ->get(['type', 'granted', 'channel', 'recorded_at']);

            $last = $history->first();
            if ($last === null || $last->type !== 'newsletter' || $last->granted || $last->channel !== 'booking_widget') {
                continue;
            }
            if ($history->contains(fn (GuestConsent $c) => $c->granted)) {
                $result[] = $guest;
            }
        }

        return $result;
    }
}
