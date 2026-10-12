<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\TenantType;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationAttachment;
use App\Models\Tag;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Reservierungsliste am Smartphone: Jede Reservierung nimmt zwei Zeilen ein
 * (Name mit Kurzzeile und Status, darunter die Aktionen), die Seite scrollt
 * nicht seitlich, und „Alle auswählen“ steht über der Liste.
 */
class ReservationListLayoutTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     */
    private function reservationAt(array $setup, string $name = 'Testgast'): Reservation
    {
        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(19, 0);

        return Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 4,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => $name,
        ]);
    }

    private function tableBody(string $html): string
    {
        return mb_substr($html, (int) mb_strpos($html, '<tbody'));
    }

    public function test_each_row_carries_a_compact_line_for_small_screens(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $reservation = $this->reservationAt($setup);
        $reservation->tables()->attach($setup['tables'][1]->id);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/reservations?range=all')->assertOk()->getContent();
        $body = $this->tableBody($html);

        $this->assertStringContainsString('<p class="text-[0.8125rem] text-stone-600 md:hidden">', $body);
        $this->assertMatchesRegularExpression('/19:00–21:00 · 4 Pers\. · T2 · /', $body);
        $this->assertStringContainsString('md:min-w-[42rem]', $html);
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*(?<![:\w-])min-w-\[42rem\]/', $html);
        $this->assertSame(2, substr_count($html, 'class="bulk-all rounded"'));
    }

    public function test_the_compact_line_of_a_salon_appointment_leaves_out_the_missing_table(): void
    {
        $setup = $this->createTenantSetup([]);
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $setup['room']->delete();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->reservationAt($setup);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/reservations?range=all')->assertOk()->getContent();
        $body = $this->tableBody($html);

        $this->assertMatchesRegularExpression('/19:00–21:00 · 4 Pers\. · Online<\/p>/', $body);
        $this->assertStringNotContainsString('·  ·', $body);
        // Ohne Räume gibt es keinen Raumfilter, der nur „Alle“ anbietet.
        $this->assertStringNotContainsString('id="filterRoom"', $html);
    }

    public function test_the_room_filter_is_offered_when_the_location_has_rooms(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/reservations?range=all')
            ->assertOk()
            ->assertSee('id="filterRoom"', false)
            ->assertSee($setup['room']->name);
    }

    public function test_the_detail_page_keeps_one_fixed_column_on_phones(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $reservation = $this->reservationAt($setup);
        $this->clearTenantContext();

        // minmax(0,1fr): Ein langer Dateiname weitet die Spalte nicht über den Bildschirm.
        $this->actingAs($owner)->get('/admin/reservations/'.$reservation->id)
            ->assertOk()
            ->assertSee('class="grid grid-cols-[minmax(0,1fr)] gap-6 [overflow-wrap:anywhere] lg:grid-cols-3"', false);
    }

    public function test_the_actions_row_is_hidden_on_phones_when_nothing_can_be_done(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $reservation = $this->reservationAt($setup);
        $reservation->update(['status' => ReservationStatus::Completed]);
        $this->clearTenantContext();

        $body = $this->tableBody((string) $this->actingAs($owner)->get('/admin/reservations?range=all')->assertOk()->getContent());

        $this->assertSame(1, preg_match('/<td class="([^"]*)" role="cell">\s*<div class="flex flex-wrap gap-1\.5 md:flex-nowrap">\s*<\/div>\s*<\/td>/', $body, $cell));
        $this->assertStringContainsString('max-md:hidden', $cell[1]);
    }

    public function test_select_all_above_the_list_appears_only_when_there_is_something_to_select(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/reservations?range=all')->assertOk()->getContent();

        $this->assertStringNotContainsString('aria-controls="reservationList"', $html);
        $this->assertStringContainsString('Noch keine Reservierungen.', $html);
    }

    public function test_the_table_keeps_its_roles_when_phones_turn_it_into_blocks(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->reservationAt($setup);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/reservations?range=all')->assertOk()->getContent();

        $this->assertStringContainsString('<table id="reservationList" role="table"', $html);
        $this->assertStringContainsString('role="rowgroup"', $this->tableBody($html));
        $this->assertStringContainsString('<tr role="row"', $this->tableBody($html));
        $this->assertSame(9, substr_count($this->tableBody($html), 'role="cell"'));
    }

    public function test_filters_and_attachments_use_no_inline_handlers(): void
    {
        $index = (string) file_get_contents(resource_path('views/admin/reservations/index.blade.php'));
        $show = (string) file_get_contents(resource_path('views/admin/reservations/show.blade.php'));

        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $index);
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $show);
        $this->assertSame(3, substr_count($index, 'data-autosubmit'));
        $this->assertStringContainsString(':id="\'anhang-loeschen-\'.$attachment->id"', $show);
        // Gerade Anführungszeichen beendeten das Attribut data-tip mitten im Text.
        $this->assertStringNotContainsString('„Bestätigen"', $index);
        $this->assertStringNotContainsString('„Heute"', $index);
        $this->assertStringNotContainsString('„Von/Bis"', $index);
        $this->assertDoesNotMatchRegularExpression('/data-tip="[^"]*„[^“"]*"/u', $index);
    }

    public function test_the_filters_are_labelled_for_their_fields(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/reservations?range=all')->assertOk()->getContent();

        foreach (['status' => 'filterStatus', 'source' => 'filterSource', 'room_id' => 'filterRoom'] as $name => $id) {
            $this->assertMatchesRegularExpression('/<select name="'.$name.'" id="'.$id.'"[^>]* data-autosubmit>/', $html);
            $this->assertStringContainsString('<label for="'.$id.'"', $html);
        }
        $this->assertMatchesRegularExpression('/<input type="search" name="q" id="filterSearch"/', $html);
        $this->assertStringContainsString('<label for="filterSearch"', $html);
    }

    public function test_deleting_an_attachment_asks_in_the_row_and_posts_plainly(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $reservation = $this->reservationAt($setup);
        $attachment = ReservationAttachment::create([
            'tenant_id' => $setup['tenant']->id, 'reservation_id' => $reservation->id, 'disk' => 'local',
            'path' => 'reservation-attachments/x/menue.pdf', 'original_name' => 'Menü & Wein.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 1200,
        ]);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/reservations/'.$reservation->id)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<form id="anhang-loeschen-'.$attachment->id.'" data-inline-confirm-panel\s+hidden\s+method="POST" action="[^"]+\/attachments\/'.$attachment->id.'"\s*class=/', $html);
        $this->assertStringContainsString('name="_method" value="DELETE"', $html);
        $this->assertStringContainsString('data-inline-confirm-group', $html);
        $this->assertStringContainsString('„Menü &amp; Wein.pdf“ löschen? Die Datei lässt sich danach nicht wiederherstellen.', $html);
        $this->assertStringContainsString('Datei löschen</button>', $html);
        $this->assertDoesNotMatchRegularExpression('/<form id="anhang-loeschen-\d+"[^>]*data-ajax/', $html);
        $this->assertStringNotContainsString('onsubmit=', $html);
    }

    public function test_a_read_only_user_gets_no_delete_button_for_attachments(): void
    {
        $setup = $this->createTenantSetup();
        $viewer = $this->createMember($setup['tenant'], 'readonly');
        $reservation = $this->reservationAt($setup);
        ReservationAttachment::create([
            'tenant_id' => $setup['tenant']->id, 'reservation_id' => $reservation->id, 'disk' => 'local',
            'path' => 'reservation-attachments/x/menue.pdf', 'original_name' => 'menue.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 1200,
        ]);
        $this->clearTenantContext();

        $this->actingAs($viewer)->get('/admin/reservations/'.$reservation->id)
            ->assertOk()
            ->assertSee('menue.pdf')
            ->assertDontSee('anhang-loeschen-');
    }

    public function test_the_tag_name_field_takes_its_limit_from_the_settings(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $reservation = $this->reservationAt($setup);
        $this->clearTenantContext();

        $this->assertSame(40, config('swayy.ui.tag_name_max'));
        $this->actingAs($owner)->get('/admin/reservations/'.$reservation->id)
            ->assertOk()
            ->assertSee('id="tagNameInput" type="text" maxlength="40"', false);

        config(['swayy.ui.tag_name_max' => 12]);
        $this->actingAs($owner)->get('/admin/reservations/'.$reservation->id)
            ->assertOk()
            ->assertSee('id="tagNameInput" type="text" maxlength="12"', false)
            ->assertDontSee('maxlength="40"', false);

        config(['swayy.ui.tag_name_max' => 25]);
        $this->actingAs($owner)->get('/admin/reservations/'.$reservation->id)
            ->assertOk()
            ->assertSee('id="tagNameInput" type="text" maxlength="25"', false);
    }

    public function test_a_failed_tag_creation_is_reported_at_the_field(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $reservation = $this->reservationAt($setup);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/reservations/'.$reservation->id)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input id="tagNameInput"[^>]*aria-describedby="tagNameError"/', $html);
        // Fokus plus aria-describedby melden den Fehler; ein zusätzliches role="alert" ließe ihn doppelt vorlesen.
        $this->assertMatchesRegularExpression('/<p id="tagNameError" class="[^"]*"><\/p>/', $html);
        $this->assertMatchesRegularExpression('/<p id="tagNameNote" role="status" class="[^"]*"><\/p>/', $html);
        $this->assertStringContainsString('if (tag.message) nameNote.textContent = tag.message;', $html);
        $this->assertStringNotContainsString('if (!res.ok) return;', $html);
        $this->assertStringContainsString('aria-invalid', $html);
        // Die Rückfallmeldung nennt den nächsten Schritt.
        $this->assertStringContainsString('Der Tag konnte nicht angelegt werden. Bitte die Seite neu laden und es noch einmal versuchen.', $html);
    }

    public function test_a_failed_tag_save_is_reported_with_the_cause_and_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $reservation = $this->reservationAt($setup);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/reservations/'.$reservation->id)->assertOk()->getContent();

        // Die Meldung steht außerhalb der Auswahl und ist auch bei geschlossener Auswahl zu sehen (Entfernen mit ✕).
        $this->assertMatchesRegularExpression('/<p id="tagSyncError" role="alert" class="[^"]*"><\/p>.*?<div id="tagPicker"/s', $html);
        // Eine Antwort mit Fehler zeigt den Text der Antwort, ein Netzwerkfehler einen eigenen Satz mit dem nächsten Schritt.
        $this->assertStringContainsString('if (!res.ok) {', $html);
        $this->assertStringContainsString('syncFailed(failureText(res, await readJson(res), syncFailedText));', $html);
        $this->assertStringContainsString('syncFailed(syncNetworkText);', $html);
        // Das Skript bekommt die Texte als JSON; die Umlaute stehen dort maskiert.
        foreach (['Die Änderung konnte nicht gespeichert werden. Bitte die Verbindung prüfen und erneut versuchen.', 'Die Änderung konnte nicht gespeichert werden. Bitte die Seite neu laden und es noch einmal versuchen.'] as $text) {
            $this->assertStringContainsString(substr((string) json_encode($text), 1, -1), $html);
        }
        // Nach einem Fehler zeigt die Seite wieder den Stand, den der Server bestätigt hat.
        $this->assertStringContainsString('activeTags = [...savedTags];', $html);
    }

    public function test_the_remove_buttons_of_tags_are_named_and_large_enough(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $reservation = $this->reservationAt($setup);
        $tag = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'Hochzeit "groß"', 'color' => '#aa3300', 'scope' => 'reservation']);
        $reservation->tags()->attach($tag->id);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/reservations/'.$reservation->id)->assertOk()->getContent();

        $this->assertStringContainsString('<button type="button" aria-label="Tag „Hochzeit &quot;groß&quot;“ entfernen" class="tag-remove -my-1 -mr-1.5 inline-flex h-6 w-6 ', $html);
        // Die Schaltfläche, die das Skript baut, trägt Name und Mindestgröße ebenfalls, der Name geht maskiert hinein.
        $this->assertStringContainsString('`${esc(tag.name)} <button type="button" aria-label="Tag „${esc(tag.name)}“ entfernen" class="tag-remove -my-1 -mr-1.5 inline-flex h-6 w-6 ', $html);
    }

    public function test_the_markers_of_a_guest_in_the_list_carry_text_for_screen_readers(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $guest = Guest::create(['tenant_id' => $setup['tenant']->id, 'first_name' => 'Test', 'last_name' => 'Gast', 'is_vip' => true]);
        $reservation = $this->reservationAt($setup);
        $reservation->update(['guest_id' => $guest->id, 'allergy_note' => 'Nüsse <b>& Soja</b>']);
        $this->clearTenantContext();

        $body = $this->tableBody((string) $this->actingAs($owner)->get('/admin/reservations?range=all')->assertOk()->getContent());

        $this->assertStringContainsString('<span title="VIP"><span aria-hidden="true">⭐</span><span class="sr-only">VIP</span></span>', $body);
        $this->assertStringContainsString('<span aria-hidden="true">⚠️</span><span class="sr-only max-md:hidden">Allergien: Nüsse &lt;b&gt;&amp; Soja&lt;/b&gt;</span>', $body);
        // Unter 768 px steht der Text als sichtbare Zeile da (und der sr-only-Span ist ausgeblendet), ab 768 px umgekehrt.
        $this->assertStringContainsString('<p class="line-clamp-2 text-[0.8125rem] font-semibold text-red-700 md:hidden [overflow-wrap:anywhere]">Allergien: Nüsse &lt;b&gt;&amp; Soja&lt;/b&gt;</p>', $body);
        $this->assertSame(1, substr_count($body, 'text-red-700 md:hidden'));
    }

    public function test_only_rows_with_actions_span_two_grid_rows_on_phones(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $withActions = $this->reservationAt($setup, 'MitAktion');
        $without = $this->reservationAt($setup, 'OhneAktion');
        $without->update(['status' => ReservationStatus::Completed]);
        $this->clearTenantContext();

        $body = $this->tableBody((string) $this->actingAs($owner)->get('/admin/reservations?range=all')->assertOk()->getContent());

        $cellClass = function (Reservation $r) use ($body): string {
            $this->assertSame(1, preg_match('/<td class="([^"]*)" role="cell"><label[^>]*><input type="checkbox" form="bulkForm" name="ids\[\]" value="'.$r->id.'"/', $body, $m));

            return $m[1];
        };
        $this->assertStringContainsString('max-md:row-span-2', $cellClass($withActions));
        $this->assertStringNotContainsString('row-span-2', $cellClass($without));
    }

    public function test_the_selection_bar_catches_up_with_restored_checkboxes_at_load(): void
    {
        $index = (string) file_get_contents(resource_path('views/admin/reservations/index.blade.php'));

        // Nach „Zurück“ stellt der Browser angehakte Kästchen wieder her; sync() läuft deshalb einmal beim Laden.
        $this->assertMatchesRegularExpression('/bulkClear\'\)\.addEventListener[^\n]*\n[^\n]*\n\s*sync\(\);\n\}\)\(\);\n<\/script>/', $index);
    }

    public function test_an_empty_filtered_list_offers_to_reset_the_filters(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $filtered = $this->actingAs($owner)->get('/admin/reservations?range=all&status=confirmed')->assertOk();
        $filtered->assertSee('Mit den gewählten Filtern passt keine Reservierung.');
        // Ein Satz genügt: Die Meldung sagt nicht zweimal dasselbe.
        $filtered->assertDontSee('Keine Reservierungen gefunden', false);
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('admin.reservations.index'), '/').'" class="btn btn-secondary">Filter zurücksetzen<\/a>/', (string) $filtered->getContent());
        $filtered->assertDontSee('Noch keine Reservierungen.');
    }

    public function test_an_empty_book_offers_to_create_the_first_reservation(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/reservations?range=all')->assertOk()->getContent();

        $this->assertStringContainsString('<p>Noch keine Reservierungen.</p>', $html);
        // Am Smartphone füllt die Leerzeile die Breite (display: block), damit Satz und Knopf mittig stehen.
        $this->assertStringContainsString('<td colspan="9" role="cell" class="px-4 py-8 text-center text-stone-600 max-md:block">', $html);
        $this->assertStringNotContainsString('>Filter zurücksetzen<', $html);
        $this->assertStringNotContainsString('setze die Filter zurück', $html);
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('admin.reservations.create'), '/').'" class="btn btn-primary">\+ Neu<\/a>/', $html);
    }

    public function test_users_without_the_right_to_create_get_the_sentence_without_the_link(): void
    {
        $setup = $this->createTenantSetup();
        $viewer = $this->createMember($setup['tenant'], 'readonly');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($viewer)->get('/admin/reservations?range=all')->assertOk()->getContent();

        $this->assertStringContainsString('<p>Noch keine Reservierungen.</p>', $html);
        // Weder in der Leerzeile noch im Kopf führt ein Knopf zu einer Seite, die dieses Recht verlangt.
        $this->assertStringNotContainsString(route('admin.reservations.create'), $html);
        $this->assertStringNotContainsString('+ Neu', $html);
    }
}
