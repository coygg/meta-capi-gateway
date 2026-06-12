<?php

declare(strict_types=1);

$baseUrl = rtrim((string) (getenv('APP_BASE_URL') ?: 'http://127.0.0.1:18080'), '/');
$formUrl = (string) (getenv('TEST_TELEHEALTH_URL') ?: 'http://127.0.0.1:18081/intake/start');

return [
    'weight-intake' => [
        'status' => 'active',
        'landing_url' => $baseUrl . '/intake/weight-intake',
        'form_url' => $formUrl,
        'public_fallback_url' => $baseUrl . '/fallback/weight-intake',
        'event_source_url' => $baseUrl . '/intake/weight-intake',
        'allowed_domains' => [
            '127.0.0.1',
            'localhost',
        ],
        'required_params' => [
            'ad_id',
            'adset_id',
            'campaign_id',
            'utm_source',
        ],
        'accepted_utm_sources' => [
            'facebook',
            'instagram',
        ],
        'click_token_ttl_seconds' => 1800,
        'form_token_ttl_seconds' => 7200,
        'click_token_param' => 'cid',
        'form_token_param' => 'sid',
        'capi_event_name' => 'Lead',
        'capi_custom_data' => [],
        'fallback_title' => 'Online health intake',
        'fallback_body' => 'Public fallback page for the same offer.',
        'intake_title' => 'Online health intake',
        'intake_body' => 'Mock static intake lander.',
    ],
    'custom-intake' => [
        'status' => 'active',
        'landing_url' => $baseUrl . '/intake/custom-intake',
        'form_url' => $formUrl,
        'public_fallback_url' => $baseUrl . '/fallback/custom-intake',
        'event_source_url' => $baseUrl . '/intake/custom-intake',
        'allowed_domains' => [
            '127.0.0.1',
            'localhost',
        ],
        'required_params' => [
            'ad_id',
            'adset_id',
            'campaign_id',
            'utm_source',
        ],
        'accepted_utm_sources' => [
            'facebook',
        ],
        'click_token_ttl_seconds' => 1800,
        'form_token_ttl_seconds' => 7200,
        'click_token_param' => 'cid',
        'form_token_param' => 'sid',
        'capi_event_name' => 'Lead',
        'capi_custom_data' => [
            'content_name' => 'generic-intake',
        ],
        'fallback_title' => 'Custom fallback',
        'fallback_body' => 'Public fallback page.',
        'intake_title' => 'Custom intake',
        'intake_body' => 'Custom intake lander.',
    ],
    'bad-domains' => [
        'status' => 'active',
        'landing_url' => $baseUrl . '/intake/bad-domains',
        'form_url' => $formUrl,
        'public_fallback_url' => $baseUrl . '/fallback/bad-domains',
        'event_source_url' => $baseUrl . '/intake/bad-domains',
        'allowed_domains' => 'bad-config',
        'required_params' => [
            'ad_id',
            'adset_id',
            'campaign_id',
            'utm_source',
        ],
        'accepted_utm_sources' => [
            'facebook',
        ],
        'capi_event_name' => 'Lead',
    ],
];
