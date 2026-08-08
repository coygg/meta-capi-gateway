<?php

declare(strict_types=1);

use Gateway\AdminController;
use Gateway\App;
use Gateway\Config;
use Gateway\Database;
use Gateway\Env;
use Gateway\Services\AdminRepository;
use Gateway\Services\CampaignRepository;
use Gateway\Services\ClickRepository;
use Gateway\Services\ClickValidator;
use Gateway\Services\DomainRepository;
use Gateway\Services\RateLimiter;
use Gateway\Services\TokenService;
use Gateway\Services\UpdateRepository;
use Gateway\Services\UpdateService;
use Gateway\Support\Cookie;
use Gateway\Support\Response;
use Gateway\Support\Url;

function run_unit_tests(TestHarness $test, string $root): void
{
    echo "\nUnit tests\n";
    rm_rf($root . '/tests/.runtime/unit');
    $responseBody = static fn (Response $response): string => (fn (): string => $this->body)->call($response);
    $responseStatus = static fn (Response $response): int => (fn (): int => $this->status)->call($response);

    $tokens = new TokenService(str_repeat('a', 32));
    $token = $tokens->sign(['type' => 'click', 'click_id' => 'abc', 'campaign' => 'demo'], 60);
    $verified = $tokens->verify($token, 'click');
    $test->assertSame(true, $verified['valid'], 'valid signed click token verifies');
    $test->assertSame('abc', $verified['claims']['click_id'] ?? null, 'token claims survive round trip');
    $test->assertSame(false, $tokens->verify('not-a-token', 'click')['valid'], 'malformed token fails');
    $test->assertSame(false, $tokens->verify($token . 'x', 'click')['valid'], 'tampered token fails');
    $test->assertSame(false, $tokens->verify($token, 'form')['valid'], 'wrong token type fails');
    $expired = $tokens->sign(['type' => 'click'], -1);
    $test->assertSame('expired_token', $tokens->verify($expired, 'click')['reason'], 'expired token fails');
    $badPayload = base64url('not-json');
    $badPayloadToken = $badPayload . '.' . base64url(hash_hmac('sha256', $badPayload, str_repeat('a', 32), true));
    $test->assertSame('bad_payload', $tokens->verify($badPayloadToken)['reason'], 'bad token payload fails');

    $validator = new ClickValidator();
    $campaign = [
        'required_params' => ['ad_id', 'adset_id', 'campaign_id', 'utm_source'],
        'accepted_utm_sources' => ['facebook', 'instagram'],
    ];
    $test->assertSame(true, $validator->validate($campaign, [
        'ad_id' => '123',
        'adset_id' => '456',
        'campaign_id' => '789',
        'utm_source' => 'facebook',
    ])['valid'], 'expanded Meta params pass');
    $test->assertSame('unexpanded_ad_id', $validator->validate($campaign, [
        'ad_id' => '{{ad.id}}',
        'adset_id' => '456',
        'campaign_id' => '789',
        'utm_source' => 'facebook',
    ])['reason'], 'unexpanded macro fails');
    $test->assertSame('missing_ad_id', $validator->validate($campaign, [
        'adset_id' => '456',
        'campaign_id' => '789',
        'utm_source' => 'facebook',
    ])['reason'], 'missing required param fails');
    $test->assertSame('unexpected_utm_source', $validator->validate($campaign, [
        'ad_id' => '123',
        'adset_id' => '456',
        'campaign_id' => '789',
        'utm_source' => 'unknown',
    ])['reason'], 'unexpected UTM source fails');
    $test->assertSame('bad_required_param_config', $validator->validate(['required_params' => 'bad'], [])['reason'], 'bad validator config fails closed');
    $test->assertSame(true, $validator->validate(['required_params' => [123]], [])['valid'], 'non-string required param entries are ignored');

    $url = Url::appendQuery('https://example.com/path?a=1#x', ['cid' => 'token value']);
    $test->assertSame('https://example.com/path?a=1&cid=token+value#x', $url, 'URL query append preserves existing query and fragment');
    $authUrl = Url::appendQuery('https://user:pass@example.com:8443/path', ['sid' => 'abc']);
    $test->assertSame('https://user:pass@example.com:8443/path?sid=abc', $authUrl, 'URL append preserves auth and port');
    Url::assertAllowed('https://safe.example.com/start', ['*.example.com']);
    $test->assertTrue(true, 'wildcard allowlist permits subdomain');
    Url::assertAllowed('https://example.com/start', ['', 'example.com']);
    $test->assertTrue(true, 'allowlist skips empty entries and permits exact host');

    try {
        Url::assertAllowed('https://evil.test/start', ['*.example.com']);
        $test->assertTrue(false, 'allowlist blocks unrelated host');
    } catch (Throwable) {
        $test->assertTrue(true, 'allowlist blocks unrelated host');
    }

    try {
        Url::appendQuery('/relative', ['x' => '1']);
        $test->assertTrue(false, 'relative redirect URL is rejected');
    } catch (InvalidArgumentException) {
        $test->assertTrue(true, 'relative redirect URL is rejected');
    }

    try {
        Url::assertAllowed('/relative', ['example.com']);
        $test->assertTrue(false, 'allowlist rejects URL without host');
    } catch (InvalidArgumentException) {
        $test->assertTrue(true, 'allowlist rejects URL without host');
    }

    $cookie = Cookie::make('demo', 'value', 60, true);
    $test->assertContains('Secure', $cookie, 'secure cookie includes Secure flag');
    $test->assertContains('HttpOnly', $cookie, 'cookie includes HttpOnly flag');

    $json = Response::json(['ok' => true]);
    $test->assertTrue($json instanceof Response, 'JSON response object can be created');

    putenv('CAMPAIGN_CONFIG_PATH=');
    putenv('APP_SECRET=' . str_repeat('b', 40));
    $defaultPathConfig = Config::load($root);
    $test->assertTrue($defaultPathConfig->campaign('weight-intake') !== null, 'empty campaign config path falls back to default config');

    putenv('APP_BASE_URL');
    putenv('RENDER_EXTERNAL_URL=https://render-generated.example.com');
    $renderUrlConfig = Config::load($root);
    $test->assertSame('https://render-generated.example.com', $renderUrlConfig->string('base_url'), 'config uses Render external URL when APP_BASE_URL is unset');
    putenv('RENDER_EXTERNAL_URL');

    putenv('APP_VERSION=unit-version-sha');
    $versionConfig = Config::load($root);
    $test->assertSame('unit-version-sha', $versionConfig->string('version'), 'config exposes the deployed app version when provided');
    putenv('APP_VERSION');

    putenv('DB_PATH=tests/.runtime/unit/relative-db.sqlite');
    $relativeDbConfig = Config::load($root);
    $test->assertSame($root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . '.runtime' . DIRECTORY_SEPARATOR . 'unit' . DIRECTORY_SEPARATOR . 'relative-db.sqlite', $relativeDbConfig->dbPath($root), 'relative DB path resolves under project root');

    $tmp = $root . '/tests/.runtime/unit/nested/unit-db.sqlite';
    putenv('APP_SECRET=' . str_repeat('b', 40));
    putenv('DB_PATH=' . $tmp);
    putenv('APP_BASE_URL=http://127.0.0.1:18080');
    putenv('CAMPAIGN_CONFIG_PATH=tests/fixtures/campaigns.e2e.php');

    $config = Config::load($root);
    $test->assertTrue(is_array($config->app()), 'config exposes app array');
    $test->assertSame(null, $config->campaign('missing-campaign'), 'missing campaign returns null');
    $test->assertSame($tmp, $config->dbPath($root), 'absolute DB path is preserved');
    $database = Database::fromConfig($root, $config);
    $database->migrate();
    $adminRepo = new AdminRepository($database->pdo());
    $test->assertSame(false, $adminRepo->verifyPassword('missing-admin'), 'admin password check fails when no admin exists');
    $adminRepo->createAdmin('unit-admin-password');
    $test->assertSame(false, $adminRepo->walkthroughCompleted(), 'new admin has not completed walkthrough');
    $adminRepo->completeWalkthrough();
    $test->assertSame(true, $adminRepo->walkthroughCompleted(), 'admin walkthrough completion is stored');
    $test->assertSame(false, $adminRepo->verifyPassword('wrong-password'), 'admin password check rejects wrong password');
    $test->assertSame(true, $adminRepo->verifyPassword('unit-admin-password'), 'admin password check accepts correct password');
    try {
        $adminRepo->createAdmin('second-password');
        $test->assertTrue(false, 'admin repository prevents duplicate admin creation');
    } catch (RuntimeException) {
        $test->assertTrue(true, 'admin repository prevents duplicate admin creation');
    }
    $database->pdo()->exec("UPDATE admin_users SET password_hash = '" . password_hash('rehash-password', PASSWORD_BCRYPT, ['cost' => 4]) . "'");
    $test->assertSame(true, $adminRepo->verifyPassword('rehash-password'), 'admin password verify rehashes old hashes');

    $repo = new ClickRepository($database->pdo());
    $repo->recordClick([
        'click_id' => 'unit-click',
        'campaign_slug' => 'weight-intake',
        'decision' => 'allow',
        'landing_url' => 'http://127.0.0.1:18080/intake/weight-intake',
        'client_ip' => '127.0.0.1',
        'client_ip_hash' => 'iphash',
        'client_user_agent' => 'Unit Test',
        'user_agent_hash' => 'uahash',
        'query' => ['ad_id' => 'ad'],
        'created_at' => ClickRepository::now(),
    ]);
    $test->assertSame('unit-click', $repo->findClick('unit-click')['click_id'] ?? null, 'repository stores clicks');
    $repo->createFormSession('unit-session', 'unit-form-token', 'unit-click', 'weight-intake', gmdate('c', time() + 60));
    $test->assertSame('unit-session', $repo->findFormSession('unit-session')['session_id'] ?? null, 'repository stores form sessions');
    $test->assertSame('unit-form-token', $repo->findActiveFormSessionForClick('unit-click', ClickRepository::now())['form_token'] ?? null, 'repository finds reusable active form session');
    $test->assertSame(null, $repo->findActiveFormSessionForClick('unit-click', gmdate('c', time() + 120)), 'repository ignores expired form sessions');

    $legacyAdminDb = $root . '/tests/.runtime/unit/legacy-admin.sqlite';
    $legacyPdo = new PDO('sqlite:' . $legacyAdminDb);
    $legacyPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $legacyPdo->exec(
        "CREATE TABLE admin_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )"
    );
    $legacyPdo = null;
    putenv('DB_PATH=' . $legacyAdminDb);
    Database::fromConfig($root, Config::load($root))->migrate();
    $legacyCheck = new PDO('sqlite:' . $legacyAdminDb);
    $legacyColumns = array_map(
        static fn (array $row): string => (string) ($row['name'] ?? ''),
        $legacyCheck->query('PRAGMA table_info(admin_users)')->fetchAll(PDO::FETCH_ASSOC)
    );
    $test->assertTrue(in_array('walkthrough_completed_at', $legacyColumns, true), 'migration adds walkthrough flag to legacy admin table');
    putenv('DB_PATH=' . $tmp);

    $limiter = new RateLimiter($database->pdo());
    $test->assertSame(false, $limiter->exceeded('unit-limit', 2, 60), 'rate limiter permits first hit');
    $test->assertSame(false, $limiter->exceeded('unit-limit', 2, 60), 'rate limiter permits second hit');
    $test->assertSame(true, $limiter->exceeded('unit-limit', 2, 60), 'rate limiter blocks above threshold');

    $domainRepo = new DomainRepository($database->pdo(), $config);
    $test->assertSame('127.0.0.1', $domainRepo->cnameTarget(), 'domain repository derives CNAME target from APP_BASE_URL');
    $test->assertSame('track.example.com', DomainRepository::normalizeHostname('https://Track.Example.com/path'), 'domain normalization extracts host from URL');
    try {
        DomainRepository::normalizeHostname('');
        $test->assertTrue(false, 'empty domain is rejected');
    } catch (InvalidArgumentException) {
        $test->assertTrue(true, 'empty domain is rejected');
    }
    try {
        DomainRepository::normalizeHostname('bad host name');
        $test->assertTrue(false, 'invalid domain is rejected');
    } catch (InvalidArgumentException) {
        $test->assertTrue(true, 'invalid domain is rejected');
    }
    $domainRepo->add('localhost');
    $test->assertSame(['localhost'], $domainRepo->activeHostnames(), 'localhost domain is active after add');
    $domainRepo->verify(1);
    $domainRepo->delete(1);
    $test->assertSame([], $domainRepo->activeHostnames(), 'domain delete removes active domain');
    try {
        $domainRepo->verify(9999);
        $test->assertTrue(false, 'domain verify rejects missing domain');
    } catch (RuntimeException) {
        $test->assertTrue(true, 'domain verify rejects missing domain');
    }

    putenv('GATEWAY_CNAME_TARGET=track.example.com');
    $configuredDomainRepo = new DomainRepository($database->pdo(), Config::load($root));
    $test->assertSame('track.example.com', $configuredDomainRepo->cnameTarget(), 'domain repository accepts configured CNAME target');
    $configuredDomainRepo->add('pending.example.com');
    $configuredDomainRepo->verify(2);
    $test->assertSame([], $configuredDomainRepo->activeHostnames(), 'domain verify records pending status for non-matching DNS');
    $matchingDomainRepo = new DomainRepository($database->pdo(), Config::load($root), static fn (string $hostname): array => [
        ['target' => 'track.example.com.'],
    ]);
    $matchingDomainRepo->add('match.example.com');
    $matchingDomainRepo->verify(3);
    $test->assertTrue(in_array('match.example.com', $matchingDomainRepo->activeHostnames(), true), 'domain verify activates matching CNAME');
    putenv('APP_BASE_URL=not-a-url');
    putenv('GATEWAY_CNAME_TARGET=');
    $test->assertSame('gateway.example.com', (new DomainRepository($database->pdo(), Config::load($root)))->cnameTarget(), 'domain repository falls back when APP_BASE_URL has no host');
    putenv('APP_BASE_URL=http://127.0.0.1:18080');
    putenv('GATEWAY_CNAME_TARGET=');

    $updateRepo = new UpdateRepository($database->pdo());
    $defaultUpdateSettings = $updateRepo->settings();
    $test->assertSame('https://github.com/coygg/meta-capi-gateway', $defaultUpdateSettings['repo_url'], 'update repository has a default GitHub repo');
    $test->assertSame('main', $defaultUpdateSettings['branch'], 'update repository has a default branch');
    $updateRepo->saveSettings('coygg/meta-capi-gateway', 'main', '');
    $test->assertSame('https://github.com/coygg/meta-capi-gateway', $updateRepo->settings()['repo_url'], 'update repository normalizes owner/repo shorthand');
    $updateRepo->saveSettings('https://github.com/coygg/meta-capi-gateway.git', 'release/main', 'https://api.render.com/deploy/srv-demo?key=secret');
    $savedUpdateSettings = $updateRepo->settings();
    $test->assertSame('https://github.com/coygg/meta-capi-gateway', $savedUpdateSettings['repo_url'], 'update repository strips .git suffixes');
    $test->assertSame('release/main', $savedUpdateSettings['branch'], 'update repository stores branch names');
    $test->assertSame('https://api.render.com/deploy/srv-demo?key=secret', $savedUpdateSettings['deploy_hook_url'], 'update repository stores deploy hook URLs');
    $updateRepo->markLatestCommit(str_repeat('a', 40), 'https://github.com/coygg/meta-capi-gateway/commit/' . str_repeat('a', 40));
    $test->assertSame(str_repeat('a', 40), $updateRepo->settings()['latest_commit_sha'], 'update repository stores latest checked commit');
    $updateRepo->markDeployTriggered(202);
    $test->assertSame('HTTP 202', $updateRepo->settings()['last_deploy_status'], 'update repository records successful deploy requests');
    $updateRepo->markDeployFailed(str_repeat('x', 300));
    $test->assertSame(240, strlen($updateRepo->settings()['last_deploy_status']), 'update repository truncates long deploy failures');

    foreach ([
        ['repo' => '', 'branch' => 'main', 'hook' => '', 'message' => 'update repository rejects empty repo'],
        ['repo' => 'http://github.com/coygg/meta-capi-gateway', 'branch' => 'main', 'hook' => '', 'message' => 'update repository rejects non-HTTPS repo'],
        ['repo' => 'https://github.com/coygg', 'branch' => 'main', 'hook' => '', 'message' => 'update repository rejects repo without owner/name'],
        ['repo' => 'https://github.com/coygg/meta-capi-gateway', 'branch' => '', 'hook' => '', 'message' => 'update repository rejects empty branch'],
        ['repo' => 'https://github.com/coygg/meta-capi-gateway', 'branch' => '../main', 'hook' => '', 'message' => 'update repository rejects unsafe branch'],
        ['repo' => 'https://github.com/coygg/meta-capi-gateway', 'branch' => 'main', 'hook' => 'http://example.com/hook', 'message' => 'update repository rejects non-HTTPS deploy hooks'],
    ] as $case) {
        try {
            $updateRepo->saveSettings($case['repo'], $case['branch'], $case['hook']);
            $test->assertTrue(false, $case['message']);
        } catch (InvalidArgumentException) {
            $test->assertTrue(true, $case['message']);
        }
    }

    $serviceCalls = [];
    $updateService = new UpdateService(static function (string $method, string $url, array $headers, ?string $body) use (&$serviceCalls): array {
        $serviceCalls[] = compact('method', 'url', 'headers', 'body');

        if ($method === 'POST') {
            return ['status' => 202, 'body' => 'deploy queued'];
        }

        return [
            'status' => 200,
            'body' => json_encode([
                'sha' => str_repeat('b', 40),
                'html_url' => 'https://github.com/coygg/meta-capi-gateway/commit/' . str_repeat('b', 40),
            ], JSON_THROW_ON_ERROR),
        ];
    });
    $latestCommit = $updateService->latestCommit('https://github.com/coygg/meta-capi-gateway.git', 'main');
    $test->assertSame(str_repeat('b', 40), $latestCommit['sha'], 'update service parses latest GitHub commit');
    $test->assertSame('bbbbbbb', $latestCommit['short_sha'], 'update service exposes short commit sha');
    $deployResult = $updateService->triggerDeploy('https://api.render.com/deploy/srv-demo?key=secret');
    $test->assertSame(202, $deployResult['status'], 'update service triggers deploy hooks');
    $test->assertSame('GET', $serviceCalls[0]['method'] ?? null, 'update service checks GitHub with GET');
    $test->assertSame('POST', $serviceCalls[1]['method'] ?? null, 'update service triggers deploy hook with POST');

    foreach ([
        ['service' => new UpdateService(static fn (): array => ['status' => 500, 'body' => '{}']), 'method' => 'latest', 'message' => 'update service rejects failed GitHub checks'],
        ['service' => new UpdateService(static fn (): array => ['status' => 200, 'body' => '{}']), 'method' => 'latest', 'message' => 'update service rejects malformed GitHub responses'],
        ['service' => $updateService, 'method' => 'latest_bad_repo', 'message' => 'update service rejects non-GitHub repos'],
        ['service' => $updateService, 'method' => 'latest_missing_repo', 'message' => 'update service rejects incomplete GitHub repos'],
        ['service' => $updateService, 'method' => 'deploy_empty', 'message' => 'update service requires deploy hook before deploy'],
        ['service' => $updateService, 'method' => 'deploy_bad_url', 'message' => 'update service rejects invalid deploy hook URLs'],
        ['service' => new UpdateService(static fn (): array => ['status' => 500, 'body' => 'nope']), 'method' => 'deploy_failed', 'message' => 'update service rejects failed deploy hooks'],
    ] as $case) {
        try {
            if ($case['method'] === 'latest') {
                $case['service']->latestCommit('coygg/meta-capi-gateway', 'main');
            } elseif ($case['method'] === 'latest_bad_repo') {
                $case['service']->latestCommit('https://example.com/coygg/meta-capi-gateway', 'main');
            } elseif ($case['method'] === 'latest_missing_repo') {
                $case['service']->latestCommit('https://github.com/coygg', 'main');
            } elseif ($case['method'] === 'deploy_empty') {
                $case['service']->triggerDeploy('');
            } elseif ($case['method'] === 'deploy_bad_url') {
                $case['service']->triggerDeploy('http://example.com/hook');
            } else {
                $case['service']->triggerDeploy('https://api.render.com/deploy/srv-demo?key=secret');
            }
            $test->assertTrue(false, $case['message']);
        } catch (RuntimeException) {
            $test->assertTrue(true, $case['message']);
        }
    }

    $campaignRepo = new CampaignRepository($database->pdo());
    $_SESSION = ['admin_authenticated' => true];
    $emptyAdmin = new AdminController($config, $adminRepo, $domainRepo, $campaignRepo, $updateRepo, $updateService);
    $database->pdo()->exec('UPDATE admin_users SET walkthrough_completed_at = NULL');
    $emptyDashboard = $emptyAdmin->handle('GET', '/admin');
    $test->assertContains('Quick setup walkthrough', $responseBody($emptyDashboard), 'admin dashboard renders first-run walkthrough');
    $test->assertContains('No campaigns yet.', $responseBody($emptyDashboard), 'admin dashboard handles empty campaign database');
    $test->assertContains('Updates', $responseBody($emptyDashboard), 'admin dashboard renders update panel');
    $adminRepo->completeWalkthrough();
    $hiddenWalkthroughDashboard = $emptyAdmin->handle('GET', '/admin');
    $test->assertTrue(!str_contains($responseBody($hiddenWalkthroughDashboard), 'Quick setup walkthrough'), 'admin dashboard hides completed walkthrough');
    $newCampaignResponse = $emptyAdmin->handle('GET', '/admin/campaigns/new');
    $test->assertContains('href="/admin">Cancel</a>', $responseBody($newCampaignResponse), 'admin campaign form renders cancel link');
    $_SESSION = ['admin_authenticated' => true, '_csrf' => 'unit-csrf'];
    $_POST = [
        '_csrf' => 'unit-csrf',
        'repo_url' => 'coygg/meta-capi-gateway',
        'branch' => 'main',
        'deploy_hook_url' => 'https://api.render.com/deploy/srv-demo?key=secret',
    ];
    $test->assertSame(302, $responseStatus($emptyAdmin->handle('POST', '/admin/updates/settings')), 'admin can save update settings');
    $test->assertSame('https://api.render.com/deploy/srv-demo?key=secret', $updateRepo->settings()['deploy_hook_url'], 'admin update settings save persists deploy hook');
    $_POST = [
        '_csrf' => 'unit-csrf',
        'repo_url' => 'coygg/meta-capi-gateway',
        'branch' => 'main',
        'deploy_hook_url' => '',
    ];
    $test->assertSame(302, $responseStatus($emptyAdmin->handle('POST', '/admin/updates/settings')), 'admin update settings keep deploy hook when field is blank');
    $test->assertSame('https://api.render.com/deploy/srv-demo?key=secret', $updateRepo->settings()['deploy_hook_url'], 'blank deploy hook field preserves existing secret');
    $_POST = [
        '_csrf' => 'unit-csrf',
        'repo_url' => 'coygg/meta-capi-gateway',
        'branch' => 'main',
        'deploy_hook_url' => '',
        'clear_deploy_hook' => '1',
    ];
    $test->assertSame(302, $responseStatus($emptyAdmin->handle('POST', '/admin/updates/settings')), 'admin update settings can clear deploy hook');
    $test->assertSame('', $updateRepo->settings()['deploy_hook_url'], 'clear checkbox removes existing deploy hook');
    $_POST = [
        '_csrf' => 'unit-csrf',
        'repo_url' => 'coygg/meta-capi-gateway',
        'branch' => 'main',
        'deploy_hook_url' => 'https://api.render.com/deploy/srv-demo?key=secret',
    ];
    $emptyAdmin->handle('POST', '/admin/updates/settings');
    $_POST = ['_csrf' => 'unit-csrf', 'repo_url' => 'not-a-url', 'branch' => 'main', 'deploy_hook_url' => ''];
    $test->assertSame(302, $responseStatus($emptyAdmin->handle('POST', '/admin/updates/settings')), 'admin update settings validation redirects with flash');
    $_POST = ['_csrf' => 'unit-csrf'];
    $test->assertSame(302, $responseStatus($emptyAdmin->handle('POST', '/admin/updates/check')), 'admin can check for updates');
    $test->assertSame(str_repeat('b', 40), $updateRepo->settings()['latest_commit_sha'], 'admin update check stores latest commit');
    putenv('APP_VERSION=' . str_repeat('b', 40));
    $matchingVersionConfig = Config::load($root);
    putenv('APP_VERSION');
    $matchingVersionAdmin = new AdminController($matchingVersionConfig, $adminRepo, $domainRepo, $campaignRepo, $updateRepo, $updateService);
    $test->assertSame(302, $responseStatus($matchingVersionAdmin->handle('POST', '/admin/updates/check')), 'admin update check recognizes the current version when exposed');
    $versionedDashboard = $matchingVersionAdmin->handle('GET', '/admin');
    $test->assertContains(substr(str_repeat('b', 40), 0, 12), $responseBody($versionedDashboard), 'admin update panel renders exposed current version');
    $test->assertSame(302, $responseStatus($emptyAdmin->handle('POST', '/admin/updates/deploy')), 'admin can trigger deploy hook');
    $test->assertSame('HTTP 202', $updateRepo->settings()['last_deploy_status'], 'admin deploy action stores deploy result');
    $errorAdmin = new AdminController($config, $adminRepo, $domainRepo, $campaignRepo, $updateRepo, new UpdateService(static fn (): array => ['status' => 500, 'body' => '{}']));
    $test->assertSame(302, $responseStatus($errorAdmin->handle('POST', '/admin/updates/check')), 'admin update check handles provider errors');
    $test->assertSame(302, $responseStatus($errorAdmin->handle('POST', '/admin/updates/deploy')), 'admin deploy handles provider errors');
    $_SESSION = [];
    $_POST = [];

    $previousServer = $_SERVER;
    $previousGet = $_GET;
    $previousCookie = $_COOKIE;
    $_SERVER = array_merge($_SERVER, [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/c/bad-domains?ad_id=ad&adset_id=set&campaign_id=camp&utm_source=facebook',
        'HTTP_HOST' => '127.0.0.1:18080',
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_USER_AGENT' => 'Unit Test',
    ]);
    $_GET = [
        'ad_id' => 'ad',
        'adset_id' => 'set',
        'campaign_id' => 'camp',
        'utm_source' => 'facebook',
    ];
    $_COOKIE = [];
    $fallbackConfigApp = new App(
        $config,
        new ClickRepository($database->pdo()),
        $campaignRepo,
        new TokenService($config->appSecret()),
        new ClickValidator(),
        new RateLimiter($database->pdo()),
        $emptyAdmin,
    );
    $badDomainResponse = $fallbackConfigApp->handle();
    $test->assertSame(500, $responseStatus($badDomainResponse), 'fallback config campaign with malformed allowed domains fails closed');
    $_SERVER = $previousServer;
    $_GET = $previousGet;
    $_COOKIE = $previousCookie;

    $callAppPrivate = static function (App $app, string $method, mixed ...$args): mixed {
        $reflection = new ReflectionMethod($app, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($app, ...$args);
    };

    $test->assertSame(7200, $callAppPrivate($fallbackConfigApp, 'remainingTtl', 'not-a-date', 7200), 'remaining TTL falls back when stored expiry is malformed');

    $forwardParams = $callAppPrivate($fallbackConfigApp, 'formForwardParams', [
        'form_token_param' => 'form_ref',
    ], [
        'fbclid' => 'unit-fbclid',
        'ad_id' => 'unit-ad',
        'adset_id' => 'unit-adset',
        'meta_campaign_id' => 'unit-campaign',
        'utm_source' => 'facebook',
        'utm_medium' => 'paid_social',
        'utm_campaign' => 'unit-demo',
        'utm_content' => 'unit-ad-name',
        'query_json' => '{"utm_term":"unit-keyword"}',
    ], 'unit-click-token', 'unit-form-token');
    $test->assertSame('unit-form-token', $forwardParams['form_ref'] ?? null, 'form redirect supports custom token param alias');
    $test->assertSame('unit-form-token', $forwardParams['sid'] ?? null, 'form redirect always includes sid alias');
    $test->assertSame('unit-click-token', $forwardParams['cid'] ?? null, 'form redirect includes click token fallback');
    $test->assertSame('unit-keyword', $forwardParams['utm_term'] ?? null, 'form redirect can forward UTM term from raw query');

    $test->assertSame(
        [],
        $callAppPrivate($fallbackConfigApp, 'allowedDomains', ['allowed_domains' => 'bad-config']),
        'malformed allowed domains fail closed as an empty list'
    );

    $campaignRepo->seedFromConfig($config->campaigns());
    $_SESSION = ['admin_authenticated' => true];
    $seededDashboard = $emptyAdmin->handle('GET', '/admin');
    $test->assertContains('ad_id={{ad.id}}', $responseBody($seededDashboard), 'admin dashboard renders full Meta ad URL');
    $_SESSION = [];
    $campaignRepo->seedFromConfig($config->campaigns());
    $test->assertTrue(count($campaignRepo->all()) >= 1, 'campaign repository seeds config campaigns');
    $test->assertTrue($campaignRepo->findActive('weight-intake') !== null, 'campaign repository returns active campaign');

    $editedSeed = $campaignRepo->findBySlug('weight-intake');
    $editedSeed['form_url'] = 'https://changed-destination.example.com/intake/start';
    $campaignRepo->save($editedSeed);
    $campaignRepo->seedFromConfig($config->campaigns());
    $campaignRepo->seedFromConfig(['WEIGHT-INTAKE' => $config->campaign('weight-intake')]);
    $campaignRepo->seedFromConfig([' weight-intake ' => $config->campaign('weight-intake')]);
    $test->assertSame(
        'https://changed-destination.example.com/intake/start',
        $campaignRepo->findBySlug('weight-intake')['form_url'] ?? null,
        'admin-edited destination survives config re-seeding, including case and whitespace key variants'
    );
    $test->assertTrue(
        in_array('changed-destination.example.com', $campaignRepo->findBySlug('weight-intake')['allowed_domains'] ?? [], true),
        'saving a changed destination auto-allows its host'
    );
    $seededCount = count($campaignRepo->all());
    $campaignRepo->seedFromConfig(['bad slug!' => ['landing_url' => 'not-a-url']]);
    $campaignRepo->seedFromConfig(['scalar-entry' => 'https://forms.example.com/intake/start']);
    $test->assertSame($seededCount, count($campaignRepo->all()), 'invalid config entries are skipped without breaking seeding');
    $test->assertSame(null, $campaignRepo->findBySlug('scalar-entry'), 'scalar config entries are skipped without crashing every route');

    $normalizedAppend = $campaignRepo->normalize([
        'slug' => 'append-hosts',
        'status' => 'active',
        'landing_url' => 'https://landers.example.net/offer',
        'form_url' => 'https://forms.example.org/intake/start',
        'public_fallback_url' => 'https://elsewhere.example.com/info',
        'allowed_domains' => ['forms.example.org', 'extra.example.com'],
    ]);
    $test->assertSame(
        ['forms.example.org', 'extra.example.com', 'landers.example.net'],
        $normalizedAppend['allowed_domains'],
        'saving auto-allows lander and destination form hosts without duplicates'
    );
    $test->assertSame(
        false,
        in_array('elsewhere.example.com', $normalizedAppend['allowed_domains'], true),
        'fallback host is allowed at redirect time, not stored in the allowlist'
    );
    $test->assertSame(null, $campaignRepo->findById(9999), 'missing campaign id returns null');
    $test->assertSame(null, $campaignRepo->findActive('missing-campaign'), 'missing active campaign returns null');
    $pausedCampaign = [
        'slug' => 'unit-paused',
        'status' => 'unknown',
        'landing_url' => 'https://example.com/lander',
        'form_url' => 'https://example.com/form',
        'public_fallback_url' => 'https://example.com/fallback',
        'allowed_domains' => 'example.com',
        'required_params' => ['ad_id'],
        'accepted_utm_sources' => ['facebook'],
    ];
    $campaignRepo->save($pausedCampaign);
    $test->assertSame(null, $campaignRepo->findActive('unit-paused'), 'unknown campaign status normalizes to paused');
    $pausedCampaign['status'] = 'active';
    $pausedCampaign['allowed_domains'] = "example.com\ntelehealth.example.com";
    $pausedCampaign['accepted_utm_sources'] = new stdClass();
    $id = $campaignRepo->save($pausedCampaign);
    $test->assertSame($id, $campaignRepo->findBySlug('unit-paused')['id'] ?? null, 'campaign repository updates existing campaign');
    $test->assertSame('example.com', $campaignRepo->findBySlug('unit-paused')['allowed_domains'][0] ?? null, 'campaign repository parses newline lists');

    $database->pdo()->exec("ALTER TABLE campaigns ADD COLUMN event_source_url TEXT NOT NULL DEFAULT ''");
    $database->pdo()->exec("ALTER TABLE campaigns ADD COLUMN capi_event_name TEXT NOT NULL DEFAULT ''");
    $database->pdo()->exec("ALTER TABLE campaigns ADD COLUMN capi_custom_data_json TEXT NOT NULL DEFAULT ''");
    $legacyCampaignRepo = new CampaignRepository($database->pdo());
    $legacyCampaign = $pausedCampaign;
    $legacyCampaign['slug'] = 'legacy-columns';
    $legacyCampaign['status'] = 'active';
    $legacyCampaign['fallback_title'] = 'Legacy compatible';
    $legacyCampaignRepo->save($legacyCampaign);
    $legacyRow = $database->pdo()->query("SELECT event_source_url, capi_event_name, capi_custom_data_json FROM campaigns WHERE slug = 'legacy-columns'")->fetch();
    $test->assertSame('https://example.com/lander', $legacyRow['event_source_url'] ?? null, 'legacy event source column receives harmless lander URL');
    $test->assertSame('Lead', $legacyRow['capi_event_name'] ?? null, 'legacy CAPI event column receives generic default');
    $test->assertSame('[]', $legacyRow['capi_custom_data_json'] ?? null, 'legacy CAPI custom data column stays empty');

    try {
        $campaignRepo->normalize(['slug' => 'x', 'landing_url' => 'bad']);
        $test->assertTrue(false, 'campaign repository rejects invalid campaign slug');
    } catch (InvalidArgumentException) {
        $test->assertTrue(true, 'campaign repository rejects invalid campaign slug');
    }
    try {
        $campaignRepo->normalize([
            'slug' => 'valid-slug',
            'landing_url' => 'not-a-url',
            'form_url' => 'https://example.com/form',
            'public_fallback_url' => 'https://example.com/fallback',
        ]);
        $test->assertTrue(false, 'campaign repository rejects invalid campaign URL');
    } catch (InvalidArgumentException) {
        $test->assertTrue(true, 'campaign repository rejects invalid campaign URL');
    }
    try {
        $campaignRepo->normalize([
            'slug' => 'valid-slug',
            'landing_url' => 'https://example.com/lander',
            'form_url' => 'mailto:intake@example.com',
            'public_fallback_url' => 'https://example.com/fallback',
        ]);
        $test->assertTrue(false, 'campaign repository rejects non-web destination URLs');
    } catch (InvalidArgumentException) {
        $test->assertTrue(true, 'campaign repository rejects non-web destination URLs');
    }

    Env::load($root . '/does-not-exist.env');
    $test->assertSame('fallback', Env::get('MISSING_ENV_FOR_TEST', 'fallback'), 'Env default works for missing keys');
    putenv('BOOL_TEST=true');
    $test->assertSame(true, Env::bool('BOOL_TEST'), 'Env bool parses true');
    putenv('INT_TEST=42');
    $test->assertSame(42, Env::int('INT_TEST', 0), 'Env int parses numeric values');
    putenv('INT_TEST_BAD=nope');
    $test->assertSame(7, Env::int('INT_TEST_BAD', 7), 'Env int falls back for non-numeric values');

    $envFile = $root . '/tests/.runtime/unit/env-file.env';
    file_put_contents($envFile, implode(PHP_EOL, [
        '# comment',
        '',
        'NO_EQUALS',
        '=empty-key',
        'QUOTED_ENV="quoted value"',
        "SINGLE_QUOTED_ENV='single quoted'",
        'PRESET_ENV=file-value',
        '',
    ]));
    putenv('PRESET_ENV=already-set');
    Env::load($envFile);
    $test->assertSame('quoted value', Env::get('QUOTED_ENV'), 'Env strips double quotes');
    $test->assertSame('single quoted', Env::get('SINGLE_QUOTED_ENV'), 'Env strips single quotes');
    $test->assertSame('already-set', Env::get('PRESET_ENV'), 'Env load does not override existing variables');

    putenv('APP_SECRET=short');
    $shortSecretConfig = Config::load($root);
    try {
        $shortSecretConfig->appSecret();
        $test->assertTrue(false, 'short APP_SECRET is rejected');
    } catch (RuntimeException) {
        $test->assertTrue(true, 'short APP_SECRET is rejected');
    }

    spl_autoload_call('NotGateway\\Demo');
}

function base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
