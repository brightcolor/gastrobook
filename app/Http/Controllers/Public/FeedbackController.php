<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\FeedbackRequest;
use App\Models\FeedbackResponse;
use App\Services\WebhookDispatchService;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    public function __construct(private readonly WebhookDispatchService $webhooks) {}

    public function show(string $token)
    {
        $feedbackRequest = FeedbackRequest::withoutGlobalScopes()->where('token', $token)->firstOrFail();
        $location = $feedbackRequest->reservation()->withoutGlobalScopes()->first()
            ?->location()->withoutGlobalScope('tenant')->first();

        if ($feedbackRequest->responded_at !== null) {
            return view('public.feedback-done', ['location' => $location]);
        }

        return view('public.feedback', [
            'feedbackRequest' => $feedbackRequest,
            'location' => $location,
        ]);
    }

    public function store(Request $request, string $token)
    {
        $feedbackRequest = FeedbackRequest::withoutGlobalScopes()->where('token', $token)->firstOrFail();

        if ($feedbackRequest->responded_at !== null) {
            abort(410);
        }

        $validated = $request->validate([
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $location = $feedbackRequest->reservation()->withoutGlobalScopes()->first()
            ?->location()->withoutGlobalScope('tenant')->first();
        $settings = $location?->effectiveSettings();

        $redirectExternal = $settings
            && $settings->feedback_external_url
            && (int) $validated['score'] >= $settings->feedback_redirect_min_score;

        FeedbackResponse::withoutGlobalScopes()->create([
            'tenant_id' => $feedbackRequest->tenant_id,
            'location_id' => $feedbackRequest->location_id,
            'feedback_request_id' => $feedbackRequest->id,
            'score' => (int) $validated['score'],
            'comment' => $validated['comment'] ?? null,
            'redirected_external' => (bool) $redirectExternal,
        ]);

        $feedbackRequest->update(['responded_at' => now()]);

        if ($location) {
            $this->webhooks->dispatch($location->tenant, 'feedback.received', [
                'score' => (int) $validated['score'],
                'location_id' => $location->id,
            ]);
        }

        // Positive feedback → external review portal; negative stays internal
        if ($redirectExternal) {
            return redirect()->away($settings->feedback_external_url);
        }

        return view('public.feedback-done', ['location' => $location]);
    }
}
