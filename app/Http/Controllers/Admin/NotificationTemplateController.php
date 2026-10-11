<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use App\Services\AuditLogger;
use App\Services\NotificationTemplateRenderer;
use App\Support\TemplateLabels;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Einstellungen › Gästekommunikation › Vorlagen: Betreff und Text der Mails an
 * Gäste. Gilt für alle Standorte des Betriebs; ohne eigene Fassung gilt der
 * eingebaute Standardtext.
 */
class NotificationTemplateController extends Controller
{
    use RespondsToSettingsForms;

    /** Platzhalter für Tische und Räume; es gibt sie nur in einem Restaurant. */
    private const RESTAURANT_PLACEHOLDERS = ['table_name', 'room_name'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);
        $salon = $tenant->isSalon();

        $overrides = NotificationTemplate::where('locale', 'de')
            ->whereNull('location_id')
            ->get()
            ->keyBy('key');

        $templates = collect(NotificationTemplateRenderer::defaults())
            ->only(array_keys(TemplateLabels::LABELS))
            ->map(function (array $default, string $key) use ($overrides, $salon) {
                $override = $overrides->get($key);

                return [
                    'key' => $key,
                    'label' => $this->label($key, $salon),
                    'subject' => $override->subject ?? $default['subject'],
                    'body' => $override->body ?? $default['body'],
                    'customized' => $override !== null,
                ];
            })
            ->values();

        return view('admin.templates.index', [
            'templates' => $templates,
            'placeholders' => array_values(array_diff(
                NotificationTemplateRenderer::placeholderHints(),
                $salon ? self::RESTAURANT_PLACEHOLDERS : [],
            )),
            'limits' => (array) config('swayy.templates'),
            'salon' => $salon,
        ]);
    }

    public function update(Request $request, string $key): mixed
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);
        abort_unless(array_key_exists($key, TemplateLabels::LABELS), 404);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:'.(int) config('swayy.templates.subject_max')],
            'body' => ['required', 'string', 'max:'.(int) config('swayy.templates.body_max')],
        ], [
            'subject.required' => __('Bitte einen Betreff eintragen.'),
            'subject.string' => __('Der Betreff muss ein Text sein. Bitte neu eintragen.'),
            'subject.max' => __('Der Betreff darf höchstens :max Zeichen haben. Bitte kürzen.'),
            'body.required' => __('Bitte einen Text eintragen. Zum Zurücksetzen auf den Standard gibt es „Auf Standard zurücksetzen“.'),
            'body.string' => __('Der Text muss ein Text sein. Bitte neu eintragen.'),
            'body.max' => __('Der Text darf höchstens :max Zeichen haben. Bitte kürzen.'),
        ]);

        $template = NotificationTemplate::updateOrCreate(
            ['tenant_id' => $tenant->id, 'location_id' => null, 'key' => $key, 'locale' => 'de'],
            ['subject' => $validated['subject'], 'body' => $validated['body'], 'is_active' => true],
        );

        $this->audit->log('template.updated', $template, null, ['key' => $key]);

        return $this->saved($request, __('Vorlage „:name“ gespeichert.', ['name' => $this->label($key, $tenant->isSalon())]), reload: true);
    }

    public function reset(Request $request, string $key): mixed
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);
        abort_unless(array_key_exists($key, TemplateLabels::LABELS), 404);

        NotificationTemplate::where('tenant_id', $tenant->id)
            ->whereNull('location_id')
            ->where('key', $key)
            ->where('locale', 'de')
            ->delete();

        $this->audit->log('template.reset', null, null, ['key' => $key]);

        return $this->saved($request, __('Vorlage „:name“ auf Standard zurückgesetzt.', ['name' => $this->label($key, $tenant->isSalon())]), reload: true);
    }

    private function label(string $key, bool $salon): string
    {
        return TemplateLabels::label($key, $salon);
    }
}
