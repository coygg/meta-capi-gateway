<?php

declare(strict_types=1);

namespace Gateway;

use Gateway\Services\CapiClient;
use Gateway\Services\CampaignRepository;
use Gateway\Services\ClickRepository;
use Gateway\Services\ClickValidator;
use Gateway\Services\RateLimiter;
use Gateway\Services\TokenService;
use Gateway\Support\Cookie;
use Gateway\Support\Response;
use Gateway\Support\Url;

final class App
{
    public function __construct(
        private readonly Config $config,
        private readonly ClickRepository $repository,
        private readonly CampaignRepository $campaigns,
        private readonly TokenService $tokens,
        private readonly ClickValidator $validator,
        private readonly CapiClient $capi,
        private readonly RateLimiter $limiter,
        private readonly AdminController $admin,
    ) {
    }

    public function handle(): Response
    {
        try {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

            if ($method === 'GET' && $path === '/health') {
                return Response::json(['ok' => true, 'time' => ClickRepository::now()]);
            }

            if (str_starts_with($path, '/admin')) {
                return $this->admin->handle($method, $path);
            }

            if ($method === 'GET' && preg_match('#^/c/([A-Za-z0-9_-]+)$#', $path, $match) === 1) {
                return $this->trackClick($match[1]);
            }

            if ($method === 'GET' && $path === '/start') {
                return $this->startForm();
            }

            if ($method === 'POST' && $path === '/capi/intake-completed') {
                return $this->recordIntakeCompleted();
            }

            if ($method === 'GET' && preg_match('#^/fallback/([A-Za-z0-9_-]+)$#', $path, $match) === 1) {
                return $this->fallbackPage($match[1]);
            }

            if ($method === 'GET' && preg_match('#^/intake/([A-Za-z0-9_-]+)$#', $path, $match) === 1) {
                return $this->intakePage($match[1]);
            }

            return Response::json(['error' => 'not_found'], 404);
        } catch (\Throwable $error) {
            return Response::json([
                'error' => 'server_error',
                'message' => $this->config->string('env') === 'local' ? $error->getMessage() : 'Unexpected server error.',
            ], 500);
        }
    }

    private function trackClick(string $slug): Response
    {
        $campaign = $this->activeCampaign($slug);
        $clientIp = $this->clientIp();
        $ipHash = $this->hashForLogs($clientIp);

        if ($this->limiter->exceeded('click:' . $ipHash . ':' . $slug, 90, 60)) {
            return Response::json(['error' => 'rate_limited'], 429);
        }

        $clickId = bin2hex(random_bytes(16));
        $validation = $this->validator->validate($campaign, $_GET);

        if (!$validation['valid']) {
            $this->repository->recordClick($this->clickData(
                clickId: $clickId,
                slug: $slug,
                campaign: $campaign,
                decision: 'fallback',
                fallbackReason: $validation['reason'] ?? 'invalid_click',
                landingUrl: (string) $campaign['public_fallback_url'],
                clientIp: $clientIp,
            ));

            Url::assertAllowed((string) $campaign['public_fallback_url'], $this->allowedDomains($campaign));

            return Response::redirect((string) $campaign['public_fallback_url']);
        }

        $fbc = $this->resolveFbc($_GET);
        $fbp = $this->resolveFbp();
        $ttl = (int) ($campaign['click_token_ttl_seconds'] ?? 1800);
        $expiresAt = gmdate('c', time() + $ttl);
        $token = $this->tokens->sign([
            'type' => 'click',
            'click_id' => $clickId,
            'campaign' => $slug,
        ], $ttl);
        $landingUrl = Url::appendQuery((string) $campaign['landing_url'], [
            (string) ($campaign['click_token_param'] ?? 'cid') => $token,
        ]);

        Url::assertAllowed($landingUrl, $this->allowedDomains($campaign));

        $this->repository->recordClick($this->clickData(
            clickId: $clickId,
            slug: $slug,
            campaign: $campaign,
            decision: 'allow',
            fallbackReason: null,
            landingUrl: $landingUrl,
            clientIp: $clientIp,
            fbc: $fbc,
            fbp: $fbp,
            expiresAt: $expiresAt,
        ));

        return Response::redirect($landingUrl, 302, [
            'Set-Cookie' => [
                Cookie::make('pj_fbp', $fbp, 60 * 60 * 24 * 90, $this->config->bool('cookie_secure', true)),
                Cookie::make('pj_click', $token, $ttl, $this->config->bool('cookie_secure', true)),
            ],
        ]);
    }

