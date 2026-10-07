<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Support\Navigation\Navigation;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Liste aller erlaubten Einstellungsseiten. Auf dem Smartphone der Einstieg
 * in den Einstellungsbereich; auf breiten Bildschirmen führt „Einstellungen"
 * direkt zur ersten erlaubten Seite.
 */
class SettingsMenuController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request): View
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $groups = Navigation::fromConfig()->settings($request->user(), $tenant, $this->context->location(), null);
        abort_if($groups === [], 403, __('Für die Einstellungen fehlt die Berechtigung. Bitte einen Administrator des Betriebs fragen.'));

        return view('admin.settings.menu', ['groups' => $groups]);
    }
}
