<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings\Concerns;

use Illuminate\Http\Request;

/**
 * Antworten der Einstellungsformulare: per fetch als JSON („message“, bei
 * Ablehnung zusätzlich „errors“ mit Status 422), sonst zurück zur Seite.
 */
trait RespondsToSettingsForms
{
    protected function saved(Request $request, string $message, bool $reload = false): mixed
    {
        if ($request->wantsJson()) {
            $data = ['message' => $message];
            if ($reload) {
                $data['reload'] = true;
            }

            return response()->json($data);
        }

        return back()->with('success', $message);
    }

    protected function failed(Request $request, string $field, string $message): mixed
    {
        return $this->rejected($request, [$field => $message]);
    }

    /**
     * Ablehnung in derselben Form wie ein Prüfungsfehler von Laravel.
     *
     * @param  array<string, string>  $errors  Feld => Meldung
     */
    protected function rejected(Request $request, array $errors): mixed
    {
        if ($request->wantsJson()) {
            return response()->json([
                'message' => implode(' ', $errors),
                'errors' => array_map(fn (string $message) => [$message], $errors),
            ], 422);
        }

        return back()->withErrors($errors);
    }
}
