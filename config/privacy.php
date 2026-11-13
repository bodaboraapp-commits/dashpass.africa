<?php

declare(strict_types=1);

/*
 * Privacy, legal and data-protection configuration.
 *
 * One place for the public legal identity + contact details rendered on the
 * Privacy Policy, Terms & Conditions, Data Policy, Privacy Choices and
 * Delete My Data pages, so a company/DPO change never needs a view edit.
 *
 * Nothing here is a secret: every value is public-facing contact information.
 */

return [
    // Product / trading name shown in the legal pages ("DashPASS").
    'controller' => (string) env('PRIVACY_CONTROLLER_NAME', 'DashPASS'),
    // Registered legal entity name used in the Terms and the data-controller line.
    'legal_entity' => (string) env('PRIVACY_LEGAL_ENTITY', 'DashPASS'),
    'registered_address' => (string) env(
        'PRIVACY_REGISTERED_ADDRESS',
        'Nairobi, Kenya'
    ),
    // Inbox for privacy / data-subject rights requests.
    'privacy_email' => (string) env('PRIVACY_EMAIL', 'privacy@dashpass.africa'),
    // Data Protection Officer / data-protection lead.
    'dpo_email' => (string) env('PRIVACY_DPO_EMAIL', 'dpo@dashpass.africa'),
    // Version + effective date rendered on every legal page and carried in the
    // audit/request records so a consent snapshot can be traced to a text version.
    'version' => (string) env('PRIVACY_POLICY_VERSION', '1.0'),
    'effective_date' => (string) env('PRIVACY_POLICY_EFFECTIVE', '13 November 2026'),
    // Statutory maximum under the Kenya Data Protection Act, 2019 (s.37): a data
    // controller must respond to a data-subject request within 30 days.
    'response_days' => max(1, (int) env('PRIVACY_RESPONSE_DAYS', 30)),
    'governing_law' => (string) env('PRIVACY_GOVERNING_LAW', 'the Republic of Kenya'),
    'supervisory_authority' => 'Office of the Data Protection Commissioner (ODPC), Kenya',
    'supervisory_url' => 'https://www.odpc.go.ke',
];