    private function startForm(): Response
    {
        $tokenParam = (string) ($_GET['cid'] ?? $_COOKIE['pj_click'] ?? '');
        $verification = $this->tokens->verify($tokenParam, 'click');

        if (!$verification['valid']) {
            return Response::json(['error' => 'invalid_click_token', 'reason' => $verification['reason']], 400);
        }

        $clickId = (string) ($verification['claims']['click_id'] ?? '');
        $slug = (string) ($verification['claims']['campaign'] ?? '');
        $click = $this->repository->findClick($clickId);
        $campaign = $this->activeCampaign($slug);

        if ($click === null || ($click['decision'] ?? null) !== 'allow') {
            return Response::json(['error' => 'click_not_found'], 404);
        }

        $sessionId = bin2hex(random_bytes(16));
        $ttl = (int) ($campaign['form_token_ttl_seconds'] ?? 7200);
        $expiresAt = gmdate('c', time() + $ttl);
        $formToken = $this->tokens->sign([
            'type' => 'form',
            'session_id' => $sessionId,
            'click_id' => $clickId,
            'campaign' => $slug,
        ], $ttl);

        $this->repository->createFormSession($sessionId, $clickId, $slug, $expiresAt);

        $formUrl = Url::appendQuery(
            (string) $campaign['form_url'],
            $this->formForwardParams($campaign, $click, $tokenParam, $formToken)
        );

        Url::assertAllowed($formUrl, $this->allowedDomains($campaign));

        return Response::redirect($formUrl, 302, [
            'Set-Cookie' => Cookie::make('pj_form', $formToken, $ttl, $this->config->bool('cookie_secure', true)),
        ]);
    }

    private function recordIntakeCompleted(): Response
    {
        $configuredSecret = $this->config->string('intake_webhook_secret');
        $providedSecret = (string) ($_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '');

        if ($configuredSecret === '' || !hash_equals($configuredSecret, $providedSecret)) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        $input = $this->jsonInput();
        $token = $this->webhookToken($input);
        $verification = $this->tokens->verify($token['value'], $token['type']);

        if (!$verification['valid']) {
            return Response::json(['error' => 'invalid_token', 'reason' => $verification['reason']], 400);
        }

        $clickId = (string) ($verification['claims']['click_id'] ?? '');
        $slug = (string) ($verification['claims']['campaign'] ?? '');
        $click = $this->repository->findClick($clickId);
        $campaign = $this->activeCampaign($slug);

        if ($click === null || ($click['decision'] ?? null) !== 'allow') {
            return Response::json(['error' => 'click_not_found'], 404);
        }

        $sessionId = (string) ($verification['claims']['session_id'] ?? '');
        if ($sessionId !== '' && $this->repository->findFormSession($sessionId) === null) {
            return Response::json(['error' => 'form_session_not_found'], 404);
        }

        $eventName = (string) ($campaign['capi_event_name'] ?? 'Lead');
        $eventId = $this->webhookEventId($input);
        $existingConversion = $this->repository->findConversion($eventId);

        if ($existingConversion !== null) {
            return Response::json([
                'ok' => true,
                'duplicate' => true,
                'event_id' => $eventId,
                'meta_status_code' => isset($existingConversion['meta_status_code'])
                    ? (int) $existingConversion['meta_status_code']
                    : null,
                'meta_response' => json_decode((string) ($existingConversion['meta_response_json'] ?? '{}'), true) ?: [],
            ]);
        }

        $event = $this->buildCapiEvent($eventName, $eventId, $campaign, $click);
        $result = $this->capi->send($event);

        $this->repository->recordConversion([
            'event_id' => $eventId,
            'click_id' => $clickId,
            'form_session_id' => $sessionId !== '' ? $sessionId : null,
            'campaign_slug' => $slug,
            'event_name' => $eventName,
            'dry_run' => $result['dry_run'],
            'meta_status_code' => $result['status_code'],
            'meta_response_json' => json_encode($result['response'], JSON_UNESCAPED_SLASHES),
        ]);

        return Response::json([
            'ok' => true,
            'dry_run' => $result['dry_run'],
            'event_id' => $eventId,
            'meta_status_code' => $result['status_code'],
            'meta_response' => $result['response'],
        ]);
    }

