<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\ContactRequestMail;
use App\Models\Plan;
use App\Services\LegalDocumentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class MarketingController extends Controller
{
    public function __construct(private readonly LegalDocumentStatus $rechtstexte) {}

    public function home()
    {
        $plans = Plan::where('is_active', true)
            ->where('key', '!=', 'trial')
            ->orderBy('sort_order')
            ->get();

        return view('marketing.home', compact('plans'));
    }

    public function imprint()
    {
        return $this->legalDocument('impressum');
    }

    public function privacy()
    {
        return $this->legalDocument('datenschutz');
    }

    public function terms()
    {
        return $this->legalDocument('agb');
    }

    /**
     * Render a legal document from its Markdown source. Read fresh on every
     * request (storage/app/private/legal/<key>.md) so edits take effect without a
     * restart. Falls back to the shipped template if the file is missing.
     *
     * Gelesen wird über LegalDocumentStatus – derselbe Weg, auf dem auch die
     * Vollständigkeitsprüfung schaut. Zwei Lesepfade würden früher oder später
     * auseinanderlaufen und den Hinweis auf einer Seite anzeigen, die längst
     * gepflegt ist (oder umgekehrt).
     */
    private function legalDocument(string $key)
    {
        $titles = config('swayy.legal.documents');
        abort_unless(isset($titles[$key]), 404);

        return view('marketing.legal.document', [
            'title' => $titles[$key],
            'html' => Str::markdown($this->rechtstexte->markdown($key)),
        ]);
    }

    public function contact()
    {
        return view('marketing.contact');
    }

    public function sendContact(Request $request)
    {
        if ($request->filled('website')) {
            abort(422); // honeypot
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        Mail::to(config('services.support_email') ?: config('mail.from.address'))
            ->send(new ContactRequestMail($validated['name'], $validated['email'], $validated['message']));

        return back()->with('success', __('Vielen Dank! Wir melden uns so schnell wie möglich.'));
    }
}
