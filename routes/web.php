<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BillingRequestController;
use App\Http\Controllers\Admin\BoardController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DirectDebitController;
use App\Http\Controllers\Admin\EventAdminController;
use App\Http\Controllers\Admin\FloorPlanController;
use App\Http\Controllers\Admin\FloorZoneController;
use App\Http\Controllers\Admin\GuestController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\NotificationTemplateController;
use App\Http\Controllers\Admin\OnboardingController;
use App\Http\Controllers\Admin\RefundController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReservationBookController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StaffMemberController;
use App\Http\Controllers\Admin\TagController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\WaitlistAdminController;
use App\Http\Controllers\Admin\WalkInController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\GoCardlessWebhookController;
use App\Http\Controllers\Public\FeedbackController;
use App\Http\Controllers\Public\GuestPortalController;
use App\Http\Controllers\Public\MarketingController;
use App\Http\Controllers\Public\PaymentController;
use App\Http\Controllers\Public\PublicBookingController;
use App\Http\Controllers\Public\PublicEventController;
use App\Http\Controllers\Public\WaitlistResponseController;
use App\Http\Controllers\Saas\SaasTenantController;
use App\Http\Controllers\Saas\SaasUserController;
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
    Route::get('/brand/{tenantSlug}/logo', [PublicBookingController::class, 'tenantLogo'])->name('brand.tenant.logo');
    Route::get('/brand/{tenantSlug}/{locationSlug}/logo', [PublicBookingController::class, 'locationLogo'])->name('brand.location.logo');
    Route::get('/book/{tenantSlug}', [PublicBookingController::class, 'landing'])->name('booking.landing');
    Route::get('/book/{tenantSlug}/{locationSlug}', [PublicBookingController::class, 'show'])->name('booking.show');
    Route::get('/r/{tenantSlug}/{locationSlug}', [PublicBookingController::class, 'show']);
    Route::get('/book/{tenantSlug}/{locationSlug}/slots', [PublicBookingController::class, 'slots'])->name('booking.slots');
    Route::get('/book/{tenantSlug}/{locationSlug}/floorplan', [PublicBookingController::class, 'floorplan'])->name('booking.floorplan');
    Route::get('/embed/{tenantSlug}/{locationSlug}.js', [PublicBookingController::class, 'embedScript'])->name('booking.embed');
    Route::get('/embed/{tenantSlug}.js', [PublicBookingController::class, 'embedScriptSingle'])->name('booking.embed.single');
    Route::get('/widget/{tenantSlug}/{locationSlug}/popup.js', [PublicBookingController::class, 'popupScript'])->name('booking.widget.popup');
    Route::get('/widget/{tenantSlug}/popup.js', [PublicBookingController::class, 'popupScriptSingle'])->name('booking.widget.popup.single');
    Route::get('/book/{tenantSlug}/{locationSlug}/events', [PublicEventController::class, 'index'])->name('events.index');
    Route::get('/book/{tenantSlug}/{locationSlug}/events/{eventSlug}', [PublicEventController::class, 'show'])->name('events.show');
});

Route::middleware('throttle:booking')->group(function () {
    Route::post('/book/{tenantSlug}', [PublicBookingController::class, 'storeLanding'])->name('booking.store.landing');
    Route::post('/book/{tenantSlug}/{locationSlug}', [PublicBookingController::class, 'store'])->name('booking.store');
    Route::post('/book/{tenantSlug}/{locationSlug}/waitlist', [PublicBookingController::class, 'joinWaitlist'])->name('booking.waitlist');
    Route::post('/book/{tenantSlug}/{locationSlug}/events/{eventSlug}', [PublicEventController::class, 'store'])->name('events.store');
});

Route::get('/event-booking/{code}/{token}', [PublicEventController::class, 'manage'])
    ->middleware('throttle:booking-slots')->name('events.manage');
Route::post('/event-booking/{code}/{token}/cancel', [PublicEventController::class, 'cancel'])
    ->middleware('throttle:booking-slots')->name('events.cancel');

// Payments (Stripe Checkout + Webhook)
Route::get('/pay/event/{code}/{token}', [PaymentController::class, 'checkoutEventBooking'])
    ->middleware('throttle:booking')->name('pay.event');
Route::get('/pay/reservation/{code}/{token}', [PaymentController::class, 'checkoutReservation'])
    ->middleware('throttle:booking')->name('pay.reservation');
Route::get('/pay/paypal/return/{intent}', [PaymentController::class, 'paypalReturn'])
    ->middleware('throttle:booking')->name('pay.paypal.return');
