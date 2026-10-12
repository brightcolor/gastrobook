<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Prüfregeln und Meldungen für die Seite „Benachrichtigungen“. Die Grenzen
 * stehen in config/swayy.php (notifications.*).
 */
final class NotificationSettingRules
{
    /**
     * Die Schalter sind Pflicht: x-checkbox sendet ohne Haken eine 0.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        $limits = (array) config('swayy.notifications');
        $switch = ['required', 'boolean'];

        return [
            'guest_address' => ['required', 'in:du,Sie'],
            'reminder_enabled' => $switch,
            'reminder_hours_before' => ['required', 'integer', 'min:'.$limits['reminder_hours_min'], 'max:'.$limits['reminder_hours_max']],
            'sms_reminder_enabled' => $switch,
            'feedback_enabled' => $switch,
            'feedback_hours_after' => ['required', 'integer', 'min:'.$limits['feedback_hours_min'], 'max:'.$limits['feedback_hours_max']],
            'feedback_redirect_min_score' => ['required', 'integer', 'min:1', 'max:5'],
            'feedback_external_url' => ['nullable', 'string', 'max:'.$limits['feedback_url_max'], 'url:https'],
            'owner_notification_enabled' => $switch,
            'owner_notification_email' => ['nullable', 'email:rfc', 'max:'.$limits['owner_email_max']],
        ];
    }

    /**
     * Meldungen der Prüfung. Sie nennen die Einstellung und den nächsten
     * Schritt. Die Texte für required, boolean, in, integer und string
     * entstehen für jedes Feld der Regeln, damit kein Feld die allgemeine
     * Meldung von Laravel zeigt. Die Meldungen tragen den Feldnamen im
     * Schlüssel, weil sie mit denen der Buchungsregeln zusammengeführt werden
     * und ein Platzhalter dort auch fremde Felder träfe.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $messages = [
            'guest_address.required' => __('Bitte „du“ oder „Sie“ wählen.'),
            'guest_address.in' => __('Bitte „du“ oder „Sie“ wählen.'),
            'reminder_hours_before.required' => __('Bitte eintragen, wie viele Stunden vorher die Erinnerung kommt.'),
            'reminder_hours_before.integer' => __('Bitte die Stunden als ganze Zahl eintragen.'),
            'reminder_hours_before.min' => __('Die Erinnerung kommt frühestens :min Std. vorher. Bitte mindestens :min eintragen.'),
            'reminder_hours_before.max' => __('Die Erinnerung kommt höchstens :max Stunden vorher. Bitte höchstens :max eintragen.'),
            'feedback_hours_after.required' => __('Bitte eintragen, wie viele Stunden nach dem Besuch die Anfrage kommt.'),
            'feedback_hours_after.integer' => __('Bitte die Stunden als ganze Zahl eintragen.'),
            'feedback_hours_after.min' => __('Die Feedback-Anfrage kommt frühestens :min Std. nach dem Besuch. Bitte mindestens :min eintragen.'),
            'feedback_hours_after.max' => __('Die Feedback-Anfrage kommt höchstens :max Stunden nach dem Besuch. Bitte höchstens :max eintragen.'),
            'feedback_external_url.url' => __('Der Link zum Bewertungsportal muss mit https:// beginnen. Bitte den Link aus deinem Bewertungsportal kopieren.'),
            'feedback_external_url.max' => __('Der Link zum Bewertungsportal darf höchstens :max Zeichen haben. Bitte einen kürzeren Link verwenden, etwa den Kurzlink des Portals.'),
            'owner_notification_email.email' => __('Die Adresse ist keine gültige E-Mail-Adresse. Bitte so eintragen: team@dein-betrieb.de'),
            'owner_notification_email.max' => __('Die Adresse darf höchstens :max Zeichen haben.'),
        ];
        foreach (['required', 'integer', 'min', 'max'] as $rule) {
            $messages["feedback_redirect_min_score.{$rule}"] = __('Bitte eine Zahl von 1 bis 5 Sternen wählen.');
        }

        foreach (self::rules() as $field => $rules) {
            foreach ($rules as $rule) {
                $name = is_string($rule) ? strtok($rule, ':') : null;
                $text = match ($name) {
                    'required' => __('Bei „:attribute“ fehlt ein Wert. Bitte einen Wert eintragen oder wählen; steht das Feld nicht auf der Seite, die Seite neu laden.'),
                    'boolean' => __('Bitte „:attribute“ ein- oder ausschalten. Steht der Schalter nicht auf der Seite, die Seite neu laden.'),
                    'in' => __('Der Wert bei „:attribute“ steht nicht zur Auswahl. Bitte einen Wert aus der Liste wählen oder die Seite neu laden.'),
                    'integer' => __('Bei „:attribute“ bitte eine ganze Zahl eintragen.'),
                    'string' => __('Bei „:attribute“ bitte einen Text eintragen.'),
                    default => null,
                };
                if ($text !== null) {
                    $messages["{$field}.{$name}"] ??= $text;
                }
            }
        }

        return $messages;
    }

    /**
     * Feldnamen, wie sie auf der neuen Seite stehen.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'guest_address' => __('Anrede'),
            'reminder_enabled' => __('E-Mail-Erinnerung'),
            'reminder_hours_before' => __('Stunden vorher'),
            'sms_reminder_enabled' => __('SMS-Erinnerung'),
            'feedback_enabled' => __('Feedback-Anfrage'),
            'feedback_hours_after' => __('Stunden nach dem Besuch'),
            'feedback_redirect_min_score' => __('Ab Sternen zum Portal'),
            'feedback_external_url' => __('Link zum Bewertungsportal'),
            'owner_notification_enabled' => __('Info an den Betrieb'),
            'owner_notification_email' => __('Adresse für die Info'),
        ];
    }
}
