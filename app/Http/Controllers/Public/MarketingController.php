<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\ContactRequestMail;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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
        return view('marketing.legal.imprint');
    }

    public function privacy()
    {
        return view('marketing.legal.privacy');
    }

    public function terms()
    {
        return view('marketing.legal.terms');
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
