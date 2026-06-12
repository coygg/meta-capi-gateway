<?php

declare(strict_types=1);

use Gateway\App;
use Gateway\AdminController;
use Gateway\Config;
use Gateway\Database;
use Gateway\Env;
use Gateway\Services\CapiClient;
use Gateway\Services\AdminRepository;
use Gateway\Services\CampaignRepository;
use Gateway\Services\ClickRepository;
use Gateway\Services\ClickValidator;
use Gateway\Services\DomainRepository;
use Gateway\Services\RateLimiter;
use Gateway\Services\TokenService;

$root = dirname(__DIR__);

require $root . '/src/bootstrap.php';

$envFile = getenv('ENV_FILE');
Env::load(is_string($envFile) && $envFile !== '' ? $envFile : $root . '/.env');

$config = Config::load($root);

session_name('gateway_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $config->bool('cookie_secure', true),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$database = Database::fromConfig($root, $config);
$database->migrate();
$pdo = $database->pdo();
$adminRepository = new AdminRepository($pdo);
$domainRepository = new DomainRepository($pdo, $config);
$campaignRepository = new CampaignRepository($pdo);
$campaignRepository->seedFromConfig($config->campaigns());

$app = new App(
    config: $config,
    repository: new ClickRepository($pdo),
    campaigns: $campaignRepository,
    tokens: new TokenService($config->appSecret()),
    validator: new ClickValidator(),
    capi: CapiClient::fromConfig($config),
    limiter: new RateLimiter($pdo),
    admin: new AdminController($config, $adminRepository, $domainRepository, $campaignRepository),
);

$app->handle()->send();
