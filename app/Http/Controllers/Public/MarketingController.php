<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\ContactRequestMail;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MarketingController extends Controller
{
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
     * request (storage/app/legal/<key>.md) so edits take effect without a
     * restart. Falls back to the shipped template if the file is missing.
     */
    private function legalDocument(string $key)
    {
        $titles = config('gastrobook.legal.documents');
        abort_unless(isset($titles[$key]), 404);

        $disk = Storage::disk('local');
        $path = "legal/{$key}.md";

        if ($disk->exists($path)) {
            $markdown = (string) $disk->get($path);
        } else {
            $fallback = resource_path("legal/{$key}.md");
            $markdown = is_file($fallback) ? (string) file_get_contents($fallback) : '';
        }

        return view('marketing.legal.document', [
            'title' => $titles[$key],
            'html' => Str::markdown($markdown),
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