Route::post('/webhooks/stripe', [PaymentController::class, 'stripeWebhook'])->name('webhooks.stripe');
Route::post('/webhooks/gocardless', [GoCardlessWebhookController::class, 'handle'])->name('webhooks.gocardless');

Route::get('/reservation/{code}/confirmed/{token}', [PublicBookingController::class, 'confirmation'])
    ->middleware('throttle:booking-slots')->name('booking.confirmation');
Route::get('/reservation/{code}/manage/{token}', [PublicBookingController::class, 'manage'])
    ->middleware('throttle:booking-slots')->name('booking.manage');
Route::post('/reservation/{code}/cancel/{token}', [PublicBookingController::class, 'cancel'])
    ->middleware('throttle:booking-slots')->name('booking.cancel');
Route::get('/reservation/{code}/reschedule/{token}', [PublicBookingController::class, 'rescheduleShow'])
    ->middleware('throttle:booking-slots')->name('booking.reschedule');
Route::post('/reservation/{code}/reschedule/{token}', [PublicBookingController::class, 'reschedule'])
    ->middleware('throttle:booking')->name('booking.reschedule.post');

// Guest account (passwordless magic link) + email confirmation
Route::get('/konto/verify/{token}', [GuestPortalController::class, 'verify'])->name('guest.verify');
Route::get('/konto/{tenantSlug}', [GuestPortalController::class, 'request'])->name('guest.portal.request');
Route::post('/konto/{tenantSlug}', [GuestPortalController::class, 'sendLink'])
    ->middleware('throttle:5,10')->name('guest.portal.link');
Route::get('/konto/{tenantSlug}/login/{token}', [GuestPortalController::class, 'login'])->name('guest.portal.login');
Route::get('/konto/{tenantSlug}/start', [GuestPortalController::class, 'dashboard'])->name('guest.portal.dashboard');
Route::post('/konto/{tenantSlug}/logout', [GuestPortalController::class, 'logout'])->name('guest.portal.logout');

Route::get('/feedback/{token}', [FeedbackController::class, 'show'])
    ->middleware('throttle:booking-slots')->name('feedback.show');
Route::post('/feedback/{token}', [FeedbackController::class, 'store'])
    ->middleware('throttle:booking-slots')->name('feedback.store');

Route::get('/waitlist/{entry}/{token}', [WaitlistResponseController::class, 'show'])
    ->middleware('throttle:booking-slots')->name('waitlist.respond');
Route::post('/waitlist/{entry}/{token}', [WaitlistResponseController::class, 'respond'])
    ->middleware('throttle:booking-slots')->name('waitlist.respond.post');

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
    Route::get('/passwort-vergessen', [PasswordResetController::class, 'showForgot'])->name('password.request');
    Route::post('/passwort-vergessen', [PasswordResetController::class, 'sendLink'])->name('password.email');
    Route::get('/passwort-reset/{token}', [PasswordResetController::class, 'showReset'])->name('password.reset');
    Route::post('/passwort-reset', [PasswordResetController::class, 'reset'])->name('password.update');
});

// Abmelde-Seite per URL erreichbar (auch wenn man durch eine alte Session auf
// /login zur Startseite umgeleitet wird) – zeigt einen Abmelden-Button.
Route::get('/abmelden', [AuthController::class, 'logoutConfirm'])->name('logout.confirm');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

/*
|--------------------------------------------------------------------------
| Admin (tenant context)
|--------------------------------------------------------------------------
*/
// E-mail confirmation link (no auth – customer clicks from their inbox)
Route::get('/billing/confirm/{token}', [BillingRequestController::class, 'confirm'])
    ->name('billing.confirm');

