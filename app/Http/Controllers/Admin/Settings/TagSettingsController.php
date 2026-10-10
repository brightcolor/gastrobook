<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Support\TenantContext;
use Illuminate\View\View;

/**
 * Einstellungen › Buchungen › Tags: farbige Markierungen für Reservierungen.
 * Gilt für alle Standorte. Anlegen, Ändern und Löschen laufen über
 * TagController (admin.tags.*), den auch die Reservierungsdetails nutzen.
 */
class TagSettingsController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(): View
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        return view('admin.settings.tags', [
            'tags' => Tag::where('tenant_id', $tenant->id)
                ->whereIn('scope', ['reservation', 'both'])
                ->withCount('reservations')
                ->orderBy('name')
                ->get(),
            'salon' => $tenant->isSalon(),
            'defaultColor' => (string) config('swayy.ui.default_label_color'),
            'nameMax' => (int) config('swayy.ui.tag_name_max'),
        ]);
    }
}
