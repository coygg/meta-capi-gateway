<?php

declare(strict_types=1);

use Gateway\Services\CapiClient;
use Gateway\Services\TokenService;

function run_e2e_tests(TestHarness $test, string $root): void
{
    echo "\nE2E tests\n";

    $runtime = $root . '/tests/.runtime/e2e-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($runtime, 0777, true);

    $gatewayPort = 18080;
    $telehealthPort = 18081;
    $metaPort = 18082;
    $gatewayBase = 'http://127.0.0.1:' . $gatewayPort;
    $telehealthBase = 'http://127.0.0.1:' . $telehealthPort;
    $metaBase = 'http://127.0.0.1:' . $metaPort;
    $coverageDir = $root . '/tests/.runtime/e2e/coverage';
    rm_rf($coverageDir);
    mkdir($coverageDir, 0777, true);

    $metaLog = $runtime . '/meta-requests.jsonl';
    $telehealthLog = $runtime . '/telehealth-sessions.jsonl';
    $gatewayEnvFile = $runtime . '/gateway.env';
    $webhookSecret = 'e2e-webhook-secret';
    $processes = [];

    file_put_contents($gatewayEnvFile, implode(PHP_EOL, [
        'APP_ENV=local',
        'APP_BASE_URL=' . $gatewayBase,
        'APP_SECRET=e2e-development-secret-change-before-production-12345',
        'DB_PATH=' . $runtime . '/gateway.sqlite',
        'COOKIE_SECURE=0',
        'TRUST_PROXY=1',
        'CAPI_DRY_RUN=0',
        'META_PIXEL_ID=test-pixel',
        'META_ACCESS_TOKEN=test-token',
        'META_GRAPH_VERSION=v20.0',
        'META_GRAPH_BASE_URL=' . $metaBase,
        'INTAKE_WEBHOOK_SECRET=' . $webhookSecret,
        'CAMPAIGN_CONFIG_PATH=' . $root . '/tests/fixtures/campaigns.e2e.php',
        'TEST_TELEHEALTH_URL=' . $telehealthBase . '/intake/start',
        '',
    ]));

    try {
        $processes[] = start_php_server('127.0.0.1', $metaPort, $root . '/tests/mock-meta', $runtime . '/mock-meta.log', [
            'MOCK_META_LOG' => $metaLog,
        ]);
        $processes[] = start_php_server('127.0.0.1', $telehealthPort, $root . '/tests/mock-telehealth', $runtime . '/mock-telehealth.log', [
            'MOCK_TELEHEALTH_LOG' => $telehealthLog,
            'GATEWAY_WEBHOOK_URL' => $gatewayBase . '/capi/intake-completed',
            'INTAKE_WEBHOOK_SECRET' => $webhookSecret,
        ]);

        $processes[] = start_php_server('127.0.0.1', $gatewayPort, $root . '/public', $runtime . '/gateway.log', [
            'ENV_FILE' => $gatewayEnvFile,
            'COVERAGE_DIR' => $coverageDir,
        ], [
            'pcov.enabled' => '1',
            'pcov.directory' => $root,
            'auto_prepend_file' => $root . '/tests/coverage-prepend.php',
            'auto_append_file' => $root . '/tests/coverage-append.php',
        ]);

        wait_for_url($metaBase . '/health');
        wait_for_url($telehealthBase . '/health');
        wait_for_url($gatewayBase . '/health');

        $health = http_request('GET', $gatewayBase . '/health');
        $test->assertSame(200, $health['status'], 'gateway health endpoint responds');

        $adminRedirect = http_request('GET', $gatewayBase . '/admin');
        $test->assertSame(302, $adminRedirect['status'], 'admin redirects to first-run setup before password exists');
        $test->assertSame('/admin/setup', header_value($adminRedirect, 'location'), 'first-run admin redirect points to setup');

        $setupPage = http_request('GET', $gatewayBase . '/admin/setup');
        $test->assertSame(200, $setupPage['status'], 'first-run setup page renders');
        $test->assertContains('Create admin password', $setupPage['body'], 'setup page prompts for admin password');
        $adminCookie = cookie_header($setupPage);

        $shortSetup = http_request(
            'POST',
            $gatewayBase . '/admin/setup',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query(['password' => 'short', 'password_confirmation' => 'short'])
        );
        $test->assertSame(422, $shortSetup['status'], 'setup rejects short admin password');

        $mismatchSetup = http_request(
            'POST',
            $gatewayBase . '/admin/setup',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query(['password' => 'super-secure-password', 'password_confirmation' => 'different-password'])
        );
        $test->assertSame(422, $mismatchSetup['status'], 'setup rejects mismatched admin password');

        $setup = http_request(
            'POST',
            $gatewayBase . '/admin/setup',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query(['password' => 'super-secure-password', 'password_confirmation' => 'super-secure-password'])
        );
        $test->assertSame(302, $setup['status'], 'setup accepts strong admin password');
        $adminCookie = cookie_header($setup, $adminCookie);

        $setupAfterAdmin = http_request('GET', $gatewayBase . '/admin/setup', ['Cookie' => $adminCookie]);
        $test->assertSame(302, $setupAfterAdmin['status'], 'setup redirects after admin exists');
        $setupPostAfterAdmin = http_request(
            'POST',
            $gatewayBase . '/admin/setup',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query(['password' => 'another-password', 'password_confirmation' => 'another-password'])
        );
        $test->assertSame(302, $setupPostAfterAdmin['status'], 'setup post redirects after admin exists');

        $dashboard = http_request('GET', $gatewayBase . '/admin', ['Cookie' => $adminCookie]);
        $test->assertSame(200, $dashboard['status'], 'admin dashboard renders after setup');
        $test->assertContains('Domains', $dashboard['body'], 'admin dashboard lists domain section');
        $csrf = csrf_from_body($dashboard['body']);
        $test->assertTrue($csrf !== '', 'admin dashboard includes CSRF token');

        $addDomainBadCsrf = http_request(
            'POST',
            $gatewayBase . '/admin/domains',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query(['_csrf' => 'bad', 'hostname' => 'localhost'])
        );
        $test->assertSame(500, $addDomainBadCsrf['status'], 'domain form rejects bad CSRF token');

        $addDomain = http_request(
            'POST',
            $gatewayBase . '/admin/domains',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query(['_csrf' => $csrf, 'hostname' => 'localhost'])
        );
        $test->assertSame(302, $addDomain['status'], 'admin can add domain');

        $dashboardWithDomain = http_request('GET', $gatewayBase . '/admin', ['Cookie' => $adminCookie]);
        $test->assertContains('localhost', $dashboardWithDomain['body'], 'admin dashboard shows added domain');
        $test->assertContains('active', $dashboardWithDomain['body'], 'localhost domain is active immediately for local setup');
        $csrf = csrf_from_body($dashboardWithDomain['body']);
        preg_match('#/admin/domains/(\d+)/verify#', $dashboardWithDomain['body'], $domainMatch);
        $domainId = (int) ($domainMatch[1] ?? 0);

        $verifyDomain = http_request(
            'POST',
            $gatewayBase . '/admin/domains/' . $domainId . '/verify',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query(['_csrf' => $csrf])
        );
        $test->assertSame(302, $verifyDomain['status'], 'admin can verify domain');

        $verifyMissingDomain = http_request(
            'POST',
            $gatewayBase . '/admin/domains/9999/verify',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query(['_csrf' => $csrf])
        );
        $test->assertSame(302, $verifyMissingDomain['status'], 'missing domain verify redirects with flash');

        $deleteDomain = http_request(
            'POST',
            $gatewayBase . '/admin/domains/' . $domainId . '/delete',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query(['_csrf' => $csrf])
        );
        $test->assertSame(302, $deleteDomain['status'], 'admin can delete domain');

        $addDomainAgain = http_request(
            'POST',
            $gatewayBase . '/admin/domains',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query(['_csrf' => $csrf, 'hostname' => 'localhost'])
        );
        $test->assertSame(302, $addDomainAgain['status'], 'admin can add domain again after delete');

        $addInvalidDomain = http_request(
            'POST',
            $gatewayBase . '/admin/domains',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query(['_csrf' => $csrf, 'hostname' => 'bad host name'])
        );
        $test->assertSame(302, $addInvalidDomain['status'], 'invalid domain add redirects with flash');

        $newCampaignForm = http_request('GET', $gatewayBase . '/admin/campaigns/new', ['Cookie' => $adminCookie]);
        $test->assertSame(200, $newCampaignForm['status'], 'new campaign form renders');
        $test->assertContains('New campaign', $newCampaignForm['body'], 'new campaign form has title');

        $campaignPayload = [
            '_csrf' => $csrf,
            'slug' => 'portal-intake',
            'status' => 'active',
            'landing_url' => $gatewayBase . '/intake/portal-intake',
            'form_url' => $telehealthBase . '/intake/start',
            'public_fallback_url' => $gatewayBase . '/fallback/portal-intake',
            'event_source_url' => $gatewayBase . '/intake/portal-intake',
            'allowed_domains' => "127.0.0.1\nlocalhost",
            'required_params' => "ad_id\nadset_id\ncampaign_id\nutm_source",
            'accepted_utm_sources' => "facebook\ninstagram",
            'click_token_ttl_seconds' => '1800',
            'form_token_ttl_seconds' => '7200',
            'click_token_param' => 'cid',
            'form_token_param' => 'sid',
            'capi_event_name' => 'Lead',
            'capi_custom_data' => '{}',
            'fallback_title' => 'Portal fallback',
            'fallback_body' => 'Portal-managed fallback.',
            'intake_title' => 'Portal intake',
            'intake_body' => 'Portal-managed intake.',
        ];
        $createCampaign = http_request(
            'POST',
            $gatewayBase . '/admin/campaigns',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query($campaignPayload)
        );
        $test->assertSame(302, $createCampaign['status'], 'admin can create campaign in database');

        $dashboardWithCampaign = http_request('GET', $gatewayBase . '/admin', ['Cookie' => $adminCookie]);
        $test->assertContains('portal-intake', $dashboardWithCampaign['body'], 'dashboard shows portal-created campaign');
        $test->assertContains('http://localhost/c/portal-intake', $dashboardWithCampaign['body'], 'dashboard generates ad URL from active portal domain');
        preg_match('#portal-intake.*?/admin/campaigns/(\d+)/edit#s', $dashboardWithCampaign['body'], $campaignMatch);
        $campaignId = (int) ($campaignMatch[1] ?? 0);

        $editCampaignForm = http_request('GET', $gatewayBase . '/admin/campaigns/' . $campaignId . '/edit', ['Cookie' => $adminCookie]);
        $test->assertSame(200, $editCampaignForm['status'], 'edit campaign form renders');
        $test->assertContains('Edit campaign', $editCampaignForm['body'], 'edit campaign form has title');
        $editCsrf = csrf_from_body($editCampaignForm['body']);
        $badCampaignPayload = $campaignPayload;
        $badCampaignPayload['_csrf'] = $editCsrf;
        $badCampaignPayload['capi_custom_data'] = '{bad-json';
        $badCampaignSave = http_request(
            'POST',
            $gatewayBase . '/admin/campaigns/' . $campaignId,
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query($badCampaignPayload)
        );
        $test->assertSame(422, $badCampaignSave['status'], 'edit campaign rejects invalid custom data');

        $campaignPayload['_csrf'] = $editCsrf;
        $campaignPayload['intake_title'] = 'Updated portal intake';
        $editCampaignSave = http_request(
            'POST',
            $gatewayBase . '/admin/campaigns/' . $campaignId,
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query($campaignPayload)
        );
        $test->assertSame(302, $editCampaignSave['status'], 'admin can edit campaign');

        $editMissingCampaign = http_request('GET', $gatewayBase . '/admin/campaigns/9999/edit', ['Cookie' => $adminCookie]);
        $test->assertSame(302, $editMissingCampaign['status'], 'missing campaign edit redirects');

        $postMissingCampaign = http_request(
            'POST',
            $gatewayBase . '/admin/campaigns/9999',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query(['_csrf' => $csrf])
        );
        $test->assertSame(302, $postMissingCampaign['status'], 'missing campaign update redirects');

        $adminMissing = http_request('GET', $gatewayBase . '/admin/unknown', ['Cookie' => $adminCookie]);
        $test->assertSame(404, $adminMissing['status'], 'unknown admin route returns 404 when authenticated');

        $portalClick = http_request('GET', $gatewayBase . '/c/portal-intake?ad_id=portal-ad&adset_id=portal-set&campaign_id=portal-camp&utm_source=facebook&fbclid=portal-fbclid');
        $test->assertSame(302, $portalClick['status'], 'portal-created DB campaign accepts ad click');
        $test->assertContains('/intake/portal-intake', header_value($portalClick, 'location'), 'portal-created DB campaign redirects to configured lander');

        $logout = http_request(
            'POST',
            $gatewayBase . '/admin/logout',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query(['_csrf' => csrf_from_body($dashboardWithCampaign['body'])])
        );
        $test->assertSame(302, $logout['status'], 'admin can log out');
        $adminCookie = cookie_header($logout, $adminCookie);
        $adminAfterLogout = http_request('GET', $gatewayBase . '/admin', ['Cookie' => $adminCookie]);
        $test->assertSame(302, $adminAfterLogout['status'], 'admin dashboard redirects after logout');

        $loginPage = http_request('GET', $gatewayBase . '/admin/login', ['Cookie' => $adminCookie]);
        $test->assertSame(200, $loginPage['status'], 'login page renders after logout');

        $loginInvalid = http_request(
            'POST',
            $gatewayBase . '/admin/login',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query(['password' => 'wrong-password'])
        );
        $test->assertSame(401, $loginInvalid['status'], 'admin login rejects bad password');

        $loginValid = http_request(
            'POST',
            $gatewayBase . '/admin/login',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query(['password' => 'super-secure-password'])
        );
        $test->assertSame(302, $loginValid['status'], 'admin login accepts configured password');
        $adminCookie = cookie_header($loginValid, $adminCookie);

        $loginPageLoggedIn = http_request('GET', $gatewayBase . '/admin/login', ['Cookie' => $adminCookie]);
        $test->assertSame(302, $loginPageLoggedIn['status'], 'login page redirects when already authenticated');

        $fallbackPage = http_request('GET', $gatewayBase . '/fallback/weight-intake');
        $test->assertSame(200, $fallbackPage['status'], 'public fallback page renders directly');
        $test->assertContains('Online health intake', $fallbackPage['body'], 'public fallback preserves offer copy');

        $intakeNoCid = http_request('GET', $gatewayBase . '/intake/weight-intake');
        $test->assertSame(200, $intakeNoCid['status'], 'static intake page renders without cid');
        $test->assertContains('eligible ad session', $intakeNoCid['body'], 'static intake without cid does not expose form URL');

        $validClick = http_request(
            'GET',
            $gatewayBase . '/c/weight-intake?ad_id=ad-123&adset_id=set-456&campaign_id=camp-789&utm_source=facebook&utm_medium=paid_social&utm_campaign=demo&utm_content=ad&fbclid=fbclid-123',
            ['X-Forwarded-For' => '203.0.113.10, 198.51.100.20']
        );
        $test->assertSame(302, $validClick['status'], 'valid expanded Meta click redirects');
        $landingLocation = header_value($validClick, 'location');
        $test->assertContains('/intake/weight-intake', $landingLocation, 'valid click lands on static intake');
        $cid = query_params($landingLocation)['cid'] ?? '';
        $test->assertTrue($cid !== '', 'valid click receives signed cid');

        $lander = http_request('GET', $landingLocation);
        $test->assertSame(200, $lander['status'], 'static intake lander renders');
        $test->assertContains('Start intake', $lander['body'], 'static intake includes protected start link');

        $start = http_request('GET', $gatewayBase . '/start?cid=' . rawurlencode($cid));
        $test->assertSame(302, $start['status'], 'start endpoint redirects to telehealth platform');
        $formLocation = header_value($start, 'location');
        $test->assertContains($telehealthBase . '/intake/start', $formLocation, 'form redirect points to mock telehealth');
        $formParams = query_params($formLocation);
        $sid = $formParams['sid'] ?? '';
        $test->assertTrue($sid !== '', 'form redirect includes signed sid');
        $test->assertSame($cid, $formParams['cid'] ?? null, 'form redirect keeps signed cid for hosted form attribution');
        $test->assertSame($cid, $formParams['gateway_cid'] ?? null, 'form redirect includes gateway_cid alias');
        $test->assertSame('fbclid-123', $formParams['fbclid'] ?? null, 'form redirect forwards fbclid');
        $test->assertSame('ad-123', $formParams['ad_id'] ?? null, 'form redirect forwards ad id');
        $test->assertSame('set-456', $formParams['adset_id'] ?? null, 'form redirect forwards ad set id');
        $test->assertSame('camp-789', $formParams['campaign_id'] ?? null, 'form redirect forwards Meta campaign id');
        $test->assertSame('facebook', $formParams['utm_source'] ?? null, 'form redirect forwards UTM source');
        $test->assertSame('paid_social', $formParams['utm_medium'] ?? null, 'form redirect forwards UTM medium');
        $test->assertSame('demo', $formParams['utm_campaign'] ?? null, 'form redirect forwards UTM campaign');
        $test->assertSame('ad', $formParams['utm_content'] ?? null, 'form redirect forwards UTM content');

        $telehealthStart = http_request('GET', $formLocation);
        $test->assertSame(200, $telehealthStart['status'], 'mock telehealth captures sid');
        $test->assertContains('Mock telehealth intake', $telehealthStart['body'], 'mock telehealth renders intake');

        $completion = http_request('POST', $telehealthBase . '/complete?event_id=e2e-event-1');
        $test->assertSame(200, $completion['status'], 'mock telehealth completion endpoint responds');
        $completionPayload = json_decode($completion['body'], true);
        $test->assertSame(200, (int) ($completionPayload['gateway_status'] ?? 0), 'mock telehealth posts conversion webhook to gateway');
        $gatewayPayload = $completionPayload['gateway_body'] ?? [];
        $test->assertSame(true, $gatewayPayload['ok'] ?? false, 'gateway accepts telehealth conversion');
        $test->assertSame(false, $gatewayPayload['dry_run'] ?? true, 'gateway sends live request to mock Meta in e2e');

        $metaRequests = read_jsonl($metaLog);
        $test->assertSame(1, count($metaRequests), 'mock Meta receives exactly one CAPI event');
        $metaRequest = $metaRequests[0];
        $test->assertContains('/v20.0/test-pixel/events', (string) ($metaRequest['uri'] ?? ''), 'CAPI request uses configured mock Meta URL');
        $metaPayload = $metaRequest['json'] ?? [];
        $event = $metaPayload['data'][0] ?? [];
        $test->assertSame('Lead', $event['event_name'] ?? null, 'CAPI event name is generic Lead');
        $test->assertSame('e2e-event-1', $event['event_id'] ?? null, 'CAPI event id comes from telehealth platform');
        $test->assertSame('website', $event['action_source'] ?? null, 'CAPI action source is website');
        $test->assertContains('fbclid-123', $event['user_data']['fbc'] ?? '', 'CAPI fbc preserves original fbclid');
        $test->assertTrue(isset($event['user_data']['fbp']), 'CAPI fbp is present');
        $test->assertTrue(!isset($event['custom_data']), 'CAPI payload omits custom data by default');
        $test->assertTrue(!str_contains(json_encode($metaPayload, JSON_UNESCAPED_SLASHES) ?: '', 'diagnosis'), 'CAPI payload does not include health terms');

        $duplicate = http_request('POST', $telehealthBase . '/complete?event_id=e2e-event-1');
        $duplicatePayload = json_decode($duplicate['body'], true);
        $duplicateGateway = $duplicatePayload['gateway_body'] ?? [];
        $test->assertSame(true, $duplicateGateway['duplicate'] ?? false, 'duplicate telehealth webhook is idempotent');
        $test->assertSame(1, count(read_jsonl($metaLog)), 'duplicate event does not send another CAPI request');

        $tokens = new TokenService('e2e-development-secret-change-before-production-12345');
        $cidClaims = $tokens->verify($cid, 'click')['claims'];
        $unknownClickToken = $tokens->sign([
            'type' => 'click',
            'click_id' => 'missing-click',
            'campaign' => 'weight-intake',
        ], 600);
        $unknownStart = http_request('GET', $gatewayBase . '/start?cid=' . rawurlencode($unknownClickToken));
        $test->assertSame(404, $unknownStart['status'], 'start rejects signed token for missing click');

        $unknownClickWebhook = http_request(
            'POST',
            $gatewayBase . '/capi/intake-completed',
            ['X-Webhook-Secret' => $webhookSecret, 'Content-Type' => 'application/json'],
            json_encode(['cid' => $unknownClickToken, 'event_id' => 'missing-click-event'], JSON_UNESCAPED_SLASHES)
        );
        $test->assertSame(404, $unknownClickWebhook['status'], 'webhook rejects signed token for missing click');

        $missingSessionToken = $tokens->sign([
            'type' => 'form',
            'session_id' => 'missing-session',
            'click_id' => (string) ($cidClaims['click_id'] ?? ''),
            'campaign' => 'weight-intake',
        ], 600);
        $missingSessionWebhook = http_request(
            'POST',
            $gatewayBase . '/capi/intake-completed',
            ['X-Webhook-Secret' => $webhookSecret, 'Content-Type' => 'application/json'],
            json_encode(['sid' => $missingSessionToken, 'event_id' => 'missing-session-event'], JSON_UNESCAPED_SLASHES)
        );
        $test->assertSame(404, $missingSessionWebhook['status'], 'webhook rejects missing form session');

        $invalidJsonWebhook = http_request(
            'POST',
            $gatewayBase . '/capi/intake-completed',
            ['X-Webhook-Secret' => $webhookSecret, 'Content-Type' => 'application/json'],
            '{bad-json'
        );
        $test->assertSame(400, $invalidJsonWebhook['status'], 'webhook rejects malformed JSON body');

        $cookieFlow = complete_mock_flow(
            $gatewayBase,
            $telehealthBase,
            'weight-intake',
            'e2e-event-cookie',
            ['Cookie' => 'pj_fbc=cookie-fbc; pj_fbp=cookie-fbp']
        );
        $test->assertSame(200, $cookieFlow['completion_status'], 'cookie attribution flow completes');
        $metaAfterCookie = read_jsonl($metaLog);
        $cookieEvent = $metaAfterCookie[1]['json']['data'][0] ?? [];
        $test->assertSame('cookie-fbc', $cookieEvent['user_data']['fbc'] ?? null, 'gateway preserves incoming fbc cookie');
        $test->assertSame('cookie-fbp', $cookieEvent['user_data']['fbp'] ?? null, 'gateway preserves incoming fbp cookie');

        $noFbclidFlow = complete_mock_flow(
            $gatewayBase,
            $telehealthBase,
            'weight-intake',
            'e2e-event-no-fbclid',
            ['CF-Connecting-IP' => '203.0.113.44'],
            ['fbclid' => null]
        );
        $test->assertSame(200, $noFbclidFlow['completion_status'], 'no-fbclid flow completes');
        $metaAfterNoFbclid = read_jsonl($metaLog);
        $noFbclidEvent = $metaAfterNoFbclid[2]['json']['data'][0] ?? [];
        $test->assertTrue(!isset($noFbclidEvent['user_data']['fbc']), 'CAPI omits fbc when no fbclid or fbc cookie exists');

        $customFlow = complete_mock_flow($gatewayBase, $telehealthBase, 'custom-intake', 'e2e-event-custom');
        $test->assertSame(200, $customFlow['completion_status'], 'custom campaign flow completes');
        $metaAfterCustom = read_jsonl($metaLog);
        $customEvent = $metaAfterCustom[3]['json']['data'][0] ?? [];
        $test->assertSame('generic-intake', $customEvent['custom_data']['content_name'] ?? null, 'configured custom_data is included when present');

        $remedoraPayload = [
            'event' => ['id' => 'remedora-nested-event'],
            'session' => ['id' => 'remedora-session-1'],
            'metadata' => ['sid' => $sid],
            'page_url' => 'https://try.remedora.com/f/reta-form?sid=' . rawurlencode($sid) . '&cid=' . rawurlencode($cid),
        ];
        $remedoraWebhook = http_request(
            'POST',
            $gatewayBase . '/capi/intake-completed',
            ['X-Webhook-Secret' => $webhookSecret, 'Content-Type' => 'application/json'],
            json_encode($remedoraPayload, JSON_UNESCAPED_SLASHES)
        );
        $test->assertSame(200, $remedoraWebhook['status'], 'webhook accepts Remedora-style nested metadata');
        $metaAfterRemedora = read_jsonl($metaLog);
        $remedoraEvent = $metaAfterRemedora[4]['json']['data'][0] ?? [];
        $test->assertSame('remedora-nested-event', $remedoraEvent['event_id'] ?? null, 'nested event id is used for idempotency');

        $remedoraDuplicate = http_request(
            'POST',
            $gatewayBase . '/capi/intake-completed',
            ['X-Webhook-Secret' => $webhookSecret, 'Content-Type' => 'application/json'],
            json_encode($remedoraPayload, JSON_UNESCAPED_SLASHES)
        );
        $remedoraDuplicatePayload = json_decode($remedoraDuplicate['body'], true) ?: [];
        $test->assertSame(true, $remedoraDuplicatePayload['duplicate'] ?? false, 'nested Remedora-style webhook is idempotent');
        $test->assertSame(5, count(read_jsonl($metaLog)), 'nested duplicate does not send another CAPI request');

        $urlOnlyWebhook = http_request(
            'POST',
            $gatewayBase . '/capi/intake-completed',
            ['X-Webhook-Secret' => $webhookSecret, 'Content-Type' => 'application/json'],
            json_encode([
                'sessionMeta' => ['session_id' => 'remedora-url-session'],
                'page_url' => 'https://try.remedora.com/f/reta-form?sid=' . rawurlencode($sid) . '&cid=' . rawurlencode($cid),
            ], JSON_UNESCAPED_SLASHES)
        );
        $test->assertSame(200, $urlOnlyWebhook['status'], 'webhook can recover sid from captured form URL');
        $metaAfterUrlOnly = read_jsonl($metaLog);
        $urlOnlyEvent = $metaAfterUrlOnly[5]['json']['data'][0] ?? [];
        $test->assertSame('remedora-url-session', $urlOnlyEvent['event_id'] ?? null, 'nested session id can drive event id');

        $clickOnlyWebhook = http_request(
            'POST',
            $gatewayBase . '/capi/intake-completed',
            ['X-Webhook-Secret' => $webhookSecret, 'Content-Type' => 'application/json'],
            json_encode([
                'submission_id' => 'remedora-click-url',
                'resume_url' => 'https://try.remedora.com/f/reta-form?cid=' . rawurlencode($cid),
            ], JSON_UNESCAPED_SLASHES)
        );
        $test->assertSame(200, $clickOnlyWebhook['status'], 'webhook can recover cid from captured form URL');
        $metaAfterClickOnly = read_jsonl($metaLog);
        $clickOnlyEvent = $metaAfterClickOnly[6]['json']['data'][0] ?? [];
        $test->assertSame('remedora-click-url', $clickOnlyEvent['event_id'] ?? null, 'submission id can drive event id');

        $remedoraNativePayload = [
            'api_version' => '2026-04-08',
            'event' => 'checkout.completed',
            'occurred_at' => gmdate('c'),
            'is_test' => false,
            'meta' => [
                'delivery_id' => 'remedora-delivery-1',
                'safe_fields_only' => true,
            ],
            'data' => [
                'session' => [
                    'reference' => 'remedora-session-reference',
                    'resume_url' => 'https://try.remedora.com/resume/remedora-session-reference',
                ],
                'attribution' => [
                    'query_params' => [
                        ['key' => 'utm_source', 'value' => 'facebook'],
                        ['key' => 'sid', 'value' => $sid],
                        ['key' => 'cid', 'value' => $cid],
                    ],
                ],
            ],
        ];
        $remedoraNativeBody = json_encode($remedoraNativePayload, JSON_UNESCAPED_SLASHES) ?: '{}';
        $remedoraTimestamp = gmdate('c');
        $remedoraSignature = 'sha256=' . hash_hmac('sha256', $remedoraTimestamp . '.' . $remedoraNativeBody, $webhookSecret);
        $badRemedoraSignature = http_request(
            'POST',
            $gatewayBase . '/capi/intake-completed',
            [
                'Content-Type' => 'application/json',
                'X-Remedora-Event' => 'checkout.completed',
                'X-Remedora-Signature' => 'sha256=bad',
                'X-Remedora-Timestamp' => $remedoraTimestamp,
            ],
            $remedoraNativeBody
        );
        $test->assertSame(401, $badRemedoraSignature['status'], 'webhook rejects bad Remedora signature');

        $remedoraNativeWebhook = http_request(
            'POST',
            $gatewayBase . '/capi/intake-completed',
            [
                'Content-Type' => 'application/json',
                'X-Remedora-Event' => 'checkout.completed',
                'X-Remedora-Signature' => $remedoraSignature,
                'X-Remedora-Timestamp' => $remedoraTimestamp,
            ],
            $remedoraNativeBody
        );
        $test->assertSame(200, $remedoraNativeWebhook['status'], 'webhook accepts native Remedora signed payload');
        $metaAfterNativeRemedora = read_jsonl($metaLog);
        $nativeRemedoraEvent = $metaAfterNativeRemedora[7]['json']['data'][0] ?? [];
        $test->assertSame('remedora-delivery-1', $nativeRemedoraEvent['event_id'] ?? null, 'Remedora delivery id drives CAPI idempotency');

        $remedoraNativeDuplicate = http_request(
            'POST',
            $gatewayBase . '/capi/intake-completed',
            [
                'Content-Type' => 'application/json',
                'X-Remedora-Event' => 'checkout.completed',
                'X-Remedora-Signature' => $remedoraSignature,
                'X-Remedora-Timestamp' => $remedoraTimestamp,
            ],
            $remedoraNativeBody
        );
        $remedoraNativeDuplicatePayload = json_decode($remedoraNativeDuplicate['body'], true) ?: [];
        $test->assertSame(true, $remedoraNativeDuplicatePayload['duplicate'] ?? false, 'native Remedora duplicate is idempotent');
        $test->assertSame(8, count(read_jsonl($metaLog)), 'native Remedora duplicate does not send another CAPI request');

        $fallback = http_request('GET', $gatewayBase . '/c/weight-intake?ad_id=%7B%7Bad.id%7D%7D&adset_id=set-456&campaign_id=camp-789&utm_source=facebook');
        $test->assertSame(302, $fallback['status'], 'unexpanded macro click redirects');
        $test->assertContains('/fallback/weight-intake', header_value($fallback, 'location'), 'unexpanded macro goes to public fallback');

        $badConfig = http_request('GET', $gatewayBase . '/c/bad-domains?ad_id=ad&adset_id=set&campaign_id=camp&utm_source=facebook');
        $test->assertSame(500, $badConfig['status'], 'bad campaign allowlist fails closed');

        $missingCampaign = http_request('GET', $gatewayBase . '/c/missing?ad_id=ad&adset_id=set&campaign_id=camp&utm_source=facebook');
        $test->assertSame(500, $missingCampaign['status'], 'missing campaign fails closed');

        $badStart = http_request('GET', $gatewayBase . '/start?cid=bad-token');
        $test->assertSame(400, $badStart['status'], 'invalid start token is rejected');

        $unauthorizedWebhook = http_request(
            'POST',
            $gatewayBase . '/capi/intake-completed',
            ['X-Webhook-Secret' => 'wrong', 'Content-Type' => 'application/json'],
            json_encode(['sid' => $sid, 'event_id' => 'unauthorized'], JSON_UNESCAPED_SLASHES)
        );
        $test->assertSame(401, $unauthorizedWebhook['status'], 'webhook requires shared secret');

        $badWebhook = http_request(
            'POST',
            $gatewayBase . '/capi/intake-completed',
            ['X-Webhook-Secret' => $webhookSecret, 'Content-Type' => 'application/json'],
            json_encode(['sid' => 'bad-token', 'event_id' => 'bad-token'], JSON_UNESCAPED_SLASHES)
        );
        $test->assertSame(400, $badWebhook['status'], 'webhook rejects invalid sid');

        $notFound = http_request('GET', $gatewayBase . '/missing-route');
        $test->assertSame(404, $notFound['status'], 'unknown route returns 404');

        $streamClient = new CapiClient(false, 'test-pixel', 'stream-token', 'v20.0', $metaBase, '', 'stream');
        $streamResult = $streamClient->send([
            'event_name' => 'Lead',
            'event_time' => time(),
            'event_id' => 'stream-event',
            'action_source' => 'website',
            'user_data' => [],
        ]);
        $test->assertSame(200, $streamResult['status_code'], 'stream transport can send to mock Meta');

        $rawClient = new CapiClient(false, 'test-pixel', 'raw', 'v20.0', $metaBase, '', 'curl');
        $rawResult = $rawClient->send([
            'event_name' => 'Lead',
            'event_time' => time(),
            'event_id' => 'raw-response-event',
            'action_source' => 'website',
            'user_data' => [],
        ]);
        $test->assertSame('not-json', $rawResult['response']['raw'] ?? null, 'CAPI client preserves raw non-JSON responses');

        try {
            (new CapiClient(false, 'pixel', 'token', 'v20.0', 'http://127.0.0.1:1', '', 'curl'))->send([
                'event_name' => 'Lead',
                'event_time' => time(),
                'event_id' => 'curl-fail',
                'action_source' => 'website',
                'user_data' => [],
            ]);
            $test->assertTrue(false, 'curl transport surfaces connection failures');
        } catch (RuntimeException) {
            $test->assertTrue(true, 'curl transport surfaces connection failures');
        }

        try {
            (new CapiClient(false, 'pixel', 'token', 'v20.0', 'http://127.0.0.1:1', '', 'stream'))->send([
                'event_name' => 'Lead',
                'event_time' => time(),
                'event_id' => 'stream-fail',
                'action_source' => 'website',
                'user_data' => [],
            ]);
            $test->assertTrue(false, 'stream transport surfaces connection failures');
        } catch (RuntimeException) {
            $test->assertTrue(true, 'stream transport surfaces connection failures');
        }

        $rateLimited = false;
        for ($i = 0; $i < 100; $i++) {
            $rate = http_request('GET', $gatewayBase . '/c/weight-intake?ad_id=rate-' . $i . '&adset_id=set&campaign_id=camp&utm_source=facebook');
            if ($rate['status'] === 429) {
                $rateLimited = true;
                break;
            }
        }
        $test->assertSame(true, $rateLimited, 'gateway rate limits repeated clicks');
    } finally {
        foreach (array_reverse($processes) as $process) {
            stop_php_server($process);
        }
    }

    rm_rf($runtime);
}

