<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Illuminate\View\View;

/**
 * Einstellungen › Betrieb › Daten: Export, Import und „Betrieb löschen“.
 * Nur für Inhaber, wie die Aktionen im AccountController; der Eintrag in der
 * Navigation folgt derselben Prüfung (config/navigation.php, roles).
 */
class DataController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(Request $request): View
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);
        abort_unless(
            $request->user()->roleIn($tenant) === 'tenant_owner',
            403,
            __('„Daten“ steht nur Inhabern des Betriebs offen. Bitte eine Person mit der Rolle „Inhaber“ um den Export bitten.'),
        );

        return view('admin.settings.data', [
            'tenant' => $tenant,
            'isLastOwner' => $tenant->memberships()->where('role', 'tenant_owner')->count() <= 1,
            'importLimit' => Number::fileSize((int) config('swayy.uploads.import_max_kb') * 1024),
        ]);
    }
}
