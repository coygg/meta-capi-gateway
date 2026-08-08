<?php

declare(strict_types=1);

// This file only seeds campaigns whose slug is not in the database yet (the shipped
// campaign on first boot). It never updates an existing campaign, so after seeding
// the /admin editor is the source of truth: every URL, including the destination
// form URL, can be changed there at any time without redeploying or recreating
// anything. Invalid entries in this file are skipped rather than breaking the app.

$baseUrl = rtrim((string) (getenv('APP_BASE_URL') ?: getenv('RENDER_EXTERNAL_URL') ?: 'http://127.0.0.1:8080'), '/');

return [
    'weight-intake' => [
        'status' => 'active',

        // This is the public/static intake lander. It should match the ad copy.
        'landing_url' => $baseUrl . '/intake/weight-intake',

        // This is the destination form start URL used for the first boot only.
        // Change it later in the /admin campaign editor.
        'form_url' => 'https://telehealth.example.com/intake/start',

        // This page keeps the Facebook copy intact but does not expose the form flow.
        'public_fallback_url' => $baseUrl . '/fallback/weight-intake',

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

        'fallback_title' => 'Online health intake',
        'fallback_body' => 'Review general information about this online intake pathway. The secure intake form is available from eligible ad sessions.',
        'intake_title' => 'Online health intake',
        'intake_body' => 'Start a secure intake flow to help determine whether this care pathway is appropriate.',
    ],
];
