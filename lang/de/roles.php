<?php

// Rollen im Betrieb: Bezeichnung und ein Satz, was die Rolle darf. Die Rechte
// selbst stehen in config/permissions.php; neue Rollen dort brauchen hier
// einen Eintrag (Test TeamPageTest).
return [
    'tenant_owner' => [
        'label' => 'Inhaber',
        'summary' => 'Alles, dazu Datenexport, Import und „Betrieb löschen“.',
    ],
    'tenant_admin' => [
        'label' => 'Administration',
        'summary' => 'Alle Einstellungen, Team mit Rollen, Abo und Zahlungen. Datenexport, Import und „Betrieb löschen“ bleiben beim Inhaber.',
    ],
    'operations_manager' => [
        'label' => 'Betriebsleitung',
        'summary' => 'Service, Buchungen, Gäste, Zeiten, Räume, Events, Vorlagen, Marketing und Standorte; lädt Personen ein, Rollen vergibt die Administration.',
    ],
    'location_manager' => [
        'label' => 'Standortleitung',
        'summary' => 'Service, Buchungen, Gäste, Zeiten, Räume, Events und Berichte.',
    ],
    'host' => [
        'label' => 'Empfang',
        'summary' => 'Reservierungen, Walk-ins, Warteliste, Tische zuweisen und Gäste pflegen.',
    ],
    'staff' => [
        'label' => 'Service',
        'summary' => 'Reservierungen ansehen und anlegen, Gäste platzieren, Walk-ins und Warteliste.',
    ],
    'marketing_manager' => [
        'label' => 'Marketing',
        'summary' => 'Gästeliste mit Export, Vorlagen, Kampagnen, Events und Berichte.',
    ],
    'readonly' => [
        'label' => 'Nur lesen',
        'summary' => 'Reservierungen, Gäste und Berichte ansehen.',
    ],
];
