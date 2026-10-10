<?php

// Überschriften und Gründe im Fußbereich je Mailart (Spec Mail-Design).
// Platzhalter: :location (Standort), :tenant (Betrieb), :guest (Gast),
// :subject (Betreff ohne „– Swayy“). Gast-Mails wählen nach der Anrede des
// Standorts, Mails an Betriebe und Swayy-Mails nach swayy.mail.owner_address.
// Fehlt eine Mailart unter „reasons“, gilt operator (operator_*), swayy
// (swayy_*) oder guest.

return [
    'headlines' => [
        'reservation_confirmed' => ['du' => 'Deine Reservierung ist bestätigt', 'Sie' => 'Ihre Reservierung ist bestätigt'],
        'reservation_requested' => ['du' => 'Deine Anfrage ist angekommen', 'Sie' => 'Ihre Anfrage ist angekommen'],
        'reservation_cancelled' => ['du' => 'Deine Reservierung ist storniert', 'Sie' => 'Ihre Reservierung ist storniert'],
        'reservation_rejected' => ['du' => 'Antwort auf deine Anfrage', 'Sie' => 'Antwort auf Ihre Anfrage'],
        'reservation_reminder' => ['du' => 'Erinnerung an deine Reservierung', 'Sie' => 'Erinnerung an Ihre Reservierung'],
        'reservation_table_changed' => ['du' => 'Dein Tisch hat sich geändert', 'Sie' => 'Ihr Tisch hat sich geändert'],
        'feedback_request' => ['du' => 'Wie war dein Besuch?', 'Sie' => 'Wie war Ihr Besuch?'],
        'waitlist_offer' => ['du' => 'Ein Tisch ist frei geworden', 'Sie' => 'Ein Tisch ist frei geworden'],
        'payment_pending' => ['du' => 'Bitte schließ deine Zahlung ab', 'Sie' => 'Bitte schließen Sie Ihre Zahlung ab'],
        'payment_reminder' => ['du' => 'Deine Zahlung ist noch offen', 'Sie' => 'Ihre Zahlung ist noch offen'],
        'payment_expired' => ['du' => 'Die Zahlungsfrist ist abgelaufen', 'Sie' => 'Die Zahlungsfrist ist abgelaufen'],
        'event_booking_confirmed' => ['du' => 'Deine Buchung ist bestätigt', 'Sie' => 'Ihre Buchung ist bestätigt'],
        'guest_verify' => ['du' => 'Bitte bestätige deine E-Mail-Adresse', 'Sie' => 'Bitte bestätigen Sie Ihre E-Mail-Adresse'],
        'guest_login' => ['du' => 'Dein Anmeldelink', 'Sie' => 'Ihr Anmeldelink'],
        'operator_new_reservation' => ['du' => 'Neue Reservierung für :location', 'Sie' => 'Neue Reservierung für :location'],
        'operator_refund' => ['du' => ':subject', 'Sie' => ':subject'],
        'operator_guest_reply' => ['du' => 'Antwort von :guest', 'Sie' => 'Antwort von :guest'],
        'swayy_password_reset' => ['du' => 'Neues Passwort festlegen', 'Sie' => 'Neues Passwort festlegen'],
        'swayy_trial_expiry' => ['du' => 'Deine Testphase endet bald', 'Sie' => 'Ihre Testphase endet bald'],
        'swayy_billing_confirm' => ['du' => 'Bitte bestätige deine Anfrage', 'Sie' => 'Bitte bestätigen Sie Ihre Anfrage'],
        'swayy_billing_owner' => ['du' => 'Neue Tarifanfrage von :tenant', 'Sie' => 'Neue Tarifanfrage von :tenant'],
        'swayy_provisioned_welcome' => ['du' => 'Willkommen bei Swayy', 'Sie' => 'Willkommen bei Swayy'],
        'swayy_provisioned_approved' => ['du' => 'Dein Zugang ist freigeschaltet', 'Sie' => 'Ihr Zugang ist freigeschaltet'],
        'swayy_contact' => ['du' => 'Neue Kontaktanfrage', 'Sie' => 'Neue Kontaktanfrage'],
        'swayy_notice' => ['du' => ':subject', 'Sie' => ':subject'],
    ],

    'reasons' => [
        'guest' => ['du' => 'Du bekommst diese Mail, weil du bei :location reserviert hast.', 'Sie' => 'Sie erhalten diese Mail, weil Sie bei :location reserviert haben.'],
        'guest_verify' => ['du' => 'Du bekommst diese Mail, weil mit dieser Adresse bei :location reserviert wurde.', 'Sie' => 'Sie erhalten diese Mail, weil mit dieser Adresse bei :location reserviert wurde.'],
        'guest_login' => ['du' => 'Du bekommst diese Mail, weil für dein Kundenkonto bei :location ein Anmeldelink angefordert wurde.', 'Sie' => 'Sie erhalten diese Mail, weil für Ihr Kundenkonto bei :location ein Anmeldelink angefordert wurde.'],
        'waitlist_offer' => ['du' => 'Du bekommst diese Mail, weil du auf der Warteliste von :location stehst.', 'Sie' => 'Sie erhalten diese Mail, weil Sie auf der Warteliste von :location stehen.'],
        'event_booking_confirmed' => ['du' => 'Du bekommst diese Mail, weil du bei :location gebucht hast.', 'Sie' => 'Sie erhalten diese Mail, weil Sie bei :location gebucht haben.'],
        'marketing' => ['du' => 'Du bekommst diese Mail, weil du Neuigkeiten von :location abonniert hast.', 'Sie' => 'Sie erhalten diese Mail, weil Sie Neuigkeiten von :location abonniert haben.'],
        'operator' => ['du' => 'Du bekommst diese Mail, weil für :location Benachrichtigungen an diese Adresse gehen.', 'Sie' => 'Sie erhalten diese Mail, weil für :location Benachrichtigungen an diese Adresse gehen.'],
        'swayy' => ['du' => 'Du bekommst diese Mail, weil du ein Konto bei Swayy hast.', 'Sie' => 'Sie erhalten diese Mail, weil Sie ein Konto bei Swayy haben.'],
        'swayy_password_reset' => ['du' => 'Du bekommst diese Mail, weil für dein Swayy-Konto ein neues Passwort angefordert wurde.', 'Sie' => 'Sie erhalten diese Mail, weil für Ihr Swayy-Konto ein neues Passwort angefordert wurde.'],
        'swayy_billing_owner' => ['du' => 'Interne Mail der Swayy-Plattform.', 'Sie' => 'Interne Mail der Swayy-Plattform.'],
        'swayy_contact' => ['du' => 'Interne Mail der Swayy-Plattform.', 'Sie' => 'Interne Mail der Swayy-Plattform.'],
    ],

    'footer' => [
        'imprint' => 'Impressum',
        'privacy' => 'Datenschutz',
        'help' => 'Hilfe & Kontakt',
        'phone' => 'Telefon',
        'email' => 'E-Mail',
        'powered_by' => 'Reserviert mit',
        'unsubscribe' => ['du' => 'Keine Neuigkeiten mehr?', 'Sie' => 'Keine Neuigkeiten mehr?'],
        'unsubscribe_link' => 'Abmelden',
    ],
];