Route::middleware(['auth', 'tenant', 'license', 'trial'])->prefix('admin')->name('admin.')->group(function () {
    // Trial-expired screen + billing request form (accessible even when locked)
    Route::get('/trial/expired', [BillingRequestController::class, 'expired'])->name('trial.expired');
    Route::post('/trial/request', [BillingRequestController::class, 'store'])->name('trial.request');

    // Owner-only billing request management (SaaS admin – no tenant scope needed here)
    Route::get('/billing-requests', [BillingRequestController::class, 'index'])->name('billing-requests.index');
    Route::post('/billing-requests/{billingRequest}/activate', [BillingRequestController::class, 'activate'])->name('billing-requests.activate');
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->name('dashboard.stats');

    // Live operations board (staff)
    Route::middleware('permission:reservations.view')->group(function () {
        Route::get('/board', [BoardController::class, 'index'])->name('board');
        Route::get('/board/data', [BoardController::class, 'data'])->name('board.data');
        Route::get('/board/stream', [BoardController::class, 'stream'])->name('board.stream');
    });

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
        Route::post('/reservations/{reservation}/party', [ReservationBookController::class, 'updateParty'])
            ->middleware('permission:reservations.update')->name('reservations.party');
        Route::post('/reservations/{reservation}/tables', [ReservationBookController::class, 'moveTable'])
            ->middleware('permission:tables.assign')->name('reservations.tables');
        Route::post('/reservations/{reservation}/tags', [TagController::class, 'syncReservation'])
            ->middleware('permission:reservations.update')->name('reservations.tags');
    });

    // Tags (tenant-scoped)
    Route::middleware('permission:reservations.update')->group(function () {
        Route::get('/tags', [TagController::class, 'index'])->name('tags.index');
        Route::post('/tags', [TagController::class, 'store'])->name('tags.store');
        Route::put('/tags/{tag}', [TagController::class, 'update'])->name('tags.update');
        Route::delete('/tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');
    });

    // Floor plan
    Route::middleware('permission:reservations.view')->group(function () {
        Route::get('/floorplan', [FloorPlanController::class, 'index'])->name('floorplan.index');
        Route::get('/floorplan/state', [FloorPlanController::class, 'state'])->name('floorplan.state');
        Route::get('/floorplan/rooms/{room}/background', [FloorPlanController::class, 'background'])
            ->name('floorplan.background');
        Route::get('/floorplan/zones', [FloorZoneController::class, 'index'])->name('floorplan.zones.index');
        Route::middleware('permission:floorplan.update')->group(function () {
            Route::post('/floorplan/positions', [FloorPlanController::class, 'updatePositions'])
                ->name('floorplan.positions');
            Route::post('/floorplan/tables', [FloorPlanController::class, 'storeTable'])
                ->name('floorplan.tables.store');
            Route::put('/floorplan/tables/{table}', [FloorPlanController::class, 'updateTable'])
                ->name('floorplan.tables.update');
            Route::post('/floorplan/rooms/{room}/background', [FloorPlanController::class, 'uploadBackground'])
                ->name('floorplan.background.upload');
            Route::delete('/floorplan/rooms/{room}/background', [FloorPlanController::class, 'deleteBackground'])
                ->name('floorplan.background.delete');
            Route::patch('/floorplan/rooms/{room}/size', [FloorPlanController::class, 'updateRoomSize'])
                ->name('floorplan.rooms.size');
            Route::post('/floorplan/zones', [FloorZoneController::class, 'store'])->name('floorplan.zones.store');
            Route::put('/floorplan/zones/{zone}', [FloorZoneController::class, 'update'])->name('floorplan.zones.update');
            Route::delete('/floorplan/zones/{zone}', [FloorZoneController::class, 'destroy'])->name('floorplan.zones.destroy');
        });
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
        Route::put('/events/{event}', [EventAdminController::class, 'update'])->name('events.update');
        Route::put('/events/{event}/status', [EventAdminController::class, 'updateStatus'])->name('events.status');
        Route::get('/events/{event}/attendees.csv', [EventAdminController::class, 'exportAttendees'])->name('events.attendees');
        Route::post('/event-bookings/{booking}/check-in', [EventAdminController::class, 'checkIn'])->name('events.check-in');
        Route::post('/event-bookings/{booking}/cancel', [EventAdminController::class, 'cancelBooking'])->name('events.cancel-booking');
    });

    // Refunds
    Route::middleware('permission:payments.manage')->group(function () {
        Route::get('/refunds', [RefundController::class, 'index'])->name('refunds.index');
        Route::post('/refunds/{refund}/approve', [RefundController::class, 'approve'])->name('refunds.approve');
        Route::post('/refunds/{refund}/reject', [RefundController::class, 'reject'])->name('refunds.reject');
        Route::post('/refunds/{refund}/retry', [RefundController::class, 'retry'])->name('refunds.retry');
    });

    // Reports
    Route::get('/reports', [ReportController::class, 'index'])
        ->middleware('permission:reports.view')->name('reports.index');

    // Onboarding wizard (new tenants only)
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');

    // Billing / SEPA direct debit (tenant subscription)
    Route::middleware('permission:billing.manage')->group(function () {
        Route::get('/billing', [DirectDebitController::class, 'show'])->name('billing.show');
        Route::get('/billing/direct-debit/setup', [DirectDebitController::class, 'setup'])->name('billing.directdebit.setup');
        Route::get('/billing/direct-debit/complete', [DirectDebitController::class, 'complete'])->name('billing.directdebit.complete');
        Route::post('/billing/direct-debit/cancel', [DirectDebitController::class, 'cancel'])->name('billing.directdebit.cancel');
    });

    // Own account / danger zone
    Route::get('/account', [AccountController::class, 'show'])->name('account.show');
    Route::delete('/account', [AccountController::class, 'destroy'])->name('account.destroy');
    Route::delete('/account/tenant', [AccountController::class, 'destroyTenant'])->name('account.tenant.destroy');

    // Settings (rooms, tables, hours, booking rules)
    Route::middleware('permission:tables.manage')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings/booking-rules', [SettingsController::class, 'updateBookingRules'])
            ->middleware('permission:opening_hours.manage')->name('settings.booking-rules');
        Route::post('/settings/rooms', [SettingsController::class, 'storeRoom'])
            ->middleware('permission:rooms.manage')->name('settings.rooms.store');
        Route::put('/settings/rooms/{room}', [SettingsController::class, 'updateRoom'])
            ->middleware('permission:rooms.manage')->name('settings.rooms.update');
        Route::delete('/settings/rooms/{room}', [SettingsController::class, 'deleteRoom'])
            ->middleware('permission:rooms.manage')->name('settings.rooms.delete');
        Route::post('/settings/tables', [SettingsController::class, 'storeTable'])->name('settings.tables.store');
        Route::put('/settings/tables/{table}', [SettingsController::class, 'updateTable'])->name('settings.tables.update');
        Route::delete('/settings/tables/{table}', [SettingsController::class, 'deleteTable'])->name('settings.tables.delete');
        Route::post('/settings/combinations', [SettingsController::class, 'storeCombination'])->name('settings.combinations.store');
        Route::put('/settings/combinations/{combination}', [SettingsController::class, 'updateCombination'])->name('settings.combinations.update');
        Route::delete('/settings/combinations/{combination}', [SettingsController::class, 'deleteCombination'])->name('settings.combinations.delete');
        Route::put('/settings/field-rules', [SettingsController::class, 'updateFieldRules'])
            ->middleware('permission:tenant.settings.manage')->name('settings.field-rules');
        Route::post('/settings/logo', [SettingsController::class, 'uploadLogo'])
            ->middleware('permission:tenant.settings.manage')->name('settings.logo.upload');
        Route::delete('/settings/logo', [SettingsController::class, 'deleteLogo'])
            ->middleware('permission:tenant.settings.manage')->name('settings.logo.delete');
        Route::put('/settings/mailwizz', [SettingsController::class, 'updateMailwizz'])
            ->middleware('permission:integrations.manage')->name('settings.mailwizz');
        Route::put('/settings/stripe', [SettingsController::class, 'updateStripe'])
            ->middleware('permission:integrations.manage')->name('settings.stripe');
        Route::put('/settings/paypal', [SettingsController::class, 'updatePaypal'])
            ->middleware('permission:integrations.manage')->name('settings.paypal');
        Route::put('/settings/sms', [SettingsController::class, 'updateSms'])
            ->middleware('permission:integrations.manage')->name('settings.sms');
        Route::post('/settings/deposit-rules', [SettingsController::class, 'storeDepositRule'])
            ->middleware('permission:payments.manage')->name('settings.deposit-rules.store');
        Route::put('/settings/deposit-rules/{rule}', [SettingsController::class, 'updateDepositRule'])
            ->middleware('permission:payments.manage')->name('settings.deposit-rules.update');
        Route::delete('/settings/deposit-rules/{rule}', [SettingsController::class, 'deleteDepositRule'])
            ->middleware('permission:payments.manage')->name('settings.deposit-rules.delete');
        Route::put('/settings/opening-hours', [SettingsController::class, 'updateOpeningHours'])
            ->middleware('permission:opening_hours.manage')->name('settings.opening-hours');
        Route::post('/settings/special-hours', [SettingsController::class, 'storeSpecialHours'])
            ->middleware('permission:special_hours.manage')->name('settings.special-hours');
        Route::delete('/settings/special-hours/{special}', [SettingsController::class, 'deleteSpecialHours'])
            ->middleware('permission:special_hours.manage')->name('settings.special-hours.delete');
        Route::post('/settings/blackouts', [SettingsController::class, 'storeBlackout'])
            ->middleware('permission:blackouts.manage')->name('settings.blackouts.store');
        Route::delete('/settings/blackouts/{blackout}', [SettingsController::class, 'deleteBlackout'])
            ->middleware('permission:blackouts.manage')->name('settings.blackouts.delete');
        Route::put('/settings/general', [SettingsController::class, 'updateGeneral'])
            ->middleware('permission:tenant.settings.manage')->name('settings.general');
        Route::put('/settings/tenant-type', [SettingsController::class, 'updateTenantType'])
            ->middleware('permission:tenant.settings.manage')->name('settings.tenant-type');
        Route::put('/settings/branding', [SettingsController::class, 'updateBranding'])
            ->middleware('permission:tenant.settings.manage')->name('settings.branding');
    });

    // Salon: Leistungen
    Route::middleware(['permission:tables.manage'])->group(function () {
        Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
        Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
        Route::put('/services/{service}', [ServiceController::class, 'update'])->name('services.update');
        Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');
    });

    // Salon: Mitarbeiter
    Route::middleware(['permission:tables.manage'])->group(function () {
        Route::get('/staff', [StaffMemberController::class, 'index'])->name('staff.index');
        Route::post('/staff', [StaffMemberController::class, 'store'])->name('staff.store');
        Route::put('/staff/{member}', [StaffMemberController::class, 'update'])->name('staff.update');
        Route::delete('/staff/{member}', [StaffMemberController::class, 'destroy'])->name('staff.destroy');
        Route::put('/staff/{member}/working-hours', [StaffMemberController::class, 'updateWorkingHours'])->name('staff.working-hours');
        Route::post('/staff/{member}/absences', [StaffMemberController::class, 'storeAbsence'])->name('staff.absences.store');
        Route::delete('/staff/absences/{absence}', [StaffMemberController::class, 'deleteAbsence'])->name('staff.absences.destroy');
    });

    // Locations (multi-location management)
    Route::middleware('permission:locations.manage')->group(function () {
        Route::get('/locations', [LocationController::class, 'index'])->name('locations.index');
        Route::post('/locations', [LocationController::class, 'store'])->name('locations.store');
        Route::put('/locations/{location}', [LocationController::class, 'update'])->name('locations.update');
        Route::post('/locations/{location}/toggle-active', [LocationController::class, 'toggleActive'])->name('locations.toggle-active');
    });

    // Users & roles
    Route::middleware('permission:users.invite')->group(function () {
        Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
        Route::post('/users/invite', [UserManagementController::class, 'invite'])->name('users.invite');
        Route::put('/users/{membership}/role', [UserManagementController::class, 'updateRole'])
            ->middleware('permission:users.roles.manage')->name('users.role');
        Route::delete('/users/{membership}', [UserManagementController::class, 'remove'])
            ->middleware('permission:users.roles.manage')->name('users.remove');
        Route::delete('/users/{membership}/delete-account', [UserManagementController::class, 'deleteUser'])
            ->middleware('permission:users.roles.manage')->name('users.delete');
    });

    // API tokens
    Route::middleware('permission:api_tokens.manage')->group(function () {
        Route::get('/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
        Route::post('/api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
        Route::delete('/api-tokens/{tokenId}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');
    });

    // E-mail templates (per-tenant overrides of the built-in notification texts)
    Route::middleware('permission:templates.manage')->group(function () {
        Route::get('/templates', [NotificationTemplateController::class, 'index'])->name('templates.index');
        Route::put('/templates/{key}', [NotificationTemplateController::class, 'update'])->name('templates.update');
        Route::delete('/templates/{key}', [NotificationTemplateController::class, 'reset'])->name('templates.reset');
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
    Route::get('/', [SaasTenantController::class, 'dashboard'])->name('dashboard');
    Route::get('/tenants', [SaasTenantController::class, 'index'])->name('tenants.index');
    Route::post('/tenants', [SaasTenantController::class, 'store'])->name('tenants.store');
    Route::put('/tenants/{tenant}/status', [SaasTenantController::class, 'updateStatus'])->name('tenants.status');
    Route::put('/tenants/{tenant}/plan', [SaasTenantController::class, 'updatePlan'])->name('tenants.plan');
    Route::put('/tenants/{tenant}/trial', [SaasTenantController::class, 'extendTrial'])->name('tenants.trial');
    Route::post('/tenants/{tenant}/impersonate', [SaasTenantController::class, 'impersonate'])->name('tenants.impersonate');
    Route::post('/stop-impersonation', [SaasTenantController::class, 'stopImpersonation'])->name('stop-impersonation');

    // Platform user management
    Route::get('/users', [SaasUserController::class, 'index'])->name('users.index');
    Route::post('/users', [SaasUserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}/role', [SaasUserController::class, 'updateRole'])->name('users.role');
    Route::delete('/users/{user}', [SaasUserController::class, 'destroy'])->name('users.destroy');
});
