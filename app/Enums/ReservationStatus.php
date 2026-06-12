<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Draft = 'draft';
    case Requested = 'requested';
    case PendingConfirmation = 'pending_confirmation';
    case Confirmed = 'confirmed';
    case Seated = 'seated';
    case PartiallyArrived = 'partially_arrived';
    case Completed = 'completed';
    case CancelledByGuest = 'cancelled_by_guest';
    case CancelledByRestaurant = 'cancelled_by_restaurant';
    case Rejected = 'rejected';
    case NoShow = 'no_show';
    case Waitlisted = 'waitlisted';
    case WaitlistOffered = 'waitlist_offered';
    case Expired = 'expired';
    case PaymentPending = 'payment_pending';
    case PaymentFailed = 'payment_failed';

    /**
     * Statuses that occupy capacity (block tables / count covers).
     *
     * @return array<string>
     */
    public static function activeStatuses(): array
    {
        return [
            self::Requested->value,
            self::PendingConfirmation->value,
            self::Confirmed->value,
            self::Seated->value,
            self::PartiallyArrived->value,
            self::PaymentPending->value,
        ];
    }

    /**
     * Allowed transitions per status. Empty array = terminal.
     *
     * @return array<string, array<string>>
     */
    public static function transitions(): array
    {
        return [
            self::Draft->value => [self::Requested->value, self::Confirmed->value, self::CancelledByRestaurant->value],
            self::Requested->value => [self::Confirmed->value, self::Rejected->value, self::CancelledByGuest->value, self::CancelledByRestaurant->value, self::Waitlisted->value, self::PaymentPending->value, self::Expired->value],
            self::PendingConfirmation->value => [self::Confirmed->value, self::Rejected->value, self::CancelledByGuest->value, self::CancelledByRestaurant->value, self::Expired->value],
            self::PaymentPending->value => [self::Confirmed->value, self::PaymentFailed->value, self::CancelledByGuest->value, self::CancelledByRestaurant->value, self::Expired->value],
            self::PaymentFailed->value => [self::PaymentPending->value, self::Confirmed->value, self::CancelledByRestaurant->value, self::Expired->value],
            self::Confirmed->value => [self::Seated->value, self::PartiallyArrived->value, self::CancelledByGuest->value, self::CancelledByRestaurant->value, self::NoShow->value, self::Completed->value],
            self::PartiallyArrived->value => [self::Seated->value, self::Completed->value, self::NoShow->value],
            self::Seated->value => [self::Completed->value],
            self::Waitlisted->value => [self::WaitlistOffered->value, self::Confirmed->value, self::CancelledByGuest->value, self::Expired->value],
            self::WaitlistOffered->value => [self::Confirmed->value, self::Waitlisted->value, self::CancelledByGuest->value, self::Expired->value],
            self::Completed->value => [],
            self::CancelledByGuest->value => [],
            self::CancelledByRestaurant->value => [],
            self::Rejected->value => [],
            self::NoShow->value => [self::Completed->value], // correction: guest showed up after all
            self::Expired->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target->value, self::transitions()[$this->value] ?? [], true);
    }

    public function isActive(): bool
    {
        return in_array($this->value, self::activeStatuses(), true);
    }

    public function label(): string
    {
        return __('reservations.status.'.$this->value);
    }
}
