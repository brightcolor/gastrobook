<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TemplatedMail;
use App\Models\Guest;
use App\Models\MarketingCampaign;
use App\Services\AuditLogger;
use App\Services\MarketingCampaignService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Automatic guest campaigns – birthday, rebooking reminder, win-back.
 */
class MarketingCampaignController extends Controller
{
    /** Starting texts, so nobody has to face an empty box. */
    private const SUGGESTIONS = [
        'birthday' => [
            'name' => 'Geburtstagsgruß',
            'offset_days' => 3,
            'subject' => 'Alles Gute, {first_name}!',
            'body' => "Hallo {first_name},\n\nwir wünschen alles Gute zum Geburtstag!\n\nWenn Sie mit uns feiern möchten: {booking_link}\n\nHerzliche Grüße\n{location_name}\n\n---\nKeine Werbung mehr? Hier abmelden: {unsubscribe_link}",
        ],
        'rebooking' => [
            'name' => 'Erinnerung nach dem Besuch',
            'offset_days' => 42,
            'subject' => 'Zeit für den nächsten Termin?',
            'body' => "Hallo {first_name},\n\nIhr letzter Besuch bei uns ist eine Weile her. Falls Sie wiederkommen möchten, hier geht es direkt zur Buchung:\n{booking_link}\n\nWir freuen uns auf Sie!\n{location_name}\n\n---\nKeine Werbung mehr? Hier abmelden: {unsubscribe_link}",
        ],
        'winback' => [
            'name' => 'Lange nicht gesehen',
            'offset_days' => 180,
            'subject' => 'Wir würden uns freuen, Sie wiederzusehen',
            'body' => "Hallo {first_name},\n\nwir haben Sie länger nicht gesehen und würden uns freuen, Sie wieder bei uns zu begrüßen.\n\nHier geht es zur Buchung: {booking_link}\n\nHerzliche Grüße\n{location_name}\n\n---\nKeine Werbung mehr? Hier abmelden: {unsubscribe_link}",
        ],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly MarketingCampaignService $campaigns,
        private readonly AuditLogger $audit,
    ) {}

    public function index()
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $campaigns = MarketingCampaign::where('location_id', $location->id)
            ->withCount('sends')
            ->orderBy('type')
            ->get();

        // How many guests each campaign would reach today – makes the effect
        // visible before anything is switched on.
        $reach = $campaigns->mapWithKeys(fn (MarketingCampaign $c) => [
            $c->id => $this->campaigns->recipients($c)->count(),
        ])->all();

        return view('admin.marketing.index', [
            'location' => $location,
            'campaigns' => $campaigns,
            'reach' => $reach,
            'suggestions' => self::SUGGESTIONS,
            'consenting' => Guest::where('anonymized', false)->where('marketing_consent', true)->count(),
        ]);
    }

    public function store(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $this->validated($request);

        $campaign = MarketingCampaign::create($validated + [
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'is_active' => false, // never starts mailing on its own
        ]);

        $this->audit->log('marketing_campaign.created', $campaign, null, $validated);

        return back()->with('success', __('Kampagne angelegt – sie verschickt erst etwas, wenn du sie aktivierst.'));
    }

    public function update(Request $request, MarketingCampaign $campaign)
    {
        $this->authorizeCampaign($campaign);

        $validated = $this->validated($request, $campaign);
        $campaign->update($validated);

        $this->audit->log('marketing_campaign.updated', $campaign, null, $validated);

        return back()->with('success', __('Kampagne gespeichert.'));
    }

    public function toggle(MarketingCampaign $campaign)
    {
        $this->authorizeCampaign($campaign);

        $campaign->update(['is_active' => ! $campaign->is_active]);
        $this->audit->log($campaign->is_active ? 'marketing_campaign.activated' : 'marketing_campaign.paused', $campaign);

        return back()->with('success', $campaign->is_active
            ? __('Kampagne läuft ab jetzt täglich.')
            : __('Kampagne pausiert.'));
    }

    public function destroy(MarketingCampaign $campaign)
    {
        $this->authorizeCampaign($campaign);

        $this->audit->log('marketing_campaign.deleted', $campaign, ['name' => $campaign->name]);
        $campaign->delete();

        return back()->with('success', __('Kampagne gelöscht.'));
    }

    /**
     * Send the campaign to the logged-in user – with their own name filled in,
     * so the text can be checked before any guest sees it.
     */
    public function test(Request $request, MarketingCampaign $campaign)
    {
        $this->authorizeCampaign($campaign);

        $location = $this->context->location();
        abort_if($location === null, 404);

        $sample = new Guest([
            'tenant_id' => $campaign->tenant_id,
            'first_name' => $request->user()->name,
            'last_name' => '',
            'email' => $request->user()->email,
        ]);
        $sample->id = 0; // never persisted; the opt-out link is only a sample

        $rendered = $this->campaigns->render($campaign, $location, $sample);

        Mail::to($request->user()->email)->queue(new TemplatedMail(
            '[Test] '.$rendered['subject'],
            $rendered['body'],
            $location->tenant->mail_from_name,
            $location->tenant->mail_reply_to,
        ));

        return back()->with('success', __('Testmail an :mail verschickt.', ['mail' => $request->user()->email]));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?MarketingCampaign $campaign = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:4000'],
            'offset_days' => ['required', 'integer', 'min:0', 'max:730'],
            'min_visits' => ['required', 'integer', 'min:0', 'max:100'],
        ];

        // The type decides the whole matching rule – changing it later would
        // turn a birthday campaign into something else behind everyone's back.
        if ($campaign === null) {
            $rules['type'] = ['required', Rule::in(MarketingCampaign::TYPES)];
        }

        return $request->validate($rules);
    }

    private function authorizeCampaign(MarketingCampaign $campaign): void
    {
        abort_if($campaign->tenant_id !== $this->context->tenantId(), 404);
        abort_if($campaign->location_id !== $this->context->locationId(), 404);
    }
}
