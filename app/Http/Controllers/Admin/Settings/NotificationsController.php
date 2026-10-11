<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Services\AuditLogger;
use App\Support\NotificationSettingRules;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Einstellungen › Gästekommunikation › Benachrichtigungen: Anrede, Bestätigung,
 * Erinnerung per E-Mail und SMS, Feedback-Anfrage und Info an den Betrieb.
 * Gilt für den gewählten Standort.
 */
class NotificationsController extends Controller
{
    use RespondsToSettingsForms;

    /** Felder, die diese Seite schreibt. */
    public const FIELDS = [
        'guest_address', 'reminder_enabled', 'reminder_hours_before', 'sms_reminder_enabled',
        'feedback_enabled', 'feedback_hours_after', 'feedback_redirect_min_score', 'feedback_external_url',
        'owner_notification_enabled', 'owner_notification_email',
    ];

    /** Schalter der Seite; x-checkbox sendet ohne Haken eine 0. */
    private const SWITCHES = ['reminder_enabled', 'sms_reminder_enabled', 'feedback_enabled', 'owner_notification_enabled'];

    /** Zahlenfelder der Seite; die Prüfung liefert sie als Text. */
    private const NUMBERS = ['reminder_hours_before', 'feedback_hours_after', 'feedback_redirect_min_score'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): View
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $tenant = $this->context->tenant();
        $user = $request->user();

        return view('admin.settings.notifications', [
            'location' => $location,
            'settings' => $location->effectiveSettings(),
            'salon' => $tenant->isSalon(),
            'limits' => (array) config('swayy.notifications'),
            // Die Seiten, auf die diese Seite verweist, verlangen eigene Rechte. Wer sie hat, sieht den Link, alle anderen einen Hinweis.
            'canTemplates' => $user?->canInTenant('templates.manage', $tenant, $location) ?? false,
            'canSms' => $user?->canInTenant('integrations.manage', $tenant, $location) ?? false,
            'smsReady' => IntegrationConnection::whereNull('location_id')
                ->where('provider', 'sevenio')
                ->where('status', 'connected')
                ->whereNotNull('credentials_encrypted')
                ->exists(),
        ]);
    }

    public function update(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate(
            NotificationSettingRules::rules(),
            NotificationSettingRules::messages(),
            NotificationSettingRules::attributes(),
        );
        foreach (self::SWITCHES as $switch) {
            $validated[$switch] = $request->boolean($switch);
        }
        foreach (self::NUMBERS as $number) {
            $validated[$number] = (int) $validated[$number];
        }
        $validated['feedback_external_url'] = trim((string) ($validated['feedback_external_url'] ?? '')) ?: null;
        $validated['owner_notification_email'] = trim((string) ($validated['owner_notification_email'] ?? '')) ?: null;

        $settings = $location->settings()->firstOrCreate(['tenant_id' => $location->tenant_id]);
        $old = $settings->only(self::FIELDS);
        $settings->update($validated);

        $this->audit->log('location.notifications_updated', $settings, $old, $validated);

        return $this->saved($request, __('Benachrichtigungen gespeichert.'));
    }
}
