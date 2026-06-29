<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use App\Services\AuditLogger;
use App\Services\NotificationTemplateRenderer;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class NotificationTemplateController extends Controller
{
    /** Human-readable labels for the editable template keys. */
    private const LABELS = [
        'reservation_confirmed' => 'Reservierung bestätigt',
        'reservation_requested' => 'Anfrage eingegangen',
        'reservation_cancelled' => 'Reservierung storniert',
        'reservation_rejected' => 'Anfrage abgelehnt',
        'reservation_reminder' => 'Erinnerung',
        'feedback_request' => 'Feedback-Anfrage',
        'waitlist_offer' => 'Wartelisten-Angebot',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index()
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $overrides = NotificationTemplate::where('locale', 'de')
            ->whereNull('location_id')
            ->get()
            ->keyBy('key');

        $templates = collect(NotificationTemplateRenderer::defaults())
            ->only(array_keys(self::LABELS))
            ->map(function (array $default, string $key) use ($overrides) {
                $override = $overrides->get($key);

                return [
                    'key' => $key,
                    'label' => self::LABELS[$key] ?? $key,
                    'subject' => $override->subject ?? $default['subject'],
                    'body' => $override->body ?? $default['body'],
                    'customized' => $override !== null,
                ];
            })
            ->values();

        return view('admin.templates.index', [
            'templates' => $templates,
            'placeholders' => NotificationTemplateRenderer::placeholderHints(),
        ]);
    }

    public function update(Request $request, string $key)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);
        abort_unless(array_key_exists($key, self::LABELS), 404);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $template = NotificationTemplate::updateOrCreate(
            ['tenant_id' => $tenant->id, 'location_id' => null, 'key' => $key, 'locale' => 'de'],
            ['subject' => $validated['subject'], 'body' => $validated['body'], 'is_active' => true],
        );

        $this->audit->log('template.updated', $template, null, ['key' => $key]);

        return back()->with('success', __('Vorlage „:name" gespeichert.', ['name' => self::LABELS[$key]]));
    }

    public function reset(string $key)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);
        abort_unless(array_key_exists($key, self::LABELS), 404);

        NotificationTemplate::where('tenant_id', $tenant->id)
            ->whereNull('location_id')
            ->where('key', $key)
            ->where('locale', 'de')
            ->delete();

        $this->audit->log('template.reset', null, null, ['key' => $key]);

        return back()->with('success', __('Vorlage „:name" auf Standard zurückgesetzt.', ['name' => self::LABELS[$key]]));
    }
}
