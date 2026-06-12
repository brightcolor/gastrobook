<?php

use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EventAdminController;
use App\Http\Controllers\Admin\FloorPlanController;
use App\Http\Controllers\Admin\GuestController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReservationBookController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\WaitlistAdminController;
use App\Http\Controllers\Admin\WalkInController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Public\FeedbackController;
use App\Http\Controllers\Public\MarketingController;
use App\Http\Controllers\Public\PaymentController;
use App\Http\Controllers\Public\PublicBookingController;
use App\Http\Controllers\Public\PublicEventController;
use App\Http\Controllers\Public\WaitlistResponseController;
use App\Http\Controllers\Saas\SaasTenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Marketing site
|--------------------------------------------------------------------------
*/
Route::get('/', [MarketingController::class, 'home'])->name('home');
Route::get('/preise', fn () => redirect(route('home').'#preise'))->name('pricing');
Route::get('/impressum', [MarketingController::class, 'imprint'])->name('legal.imprint');
Route::get('/datenschutz', [MarketingController::class, 'privacy'])->name('legal.privacy');
Route::get('/agb', [MarketingController::class, 'terms'])->name('legal.terms');
Route::get('/kontakt', [MarketingController::class, 'contact'])->name('contact');
Route::post('/kontakt', [MarketingController::class, 'sendContact'])
    ->middleware('throttle:5,10')->name('contact.send');

/*
|--------------------------------------------------------------------------
| Public booking (guest-facing, no auth)
|--------------------------------------------------------------------------
*/
Route::middleware('throttle:booking-slots')->group(function () {
    Route::get('/book/{tenantSlug}/{locationSlug}', [PublicBookingController::class, 'show'])->name('booking.show');
    Route::get('/r/{tenantSlug}/{locationSlug}', [PublicBookingController::class, 'show']);
    Route::get('/book/{tenantSlug}/{locationSlug}/slots', [PublicBookingController::class, 'slots'])->name('booking.slots');
    Route::get('/embed/{tenantSlug}/{locationSlug}.js', [PublicBookingController::class, 'embedScript'])->name('booking.embed');
    Route::get('/book/{tenantSlug}/{locationSlug}/events', [PublicEventController::class, 'index'])->name('events.index');
    Route::get('/book/{tenantSlug}/{locationSlug}/events/{eventSlug}', [PublicEventController::class, 'show'])->name('events.show');
});

Route::middleware('throttle:booking')->group(function () {
    Route::post('/book/{tenantSlug}/{locationSlug}', [PublicBookingController::class, 'store'])->name('booking.store');
    Route::post('/book/{tenantSlug}/{locationSlug}/waitlist', [PublicBookingController::class, 'joinWaitlist'])->name('booking.waitlist');
    Route::post('/book/{tenantSlug}/{locationSlug}/events/{eventSlug}', [PublicEventController::class, 'store'])->name('events.store');
});

Route::get('/event-booking/{code}/{token}', [PublicEventController::class, 'manage'])->name('events.manage');
Route::post('/event-booking/{code}/{token}/cancel', [PublicEventController::class, 'cancel'])->name('events.cancel');

// Payments (Stripe Checkout + Webhook)
Route::get('/pay/event/{code}/{token}', [PaymentController::class, 'checkoutEventBooking'])
    ->middleware('throttle:booking')->name('pay.event');
Route::get('/pay/reservation/{code}/{token}', [PaymentController::class, 'checkoutReservation'])
    ->middleware('throttle:booking')->name('pay.reservation');
Route::post('/webhooks/stripe', [PaymentController::class, 'stripeWebhook'])->name('webhooks.stripe');

Route::get('/reservation/{code}/confirmed/{token}', [PublicBookingController::class, 'confirmation'])->name('booking.confirmation');
Route::get('/reservation/{code}/manage/{token}', [PublicBookingController::class, 'manage'])->name('booking.manage');
Route::post('/reservation/{code}/cancel/{token}', [PublicBookingController::class, 'cancel'])->name('booking.cancel');

Route::get('/feedback/{token}', [FeedbackController::class, 'show'])->name('feedback.show');
Route::post('/feedback/{token}', [FeedbackController::class, 'store'])->name('feedback.store');

