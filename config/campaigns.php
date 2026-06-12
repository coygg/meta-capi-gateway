<?php

declare(strict_types=1);

$baseUrl = rtrim((string) (getenv('APP_BASE_URL') ?: getenv('RENDER_EXTERNAL_URL') ?: 'http://127.0.0.1:8080'), '/');

return [
    'weight-intake' => [
        'status' => 'active',

        // This is the public/static intake lander. It should match the ad copy.
        'landing_url' => $baseUrl . '/intake/weight-intake',

        // This is the protected telehealth form start URL. Replace for production.
        'form_url' => 'https://telehealth.example.com/intake/start',

        // This page keeps the Facebook copy intact but does not expose the form flow.
        'public_fallback_url' => $baseUrl . '/fallback/weight-intake',
        'event_source_url' => $baseUrl . '/intake/weight-intake',

        'allowed_domains' => [
            '127.0.0.1',
            'localhost',
            'telehealth.example.com',
            // Add your production domains here, for example:
            // 'track.yourdomain.com',
            // 'www.yourdomain.com',
            // '*.yourdomain.com',
        ],

        // These must be expanded by Meta. Ad Library/spy copies often have them empty
        // or still in macro form, which routes to the public fallback.
        'required_params' => [
            'ad_id',
            'adset_id',
            'campaign_id',
            'utm_source',
        ],

        'accepted_utm_sources' => [
            'facebook',
            'fb',
            'instagram',
            'ig',
            'meta',
        ],

        'click_token_ttl_seconds' => 1800,
        'form_token_ttl_seconds' => 7200,
        'click_token_param' => 'cid',
        'form_token_param' => 'sid',
        'capi_event_name' => 'Lead',

        // Keep this generic for telehealth. Do not include diagnosis, treatment,
        // medication, symptoms, or form answers in Meta events.
        'capi_custom_data' => [],

        'fallback_title' => 'Online health intake',
        'fallback_body' => 'Review general information about this online intake pathway. The secure intake form is available from eligible ad sessions.',
        'intake_title' => 'Online health intake',
        'intake_body' => 'Start a secure intake flow to help determine whether this care pathway is appropriate.',
    ],
];
