<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Support\Navigation\Navigation;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Die frühere Adresse der Einstellungen („/admin/settings“). Sie führt auf die
 * erste Einstellungsseite, die der Benutzer öffnen darf. Der Browser behält
 * dabei eine alte Sprungmarke (#zeiten) bei; settings-anchors.js leitet sie
 * auf die Seite um, die den Bereich übernommen hat.
 */
class SettingsEntryController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $url = Navigation::forRequest($request)->firstSettingsUrl($request->user(), $tenant, $this->context->location());
        abort_if($url === null, 403, Navigation::noSettingsAccessMessage());

        return redirect()->to($url);
    }
}
