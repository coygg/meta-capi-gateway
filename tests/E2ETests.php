<?php

declare(strict_types=1);

use Gateway\Services\TokenService;

function run_e2e_tests(TestHarness $test, string $root): void
{
    echo "\nE2E tests\n";

    $runtime = $root . '/tests/.runtime/e2e-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($runtime, 0777, true);

    $gatewayPort = 18080;
    $telehealthPort = 18081;
    $gatewayBase = 'http://127.0.0.1:' . $gatewayPort;
    $telehealthBase = 'http://127.0.0.1:' . $telehealthPort;
    $coverageDir = $root . '/tests/.runtime/e2e/coverage';
    rm_rf($coverageDir);
    mkdir($coverageDir, 0777, true);

    $telehealthLog = $runtime . '/telehealth-sessions.jsonl';
    $gatewayEnvFile = $runtime . '/gateway.env';
    $processes = [];

    file_put_contents($gatewayEnvFile, implode(PHP_EOL, [
        'APP_ENV=local',
        'APP_BASE_URL=' . $gatewayBase,
        'APP_SECRET=e2e-development-secret-change-before-production-12345',
        'DB_PATH=' . $runtime . '/gateway.sqlite',
        'COOKIE_SECURE=0',
        'TRUST_PROXY=1',
        'CAMPAIGN_CONFIG_PATH=' . $root . '/tests/fixtures/campaigns.e2e.php',
        'TEST_TELEHEALTH_URL=' . $telehealthBase . '/intake/start',
        '',
    ]));

    try {
        $processes[] = start_php_server('127.0.0.1', $telehealthPort, $root . '/tests/mock-telehealth', $runtime . '/mock-telehealth.log', [
            'MOCK_TELEHEALTH_LOG' => $telehealthLog,
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
        $test->assertContains('Quick setup walkthrough', $dashboard['body'], 'first admin dashboard shows walkthrough');
        $test->assertContains('Keep Remedora CAPI on', $dashboard['body'], 'walkthrough explains Remedora remains CAPI sender');
        $csrf = csrf_from_body($dashboard['body']);
        $test->assertTrue($csrf !== '', 'admin dashboard includes CSRF token');

        $dismissWalkthrough = http_request(
            'POST',
            $gatewayBase . '/admin/walkthrough/dismiss',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query(['_csrf' => $csrf])
        );
        $test->assertSame(302, $dismissWalkthrough['status'], 'admin can dismiss first-run walkthrough');

        $dashboardAfterWalkthrough = http_request('GET', $gatewayBase . '/admin', ['Cookie' => $adminCookie]);
        $test->assertTrue(!str_contains($dashboardAfterWalkthrough['body'], 'Quick setup walkthrough'), 'walkthrough stays hidden after dismissal');
        $csrf = csrf_from_body($dashboardAfterWalkthrough['body']);

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
        $test->assertTrue(!str_contains($newCampaignForm['body'], 'CAPI'), 'new campaign form does not expose CAPI fields');

        $campaignPayload = [
            '_csrf' => $csrf,
            'slug' => 'portal-intake',
            'status' => 'active',
            'landing_url' => $gatewayBase . '/intake/portal-intake',
            'form_url' => $telehealthBase . '/intake/start',
            'public_fallback_url' => 'https://google.com/',
            'allowed_domains' => "127.0.0.1\nlocalhost",
            'required_params' => "ad_id\nadset_id\ncampaign_id\nutm_source",
            'accepted_utm_sources' => "facebook\ninstagram",
            'click_token_ttl_seconds' => '1800',
            'form_token_ttl_seconds' => '7200',
            'click_token_param' => 'cid',
            'form_token_param' => 'sid',
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
        $test->assertTrue(!str_contains($editCampaignForm['body'], 'CAPI'), 'edit campaign form does not expose CAPI fields');
        $editCsrf = csrf_from_body($editCampaignForm['body']);

        $badCampaignPayload = $campaignPayload;
        $badCampaignPayload['_csrf'] = $editCsrf;
        $badCampaignPayload['form_url'] = 'not-a-url';
        $badCampaignSave = http_request(
            'POST',
            $gatewayBase . '/admin/campaigns/' . $campaignId,
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => $adminCookie],
            http_build_query($badCampaignPayload)
        );
        $test->assertSame(422, $badCampaignSave['status'], 'edit campaign rejects invalid form URL');

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

        $portalFallback = http_request('GET', $gatewayBase . '/c/portal-intake?ad_id={{ad.id}}&adset_id=portal-set&campaign_id=portal-camp&utm_source=facebook');
        $test->assertSame(302, $portalFallback['status'], 'portal-created DB campaign redirects ineligible click');
        $test->assertSame('https://google.com/', header_value($portalFallback, 'location'), 'campaign fallback can point to an external URL without adding it to allowed domains');

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
            $gatewayBase . '/c/weight-intake?ad_id=ad-123&adset_id=set-456&campaign_id=camp-789&utm_source=facebook&utm_medium=paid_social&utm_campaign=demo&utm_content=ad&utm_term=keyword&fbclid=fbclid-123',
            ['X-Forwarded-For' => '203.0.113.10, 198.51.100.20']
        );
        $test->assertSame(302, $validClick['status'], 'valid expanded Meta click redirects');
        $test->assertTrue(!str_contains(implode("\n", $validClick['headers']['set-cookie'] ?? []), 'pj_fbp'), 'gateway no longer creates Meta browser id cookies');
        $landingLocation = header_value($validClick, 'location');
        $test->assertContains('/intake/weight-intake', $landingLocation, 'valid click lands on static intake');
        $cid = query_params($landingLocation)['cid'] ?? '';
        $test->assertTrue($cid !== '', 'valid click receives signed cid');

        $cookieStart = http_request('GET', $gatewayBase . '/start', ['Cookie' => cookie_header($validClick)]);
        $test->assertSame(302, $cookieStart['status'], 'start can use protected click cookie when cid query is absent');

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
        $test->assertSame('keyword', $formParams['utm_term'] ?? null, 'form redirect forwards UTM term');

        $telehealthStart = http_request('GET', $formLocation);
        $test->assertSame(200, $telehealthStart['status'], 'mock telehealth captures sid');
        $test->assertContains('Mock telehealth intake', $telehealthStart['body'], 'mock telehealth renders intake');
        $telehealthSessions = read_jsonl($telehealthLog);
        $capturedQuery = $telehealthSessions[count($telehealthSessions) - 1]['query'] ?? [];
        $test->assertSame('fbclid-123', $capturedQuery['fbclid'] ?? null, 'telehealth side receives fbclid for its own direct Meta CAPI');
        $test->assertSame($cid, $capturedQuery['cid'] ?? null, 'telehealth side receives gateway cid');
        $test->assertSame($sid, $capturedQuery['sid'] ?? null, 'telehealth side receives gateway sid');

        $completion = http_request('POST', $telehealthBase . '/complete');
        $completionPayload = json_decode($completion['body'], true) ?: [];
        $test->assertSame(200, $completion['status'], 'mock telehealth completion endpoint responds without gateway webhook');
        $test->assertSame($sid, $completionPayload['sid'] ?? null, 'mock telehealth completes with captured session');

        $tokens = new TokenService('e2e-development-secret-change-before-production-12345');
        $unknownClickToken = $tokens->sign([
            'type' => 'click',
            'click_id' => 'missing-click',
            'campaign' => 'weight-intake',
        ], 600);
        $unknownStart = http_request('GET', $gatewayBase . '/start?cid=' . rawurlencode($unknownClickToken));
        $test->assertSame(404, $unknownStart['status'], 'start rejects signed token for missing click');

        $noFbclidFlow = complete_mock_flow(
            $gatewayBase,
            $telehealthBase,
            'weight-intake',
            ['CF-Connecting-IP' => '203.0.113.44'],
            ['fbclid' => null]
        );
        $test->assertSame(200, $noFbclidFlow['completion_status'], 'no-fbclid flow still reaches telehealth');
        $test->assertTrue(!isset($noFbclidFlow['form_params']['fbclid']), 'form redirect omits fbclid when Meta did not provide one');

        $customFlow = complete_mock_flow($gatewayBase, $telehealthBase, 'custom-intake');
        $test->assertSame(200, $customFlow['completion_status'], 'custom campaign flow reaches telehealth');

        $fallback = http_request('GET', $gatewayBase . '/c/weight-intake?ad_id=%7B%7Bad.id%7D%7D&adset_id=set-456&campaign_id=camp-789&utm_source=facebook');
        $test->assertSame(302, $fallback['status'], 'unexpanded macro click redirects');
        $test->assertContains('/fallback/weight-intake', header_value($fallback, 'location'), 'unexpanded macro goes to public fallback');

        $badConfig = http_request('GET', $gatewayBase . '/c/bad-domains?ad_id=ad&adset_id=set&campaign_id=camp&utm_source=facebook');
        $test->assertSame(500, $badConfig['status'], 'bad campaign allowlist fails closed');

        $missingCampaign = http_request('GET', $gatewayBase . '/c/missing?ad_id=ad&adset_id=set&campaign_id=camp&utm_source=facebook');
        $test->assertSame(500, $missingCampaign['status'], 'missing campaign fails closed');

        $badStart = http_request('GET', $gatewayBase . '/start?cid=bad-token');
        $test->assertSame(400, $badStart['status'], 'invalid start token is rejected');

        $removedWebhook = http_request('POST', $gatewayBase . '/capi/intake-completed', ['Content-Type' => 'application/json'], '{}');
        $test->assertSame(404, $removedWebhook['status'], 'gateway CAPI webhook route has been removed');

        $notFound = http_request('GET', $gatewayBase . '/missing-route');
        $test->assertSame(404, $notFound['status'], 'unknown route returns 404');

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
 * @return array{completion_status: int, completion_body: array<string, mixed>, cid: string, sid: string, form_params: array<string, string>}
 */
function complete_mock_flow(
    string $gatewayBase,
    string $telehealthBase,
    string $campaign,
    array $headers = [],
    array $queryOverrides = [],
): array {
    $flowId = bin2hex(random_bytes(4));
    $query = [
        'ad_id' => 'ad-' . $flowId,
        'adset_id' => 'set-' . $flowId,
        'campaign_id' => 'camp-' . $flowId,
        'utm_source' => 'facebook',
        'utm_medium' => 'paid_social',
        'utm_campaign' => 'demo',
        'utm_content' => 'ad',
        'fbclid' => 'fbclid-' . $flowId,
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
    $formLocation = header_value($start, 'location');
    $formParams = query_params($formLocation);
    $sid = $formParams['sid'] ?? '';
    http_request('GET', $formLocation);
    $completion = http_request('POST', $telehealthBase . '/complete');

    return [
        'completion_status' => $completion['status'],
        'completion_body' => json_decode($completion['body'], true) ?: [],
        'cid' => $cid,
        'sid' => $sid,
        'form_params' => $formParams,
    ];
}
