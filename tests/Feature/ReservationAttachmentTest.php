<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationAttachment;
use App\Services\GuestPrivacyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Files on a reservation. `reservation_attachments` sat in the schema from day
 * one without a single line of code behind it.
 */
class ReservationAttachmentTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function reservation(array $setup, ?int $guestId = null): Reservation
    {
        return Reservation::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'guest_id' => $guestId,
        ]);
    }

    public function test_a_file_can_be_uploaded_and_downloaded_again(): void
    {
        Storage::fake('local');

        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $reservation = $this->reservation($setup);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/reservations/'.$reservation->id.'/attachments', [
            'file' => UploadedFile::fake()->create('menue.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        $attachment = ReservationAttachment::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('menue.pdf', $attachment->original_name);
        $this->assertSame($reservation->id, $attachment->reservation_id);
        $this->assertSame($admin->id, $attachment->uploaded_by);
        Storage::disk('local')->assertExists($attachment->path);
        // Never on the public disk – these files are internal.
        $this->assertStringStartsWith('reservation-attachments/', $attachment->path);

        $this->actingAs($admin)
            ->get('/admin/reservations/'.$reservation->id.'/attachments/'.$attachment->id)
            ->assertOk()
            ->assertDownload('menue.pdf');
    }

    public function test_dangerous_file_types_are_refused(): void
    {
        Storage::fake('local');

        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $reservation = $this->reservation($setup);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/reservations/'.$reservation->id.'/attachments', [
            'file' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, ReservationAttachment::withoutGlobalScopes()->count());
    }

    public function test_deleting_removes_the_row_and_the_file(): void
    {
        Storage::fake('local');

        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $reservation = $this->reservation($setup);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/reservations/'.$reservation->id.'/attachments', [
            'file' => UploadedFile::fake()->image('tischplan.png'),
        ])->assertRedirect();

        $attachment = ReservationAttachment::withoutGlobalScopes()->firstOrFail();
        $path = $attachment->path;

        $this->actingAs($admin)
            ->delete('/admin/reservations/'.$reservation->id.'/attachments/'.$attachment->id)
            ->assertRedirect();

        $this->assertSame(0, ReservationAttachment::withoutGlobalScopes()->count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_read_only_users_cannot_upload(): void
    {
        Storage::fake('local');

        $setup = $this->createTenantSetup();
        $readonly = $this->createMember($setup['tenant'], 'readonly');
        $reservation = $this->reservation($setup);
        $this->clearTenantContext();

        $this->actingAs($readonly)->post('/admin/reservations/'.$reservation->id.'/attachments', [
            'file' => UploadedFile::fake()->create('menue.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
    }

    public function test_a_file_of_another_business_is_out_of_reach(): void
    {
        Storage::fake('local');

        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $foreignReservation = $this->reservation($other);
        $foreign = ReservationAttachment::create([
            'tenant_id' => $other['tenant']->id,
            'reservation_id' => $foreignReservation->id,
            'disk' => 'local',
            'path' => 'reservation-attachments/x/y.pdf',
            'original_name' => 'geheim.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->get('/admin/reservations/'.$foreignReservation->id.'/attachments/'.$foreign->id)
            ->assertNotFound();
    }

    /**
     * A menu agreement carries the guest's name – erasure has to take the file
     * with it, not just the profile.
     */
    public function test_anonymising_the_guest_deletes_the_files(): void
    {
        Storage::fake('local');

        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $guest = Guest::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $reservation = $this->reservation($setup, $guest->id);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/reservations/'.$reservation->id.'/attachments', [
            'file' => UploadedFile::fake()->create('vertrag.pdf', 50, 'application/pdf'),
        ])->assertRedirect();

        $path = ReservationAttachment::withoutGlobalScopes()->firstOrFail()->path;

        $this->clearTenantContext();
        app(GuestPrivacyService::class)->anonymize($guest->fresh());

        $this->assertSame(0, ReservationAttachment::withoutGlobalScopes()->count());
        Storage::disk('local')->assertMissing($path);
    }
}
