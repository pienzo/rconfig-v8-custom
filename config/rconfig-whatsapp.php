<?php

return [
    // No WhatsApp send path is enabled by default. All values live server-side.
    // Template names must be approved for this WABA and sent to recipients
    // authorized for the selected message category (normally Utility).
    'enabled' => env('RCONFIG_WHATSAPP_ENABLED', false),
    // Explicit operator attestation: approved templates, recipient opt-in and costs.
    'policy_approved' => env('RCONFIG_WHATSAPP_POLICY_APPROVED', false),
    'from_utc' => env('RCONFIG_WHATSAPP_FROM_UTC', ''),
    'body_fields_success' => env('RCONFIG_WHATSAPP_BODY_FIELDS_SUCCESS', ''),
    'body_fields_failed' => env('RCONFIG_WHATSAPP_BODY_FIELDS_FAILED', ''),
    'graph_version' => env('RCONFIG_WHATSAPP_GRAPH_VERSION', 'v26.0'),
    'access_token' => env('RCONFIG_WHATSAPP_ACCESS_TOKEN', ''),
    'phone_number_id' => env('RCONFIG_WHATSAPP_PHONE_NUMBER_ID', ''),
    'recipient' => env('RCONFIG_WHATSAPP_RECIPIENT', ''),
    'template_success' => env('RCONFIG_WHATSAPP_TEMPLATE_SUCCESS', ''),
    'template_failed' => env('RCONFIG_WHATSAPP_TEMPLATE_FAILED', ''),
    'template_language' => env('RCONFIG_WHATSAPP_TEMPLATE_LANGUAGE', 'it'),
];
