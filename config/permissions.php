<?php

/**
 * Role → permission map. Tenant roles only; SaaS roles (users.saas_role)
 * are handled separately via Gate::before and explicit checks.
 *
 * '*' grants every permission.
 */
return [

    'permissions' => [
        'reservations.view',
        'reservations.create',
        'reservations.update',
        'reservations.cancel',
        'reservations.delete',
        'reservations.seat',
        'reservations.depart',
        'reservations.no_show',
        'walkins.create',
        'waitlist.manage',
        'tables.assign',
        'floorplan.update',
        'rooms.manage',
        'tables.manage',
        'opening_hours.manage',
        'special_hours.manage',
        'blackouts.manage',
        'events.manage',
        'guests.view',
        'guests.update',
        'guests.export',
        'guests.anonymize',
        'guest_notes.view',
        'guest_notes.sensitive.view',
        'consents.view',
        'templates.manage',
        'payments.manage',
        'users.invite',
        'users.roles.manage',
        'integrations.manage',
        'api_tokens.manage',
        'webhooks.manage',
        'audit.view',
        'billing.manage',
        'tenant.settings.manage',
        'locations.manage',
        'reports.view',
        'overbook.manual',
    ],

    'roles' => [
        'tenant_owner' => ['*'],

        'tenant_admin' => ['*'],

        'operations_manager' => [
            'reservations.view', 'reservations.create', 'reservations.update', 'reservations.cancel',
            'reservations.seat', 'reservations.depart', 'reservations.no_show',
            'walkins.create', 'waitlist.manage', 'tables.assign', 'floorplan.update',
            'rooms.manage', 'tables.manage', 'opening_hours.manage', 'special_hours.manage',
            'blackouts.manage', 'events.manage',
            'guests.view', 'guests.update', 'guest_notes.view',
            'templates.manage', 'reports.view', 'users.invite', 'overbook.manual',
            'locations.manage',
        ],

        'location_manager' => [
            'reservations.view', 'reservations.create', 'reservations.update', 'reservations.cancel',
            'reservations.seat', 'reservations.depart', 'reservations.no_show',
            'walkins.create', 'waitlist.manage', 'tables.assign', 'floorplan.update',
            'rooms.manage', 'tables.manage', 'opening_hours.manage', 'special_hours.manage',
            'blackouts.manage', 'events.manage',
            'guests.view', 'guests.update', 'guest_notes.view',
            'reports.view', 'overbook.manual',
        ],

        'host' => [
            'reservations.view', 'reservations.create', 'reservations.update', 'reservations.cancel',
            'reservations.seat', 'reservations.depart', 'reservations.no_show',
            'walkins.create', 'waitlist.manage', 'tables.assign',
            'guests.view', 'guests.update', 'guest_notes.view',
        ],

        'staff' => [
            'reservations.view', 'reservations.create', 'reservations.update',
            'reservations.seat', 'reservations.depart',
            'walkins.create', 'waitlist.manage',
            'guests.view', 'guest_notes.view',
        ],

        'marketing_manager' => [
            'reservations.view',
            'guests.view', 'guests.export',
            'consents.view', 'templates.manage', 'reports.view',
            'events.manage',
        ],

        'readonly' => [
            'reservations.view', 'guests.view', 'reports.view',
        ],
    ],

    'saas_roles' => ['super_admin', 'support_admin', 'billing_admin', 'readonly_admin'],
];