    private function fallbackPage(string $slug): Response
    {
        $campaign = $this->activeCampaign($slug);
        $title = htmlspecialchars((string) ($campaign['fallback_title'] ?? 'Information'), ENT_QUOTES, 'UTF-8');
        $body = htmlspecialchars((string) ($campaign['fallback_body'] ?? ''), ENT_QUOTES, 'UTF-8');

        return Response::html($this->page($title, '<p>' . $body . '</p>'));
    }

    private function intakePage(string $slug): Response
    {
        $campaign = $this->activeCampaign($slug);
        $title = htmlspecialchars((string) ($campaign['intake_title'] ?? 'Online intake'), ENT_QUOTES, 'UTF-8');
        $body = htmlspecialchars((string) ($campaign['intake_body'] ?? ''), ENT_QUOTES, 'UTF-8');
        $cid = htmlspecialchars((string) ($_GET[$campaign['click_token_param'] ?? 'cid'] ?? ''), ENT_QUOTES, 'UTF-8');
        $cta = $cid !== ''
            ? '<p><a class="button" href="/start?cid=' . $cid . '">Start intake</a></p>'
            : '<p class="muted">The secure intake form opens from an eligible ad session.</p>';

        return Response::html($this->page($title, '<p>' . $body . '</p>' . $cta));
    }

