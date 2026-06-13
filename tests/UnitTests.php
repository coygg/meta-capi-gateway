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
    $repo->createFormSession('unit-session', 'unit-click', 'weight-intake', gmdate('c', time() + 60));
    $test->assertSame('unit-session', $repo->findFormSession('unit-session')['session_id'] ?? null, 'repository stores form sessions');

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

    $campaignRepo = new CampaignRepository($database->pdo());
    $_SESSION = ['admin_authenticated' => true];
    $emptyAdmin = new AdminController($config, $adminRepo, $domainRepo, $campaignRepo);
    $database->pdo()->exec('UPDATE admin_users SET walkthrough_completed_at = NULL');
    $emptyDashboard = $emptyAdmin->handle('GET', '/admin');
    $test->assertContains('Quick setup walkthrough', $responseBody($emptyDashboard), 'admin dashboard renders first-run walkthrough');
    $test->assertContains('No campaigns yet.', $responseBody($emptyDashboard), 'admin dashboard handles empty campaign database');
    $adminRepo->completeWalkthrough();
    $hiddenWalkthroughDashboard = $emptyAdmin->handle('GET', '/admin');
    $test->assertTrue(!str_contains($responseBody($hiddenWalkthroughDashboard), 'Quick setup walkthrough'), 'admin dashboard hides completed walkthrough');
    $newCampaignResponse = $emptyAdmin->handle('GET', '/admin/campaigns/new');
    $test->assertContains('href="/admin">Cancel</a>', $responseBody($newCampaignResponse), 'admin campaign form renders cancel link');
    $_SESSION = [];

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

    $campaignRepo->seedFromConfig($config->campaigns());
    $_SESSION = ['admin_authenticated' => true];
    $seededDashboard = $emptyAdmin->handle('GET', '/admin');
    $test->assertContains('ad_id={{ad.id}}', $responseBody($seededDashboard), 'admin dashboard renders full Meta ad URL');
    $_SESSION = [];
    $campaignRepo->seedFromConfig($config->campaigns());
    $test->assertTrue(count($campaignRepo->all()) >= 1, 'campaign repository seeds config campaigns');
    $test->assertTrue($campaignRepo->findActive('weight-intake') !== null, 'campaign repository returns active campaign');
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
