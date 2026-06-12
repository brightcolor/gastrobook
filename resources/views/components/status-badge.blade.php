@props(['status'])
@php
    $value = $status instanceof \App\Enums\ReservationStatus ? $status->value : $status;
    $colors = [
        'confirmed' => 'bg-emerald-100 text-emerald-800',
        'requested' => 'bg-amber-100 text-amber-800',
        'pending_confirmation' => 'bg-amber-100 text-amber-800',
        'seated' => 'bg-blue-100 text-blue-800',
        'partially_arrived' => 'bg-blue-50 text-blue-700',
        'completed' => 'bg-stone-100 text-stone-600',
        'cancelled_by_guest' => 'bg-red-50 text-red-600',
        'cancelled_by_restaurant' => 'bg-red-50 text-red-600',
        'rejected' => 'bg-red-100 text-red-700',
        'no_show' => 'bg-red-100 text-red-800',
        'waitlisted' => 'bg-purple-100 text-purple-800',
        'waitlist_offered' => 'bg-purple-100 text-purple-800',
        'expired' => 'bg-stone-100 text-stone-500',
        'payment_pending' => 'bg-orange-100 text-orange-800',
        'payment_failed' => 'bg-red-100 text-red-800',
        'draft' => 'bg-stone-100 text-stone-500',
    ];
@endphp
<span class="inline-block whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $colors[$value] ?? 'bg-stone-100 text-stone-600' }}">
    {{ __('reservations.status.' . $value) }}
</span>