    /**
     * @param array<string, mixed> $campaign
     * @param array<string, mixed> $click
     * @return array<string, string>
     */
    private function formForwardParams(array $campaign, array $click, string $clickToken, string $formToken): array
    {
        $tokenKey = trim((string) ($campaign['form_token_param'] ?? 'sid')) ?: 'sid';
        $params = [
            'sid' => $formToken,
            'cid' => $clickToken,
            'gateway_cid' => $clickToken,
            $tokenKey => $formToken,
        ];

        $forward = [
            'fbclid' => $click['fbclid'] ?? '',
            'ad_id' => $click['ad_id'] ?? '',
            'adset_id' => $click['adset_id'] ?? '',
            'campaign_id' => $click['meta_campaign_id'] ?? '',
            'utm_source' => $click['utm_source'] ?? '',
            'utm_medium' => $click['utm_medium'] ?? '',
            'utm_campaign' => $click['utm_campaign'] ?? '',
            'utm_content' => $click['utm_content'] ?? '',
        ];

        $query = $this->decodedClickQuery($click);
        if (isset($query['utm_term']) && is_scalar($query['utm_term'])) {
            $forward['utm_term'] = $query['utm_term'];
        }

        foreach ($forward as $key => $value) {
            $value = is_scalar($value) ? trim((string) $value) : '';

            if ($value !== '' && !isset($params[$key])) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $click
     * @return array<string, mixed>
     */
    private function decodedClickQuery(array $click): array
    {
        $decoded = json_decode((string) ($click['query_json'] ?? '{}'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{value: string, type: string}
     */
    private function webhookToken(array $input): array
    {
        $formKeys = ['sid', 'gateway_sid', 'form_session_token', 'form_token', 'attribution_sid'];
        $clickKeys = ['cid', 'gateway_cid', 'click_token', 'attribution_cid'];
        $containers = [
            'tracking',
            'metadata',
            'meta',
            'query',
            'query_params',
            'url_params',
            'custom_fields',
            'customFields',
            'hidden_fields',
            'hiddenFields',
        ];

        $token = $this->firstStringFromKeys($input, $formKeys);
        if ($token !== '') {
            return ['value' => $token, 'type' => 'form'];
        }

        $token = $this->firstStringFromKeys($input, $clickKeys);
        if ($token !== '') {
            return ['value' => $token, 'type' => 'click'];
        }

        foreach ($containers as $containerName) {
            $container = $input[$containerName] ?? null;

            if (!is_array($container)) {
                continue;
            }

            $token = $this->firstStringFromKeys($container, $formKeys);
            if ($token !== '') {
                return ['value' => $token, 'type' => 'form'];
            }

            $token = $this->firstStringFromKeys($container, $clickKeys);
            if ($token !== '') {
                return ['value' => $token, 'type' => 'click'];
            }
        }

        $token = $this->webhookTokenFromUrlFields($input, $formKeys, $clickKeys);
        if ($token['value'] !== '') {
            return $token;
        }

        foreach ($containers as $containerName) {
            $container = $input[$containerName] ?? null;

            if (!is_array($container)) {
                continue;
            }

            $token = $this->webhookTokenFromUrlFields($container, $formKeys, $clickKeys);
            if ($token['value'] !== '') {
                return $token;
            }
        }

        return ['value' => '', 'type' => 'form'];
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string> $formKeys
     * @param list<string> $clickKeys
     * @return array{value: string, type: string}
     */
    private function webhookTokenFromUrlFields(array $source, array $formKeys, array $clickKeys): array
    {
        foreach (['url', 'page_url', 'landing_url', 'source_url', 'referrer', 'resume_url'] as $field) {
            $url = $this->firstStringFromKeys($source, [$field]);

            if ($url === '') {
                continue;
            }

            $query = parse_url($url, PHP_URL_QUERY);
            if (!is_string($query) || $query === '') {
                continue;
            }

            parse_str($query, $params);

            $token = $this->firstStringFromKeys($params, $formKeys);
            if ($token !== '') {
                return ['value' => $token, 'type' => 'form'];
            }

            $token = $this->firstStringFromKeys($params, $clickKeys);
            if ($token !== '') {
                return ['value' => $token, 'type' => 'click'];
            }
        }

        return ['value' => '', 'type' => 'form'];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function webhookEventId(array $input): string
    {
        $keys = [
            'event_id',
            'eventId',
            'submission_id',
            'submissionId',
            'checkout_id',
            'checkoutId',
            'order_id',
            'orderId',
            'session_id',
            'sessionId',
            'id',
        ];

        $eventId = $this->firstStringFromKeys($input, $keys);
        if ($eventId !== '') {
            return $eventId;
        }

        foreach (['event', 'submission', 'checkout', 'order', 'session', 'sessionMeta', 'session_meta'] as $containerName) {
            $container = $input[$containerName] ?? null;

            if (!is_array($container)) {
                continue;
            }

            $eventId = $this->firstStringFromKeys($container, $keys);
            if ($eventId !== '') {
                return $eventId;
            }
        }

        return bin2hex(random_bytes(16));
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string> $keys
     */
    private function firstStringFromKeys(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source) || !is_scalar($source[$key])) {
                continue;
            }

            $value = trim((string) $source[$key]);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $campaign
     * @param array<string, mixed> $click
     * @return array<string, mixed>
     */
    private function buildCapiEvent(string $eventName, string $eventId, array $campaign, array $click): array
    {
        $userData = [
            'client_ip_address' => (string) ($click['client_ip'] ?? ''),
            'client_user_agent' => (string) ($click['client_user_agent'] ?? ''),
        ];

        if (!empty($click['fbc'])) {
            $userData['fbc'] = (string) $click['fbc'];
        }

        if (!empty($click['fbp'])) {
            $userData['fbp'] = (string) $click['fbp'];
        }

        $event = [
            'event_name' => $eventName,
            'event_time' => time(),
            'event_id' => $eventId,
            'action_source' => 'website',
            'event_source_url' => (string) ($campaign['event_source_url'] ?? $campaign['landing_url']),
            'user_data' => array_filter($userData, static fn (string $value): bool => $value !== ''),
        ];

        $customData = $campaign['capi_custom_data'] ?? [];

        if (is_array($customData) && $customData !== []) {
            $event['custom_data'] = $customData;
        }

        return $event;
    }

    /**
     * @param array<string, mixed> $campaign
     * @return list<string>
     */
    private function allowedDomains(array $campaign): array
    {
        $domains = $campaign['allowed_domains'] ?? [];

        if (!is_array($domains)) {
            return [];
        }

        return array_values(array_filter($domains, 'is_string'));
    }

    /**
     * @return array<string, mixed>
     */
    private function activeCampaign(string $slug): array
    {
        $campaign = $this->campaigns->findBySlug($slug) ?? $this->config->campaign($slug);

        if ($campaign === null || ($campaign['status'] ?? null) !== 'active') {
            throw new \RuntimeException('Campaign is not active: ' . $slug);
        }

        return $campaign;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function resolveFbc(array $query): string
    {
        $cookie = (string) ($_COOKIE['pj_fbc'] ?? '');

        if ($cookie !== '') {
            return $cookie;
        }

        $fbclid = trim((string) ($query['fbclid'] ?? ''));

        if ($fbclid === '') {
            return '';
        }

        return 'fb.1.' . ((string) ((int) floor(microtime(true) * 1000))) . '.' . $fbclid;
    }

    private function resolveFbp(): string
    {
        $cookie = (string) ($_COOKIE['pj_fbp'] ?? '');

        if ($cookie !== '') {
            return $cookie;
        }

        return 'fb.1.' . ((string) ((int) floor(microtime(true) * 1000))) . '.' . random_int(1000000000, 2147483647);
    }

    /**
     * @param array<string, mixed> $campaign
     * @return array<string, mixed>
     */
    private function clickData(
        string $clickId,
        string $slug,
        array $campaign,
        string $decision,
        ?string $fallbackReason,
        string $landingUrl,
        string $clientIp,
        string $fbc = '',
        string $fbp = '',
        ?string $expiresAt = null,
    ): array {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        return [
            'click_id' => $clickId,
            'campaign_slug' => $slug,
            'decision' => $decision,
            'fallback_reason' => $fallbackReason,
            'landing_url' => $landingUrl,
            'ad_id' => (string) ($_GET['ad_id'] ?? ''),
            'adset_id' => (string) ($_GET['adset_id'] ?? ''),
            'meta_campaign_id' => (string) ($_GET['campaign_id'] ?? ''),
            'fbclid' => (string) ($_GET['fbclid'] ?? ''),
            'fbc' => $fbc,
            'fbp' => $fbp,
            'utm_source' => (string) ($_GET['utm_source'] ?? ''),
            'utm_medium' => (string) ($_GET['utm_medium'] ?? ''),
            'utm_campaign' => (string) ($_GET['utm_campaign'] ?? ''),
            'utm_content' => (string) ($_GET['utm_content'] ?? ''),
            'client_ip' => $clientIp,
            'client_ip_hash' => $this->hashForLogs($clientIp),
            'client_user_agent' => $ua,
            'user_agent_hash' => $this->hashForLogs($ua),
            'query' => $_GET,
            'created_at' => ClickRepository::now(),
            'expires_at' => $expiresAt,
        ];
    }

    private function clientIp(): string
    {
        if ($this->config->bool('trust_proxy', false)) {
            $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            $first = trim(explode(',', $forwarded)[0] ?? '');

            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }

            $cf = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
            if (filter_var($cf, FILTER_VALIDATE_IP)) {
                return $cf;
            }
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private function hashForLogs(string $value): string
    {
        return hash_hmac('sha256', $value, $this->config->appSecret());
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonInput(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function page(string $title, string $content): string
    {
        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; color: #17202a; background: #f7f8fa; }
                main { max-width: 720px; margin: 8vh auto; padding: 32px; background: #fff; border: 1px solid #dfe4ea; border-radius: 8px; }
                h1 { margin-top: 0; font-size: 30px; line-height: 1.2; }
                p { font-size: 17px; line-height: 1.6; }
                .muted { color: #5d6d7e; }
                .button { display: inline-block; padding: 12px 16px; background: #1769aa; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 700; }
            </style>
        </head>
        <body>
            <main>
                <h1>{$title}</h1>
                {$content}
            </main>
        </body>
        </html>
        HTML;
    }
}
