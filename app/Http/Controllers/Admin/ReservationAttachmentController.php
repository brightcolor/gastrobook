<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationAttachment;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files on a reservation. The table existed from the start with no code
 * behind it – this is the way in and out.
 */
class ReservationAttachmentController extends Controller
{
    /** Deliberately narrow: no SVG (script carrier), no office macros. */
    private const ALLOWED_MIMES = 'pdf,jpg,jpeg,png,webp,heic';

    private const MAX_KILOBYTES = 8192;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Request $request, Reservation $reservation)
    {
        $this->authorizeReservation($reservation);

        $request->validate([
            'file' => ['required', 'file', 'mimes:'.self::ALLOWED_MIMES, 'max:'.self::MAX_KILOBYTES],
        ]);

        $file = $request->file('file');
        // Private disk + generated name: the original name is only ever used
        // as the download name, never as a path.
        $path = $file->store('reservation-attachments/'.$reservation->tenant_id.'/'.$reservation->id, 'local');

        $attachment = ReservationAttachment::create([
            'tenant_id' => $reservation->tenant_id,
            'reservation_id' => $reservation->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'uploaded_by' => $request->user()?->id,
        ]);

        $this->audit->log('reservation.attachment_added', $reservation, null, [
            'attachment_id' => $attachment->id,
            'name' => $attachment->original_name,
        ]);

        return back()->with('success', __('Datei angehängt.'));
    }

    public function download(Reservation $reservation, ReservationAttachment $attachment): StreamedResponse
    {
        $this->authorizeReservation($reservation);
        abort_if($attachment->reservation_id !== $reservation->id, 404);

        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }

    public function destroy(Reservation $reservation, ReservationAttachment $attachment)
    {
        $this->authorizeReservation($reservation);
        abort_if($attachment->reservation_id !== $reservation->id, 404);

        Storage::disk($attachment->disk)->delete($attachment->path);
        $this->audit->log('reservation.attachment_deleted', $reservation, [
            'name' => $attachment->original_name,
        ]);
        $attachment->delete();

        return back()->with('success', __('Datei gelöscht.'));
    }

    /**
     * Wie im ReservationBookController: Mandant UND Standort pruefen. Ein
     * Benutzer, der nur fuer einen Standort freigeschaltet ist, kommt sonst
     * ueber die Anhang-Route an Dateien des anderen Standorts – die
     * permission-Middleware prueft nur den AKTIVEN Standort, nicht den der
     * Reservierung.
     */
    private function authorizeReservation(Reservation $reservation): void
    {
        abort_if($reservation->tenant_id !== $this->context->tenantId(), 404);

        $location = $this->context->location();
        abort_if($location !== null && $reservation->location_id !== $location->id
            && ! request()->user()->canAccessLocation($this->context->tenant(), $reservation->location), 403);
    }
}