Route::get('/waitlist/{entry}/{token}', [WaitlistResponseController::class, 'show'])->name('waitlist.respond');
Route::post('/waitlist/{entry}/{token}', [WaitlistResponseController::class, 'respond'])->name('waitlist.respond.post');

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [RegistrationController::class, 'show'])->name('register');
    Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:5,10');
    Route::get('/invitation/{token}', [InvitationController::class, 'show'])->name('invitation.accept');
    Route::post('/invitation/{token}', [InvitationController::class, 'accept'])->name('invitation.accept.post');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

/*
|--------------------------------------------------------------------------
| Admin (tenant context)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'tenant'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::post('/switch-tenant', [AuthController::class, 'switchTenant'])->name('switch-tenant');
    Route::post('/switch-location', [AuthController::class, 'switchLocation'])->name('switch-location');

    // Reservation book
    Route::middleware('permission:reservations.view')->group(function () {
        Route::get('/reservations', [ReservationBookController::class, 'index'])->name('reservations.index');
        Route::get('/reservations/export', [ReservationBookController::class, 'export'])
            ->middleware('permission:reports.view')->name('reservations.export');
        Route::get('/reservations/create', [ReservationBookController::class, 'create'])
            ->middleware('permission:reservations.create')->name('reservations.create');
        Route::post('/reservations', [ReservationBookController::class, 'store'])
            ->middleware('permission:reservations.create')->name('reservations.store');
        Route::get('/reservations/{reservation}', [ReservationBookController::class, 'show'])->name('reservations.show');
        Route::post('/reservations/{reservation}/transition', [ReservationBookController::class, 'transition'])->name('reservations.transition');
        Route::post('/reservations/{reservation}/tables', [ReservationBookController::class, 'moveTable'])
            ->middleware('permission:tables.assign')->name('reservations.tables');
    });

    // Floor plan
    Route::middleware('permission:reservations.view')->group(function () {
        Route::get('/floorplan', [FloorPlanController::class, 'index'])->name('floorplan.index');
        Route::get('/floorplan/state', [FloorPlanController::class, 'state'])->name('floorplan.state');
        Route::post('/floorplan/positions', [FloorPlanController::class, 'updatePositions'])
            ->middleware('permission:floorplan.update')->name('floorplan.positions');
    });

    // Walk-ins
    Route::middleware('permission:walkins.create')->group(function () {
        Route::get('/walkins', [WalkInController::class, 'index'])->name('walkins.index');
        Route::post('/walkins', [WalkInController::class, 'store'])->name('walkins.store');
    });

    // Waitlist
    Route::middleware('permission:waitlist.manage')->group(function () {
        Route::get('/waitlist', [WaitlistAdminController::class, 'index'])->name('waitlist.index');
        Route::post('/waitlist', [WaitlistAdminController::class, 'store'])->name('waitlist.store');
        Route::post('/waitlist/{entry}/offer', [WaitlistAdminController::class, 'offer'])->name('waitlist.offer');
        Route::post('/waitlist/{entry}/seat', [WaitlistAdminController::class, 'seat'])->name('waitlist.seat');
        Route::post('/waitlist/{entry}/cancel', [WaitlistAdminController::class, 'cancel'])->name('waitlist.cancel');
    });

    // Guests
    Route::middleware('permission:guests.view')->group(function () {
        Route::get('/guests', [GuestController::class, 'index'])->name('guests.index');
        Route::get('/guests/suggest', [GuestController::class, 'suggest'])->name('guests.suggest');
        Route::get('/guests/export', [GuestController::class, 'export'])
            ->middleware('permission:guests.export')->name('guests.export');
        Route::get('/guests/{guest}', [GuestController::class, 'show'])->name('guests.show');
        Route::put('/guests/{guest}', [GuestController::class, 'update'])
            ->middleware('permission:guests.update')->name('guests.update');
        Route::post('/guests/{guest}/notes', [GuestController::class, 'addNote'])
            ->middleware('permission:guests.update')->name('guests.notes');
        Route::get('/guests/{guest}/export', [GuestController::class, 'exportSingle'])
            ->middleware('permission:guests.export')->name('guests.export-single');
        Route::post('/guests/{guest}/anonymize', [GuestController::class, 'anonymize'])
            ->middleware('permission:guests.anonymize')->name('guests.anonymize');
    });

    // Events
    Route::middleware('permission:events.manage')->group(function () {
        Route::get('/events', [EventAdminController::class, 'index'])->name('events.index');
        Route::post('/events', [EventAdminController::class, 'store'])->name('events.store');
        Route::get('/events/{event}', [EventAdminController::class, 'show'])->name('events.show');
        Route::put('/events/{event}/status', [EventAdminController::class, 'updateStatus'])->name('events.status');
        Route::get('/events/{event}/attendees.csv', [EventAdminController::class, 'exportAttendees'])->name('events.attendees');
        Route::post('/event-bookings/{booking}/check-in', [EventAdminController::class, 'checkIn'])->name('events.check-in');
        Route::post('/event-bookings/{booking}/cancel', [EventAdminController::class, 'cancelBooking'])->name('events.cancel-booking');
    });

    // Reports
    Route::get('/reports', [ReportController::class, 'index'])
        ->middleware('permission:reports.view')->name('reports.index');

    // Settings (rooms, tables, hours, booking rules)
    Route::middleware('permission:tables.manage')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings/booking-rules', [SettingsController::class, 'updateBookingRules'])
            ->middleware('permission:opening_hours.manage')->name('settings.booking-rules');
        Route::post('/settings/rooms', [SettingsController::class, 'storeRoom'])
            ->middleware('permission:rooms.manage')->name('settings.rooms.store');
        Route::post('/settings/tables', [SettingsController::class, 'storeTable'])->name('settings.tables.store');
        Route::delete('/settings/tables/{table}', [SettingsController::class, 'deleteTable'])->name('settings.tables.delete');
        Route::post('/settings/combinations', [SettingsController::class, 'storeCombination'])->name('settings.combinations.store');
        Route::put('/settings/field-rules', [SettingsController::class, 'updateFieldRules'])
            ->middleware('permission:tenant.settings.manage')->name('settings.field-rules');
        Route::put('/settings/mailwizz', [SettingsController::class, 'updateMailwizz'])
            ->middleware('permission:integrations.manage')->name('settings.mailwizz');
        Route::put('/settings/stripe', [SettingsController::class, 'updateStripe'])
            ->middleware('permission:integrations.manage')->name('settings.stripe');
        Route::post('/settings/deposit-rules', [SettingsController::class, 'storeDepositRule'])
            ->middleware('permission:payments.manage')->name('settings.deposit-rules.store');
        Route::delete('/settings/deposit-rules/{rule}', [SettingsController::class, 'deleteDepositRule'])
            ->middleware('permission:payments.manage')->name('settings.deposit-rules.delete');
        Route::put('/settings/opening-hours', [SettingsController::class, 'updateOpeningHours'])
            ->middleware('permission:opening_hours.manage')->name('settings.opening-hours');
        Route::post('/settings/special-hours', [SettingsController::class, 'storeSpecialHours'])
            ->middleware('permission:special_hours.manage')->name('settings.special-hours');
    });

    // Users & roles
    Route::middleware('permission:users.invite')->group(function () {
        Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
        Route::post('/users/invite', [UserManagementController::class, 'invite'])->name('users.invite');
        Route::put('/users/{membership}/role', [UserManagementController::class, 'updateRole'])
            ->middleware('permission:users.roles.manage')->name('users.role');
        Route::delete('/users/{membership}', [UserManagementController::class, 'remove'])
            ->middleware('permission:users.roles.manage')->name('users.remove');
    });

    // API tokens
    Route::middleware('permission:api_tokens.manage')->group(function () {
        Route::get('/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
        Route::post('/api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
        Route::delete('/api-tokens/{tokenId}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');
    });

    // Audit log
    Route::get('/audit', [AuditLogController::class, 'index'])
        ->middleware('permission:audit.view')->name('audit.index');
});

/*
|--------------------------------------------------------------------------
| SaaS admin
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->prefix('saas')->name('saas.')->group(function () {
    Route::get('/tenants', [SaasTenantController::class, 'index'])->name('tenants.index');
    Route::post('/tenants', [SaasTenantController::class, 'store'])->name('tenants.store');
    Route::put('/tenants/{tenant}/status', [SaasTenantController::class, 'updateStatus'])->name('tenants.status');
    Route::put('/tenants/{tenant}/plan', [SaasTenantController::class, 'updatePlan'])->name('tenants.plan');
    Route::post('/tenants/{tenant}/impersonate', [SaasTenantController::class, 'impersonate'])->name('tenants.impersonate');
    Route::post('/stop-impersonation', [SaasTenantController::class, 'stopImpersonation'])->name('stop-impersonation');
});
