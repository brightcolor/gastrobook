<?php

declare(strict_types=1);

namespace App\Http\Controllers\Provisioning;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Provisioning\AccountState;
use App\Provisioning\LoginLinks;
use App\Provisioning\Signature;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Die beiden Wege zwischen Kundenpanel und swayy im Browser.
 *
 * Hinein: der einmalige Anmeldelink aus „Zum Dienst“. Zurueck: „Tarif und
 * Rechnungen“, ein signierter Link ins Panel, den nur der Inhaber bekommt;
 * alle anderen Zugaenge landen auf der Anmeldung des Kundenbereichs.
 */
class PanelLinkController extends Controller
{
    public function login(Request $request, string $token, LoginLinks $links): RedirectResponse|View
    {
        $eintrag = $links->redeem($token);
        $benutzer = $eintrag !== null ? User::find($eintrag->user_id) : null;
        $betrieb = $eintrag !== null ? Tenant::find($eintrag->tenant_id) : null;

        if ($benutzer === null || $betrieb === null || $benutzer->membershipFor($betrieb) === null || ! AccountState::isOpen($betrieb)) {
            return view('provisioning.link-expired', ['panelUrl' => (string) config('provisioning.panel_base_url')]);
        }

        // Wer gerade angemeldet ist, wird abgemeldet: Der Link gehoert dem Inhaber.
        if (Auth::check()) {
            Auth::logout();
            $request->session()->invalidate();
        }
        $benutzer->forceFill(['current_tenant_id' => $betrieb->id])->save();
        Auth::login($benutzer);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard');
    }

    public function back(Request $request, TenantContext $context): RedirectResponse
    {
        $betrieb = $context->tenant();
        $panel = (string) config('provisioning.panel_base_url');
        $schluessel = (array) config('provisioning.keys', []);

        if ($betrieb === null || ! $betrieb->hasContract() || $panel === '' || $schluessel === []) {
            return redirect()->route('admin.dashboard')->with('error', __('Tarif und Rechnungen für diesen Betrieb führt der Kundenbereich noch nicht. Schreib uns an info@swayy.de, wenn du deinen Tarif ändern möchtest.'));
        }

        // Nur der Inhaber bekommt die Anmeldung im Kundenbereich mit; alle
        // anderen melden sich dort selbst an.
        if ($request->user()?->id !== $betrieb->owner_user_id) {
            return redirect()->away($panel);
        }

        return redirect()->away(Signature::backUrl(
            $panel,
            (string) $schluessel[0],
            (string) config('provisioning.service_code'),
            (string) $betrieb->contract_ref,
            time(),
            bin2hex(random_bytes(16)),
        ));
    }
}