/**
 * @return list<array<string, mixed>>
 */
function read_jsonl(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }

    return $rows;
}

/**
 * @param array<string, string> $headers
 * @param array<string, string|null> $queryOverrides
 * @return array{completion_status: int, completion_body: array<string, mixed>, cid: string, sid: string}
 */
function complete_mock_flow(
    string $gatewayBase,
    string $telehealthBase,
    string $campaign,
    string $eventId,
    array $headers = [],
    array $queryOverrides = [],
): array {
    $query = [
        'ad_id' => 'ad-' . $eventId,
        'adset_id' => 'set-' . $eventId,
        'campaign_id' => 'camp-' . $eventId,
        'utm_source' => 'facebook',
        'utm_medium' => 'paid_social',
        'utm_campaign' => 'demo',
        'utm_content' => 'ad',
        'fbclid' => 'fbclid-' . $eventId,
    ];

    foreach ($queryOverrides as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    $click = http_request('GET', $gatewayBase . '/c/' . $campaign . '?' . http_build_query($query), $headers);
    $cid = query_params(header_value($click, 'location'))['cid'] ?? '';
    $start = http_request('GET', $gatewayBase . '/start?cid=' . rawurlencode($cid));
    $sid = query_params(header_value($start, 'location'))['sid'] ?? '';
    http_request('GET', header_value($start, 'location'));
    $completion = http_request('POST', $telehealthBase . '/complete?event_id=' . rawurlencode($eventId));

    return [
        'completion_status' => $completion['status'],
        'completion_body' => json_decode($completion['body'], true) ?: [],
        'cid' => $cid,
        'sid' => $sid,
    ];
}
