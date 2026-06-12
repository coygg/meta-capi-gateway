<?php

declare(strict_types=1);

namespace Gateway;

use Gateway\Services\AdminRepository;
use Gateway\Services\CampaignRepository;
use Gateway\Services\DomainRepository;
use Gateway\Support\Response;

final class AdminController
{
    public function __construct(
        private readonly Config $config,
        private readonly AdminRepository $admins,
        private readonly DomainRepository $domains,
        private readonly CampaignRepository $campaigns,
    ) {
    }

    public function handle(string $method, string $path): Response
    {
        if (!$this->admins->hasAdmin() && $path !== '/admin/setup') {
            return Response::redirect('/admin/setup');
        }

        if ($path === '/admin/setup') {
            return $method === 'POST' ? $this->setupPost() : $this->setupGet();
        }

        if ($path === '/admin/login') {
            return $method === 'POST' ? $this->loginPost() : $this->loginGet();
        }

        if ($method === 'POST' && $path === '/admin/logout') {
            $this->assertCsrf();
            $_SESSION = [];
            session_regenerate_id(true);
            return Response::redirect('/admin/login');
        }

        if (!$this->isLoggedIn()) {
            return Response::redirect('/admin/login');
        }

        if ($method === 'GET' && $path === '/admin') {
            return $this->dashboard();
        }

        if ($method === 'POST' && $path === '/admin/domains') {
            return $this->domainAdd();
        }

        if ($method === 'POST' && preg_match('#^/admin/domains/(\d+)/verify$#', $path, $match) === 1) {
            return $this->domainVerify((int) $match[1]);
        }

        if ($method === 'POST' && preg_match('#^/admin/domains/(\d+)/delete$#', $path, $match) === 1) {
            return $this->domainDelete((int) $match[1]);
        }

        if ($method === 'GET' && $path === '/admin/campaigns/new') {
            return $this->campaignForm();
        }

        if ($method === 'POST' && $path === '/admin/campaigns') {
            return $this->campaignSave();
        }

        if ($method === 'GET' && preg_match('#^/admin/campaigns/(\d+)/edit$#', $path, $match) === 1) {
            $campaign = $this->campaigns->findById((int) $match[1]);
            if ($campaign === null) {
                $this->flash('Campaign not found.');
                return Response::redirect('/admin');
            }

            return $this->campaignForm($campaign);
        }

        if ($method === 'POST' && preg_match('#^/admin/campaigns/(\d+)$#', $path, $match) === 1) {
            $campaign = $this->campaigns->findById((int) $match[1]);
            if ($campaign === null) {
                $this->flash('Campaign not found.');
                return Response::redirect('/admin');
            }

            return $this->campaignSave($campaign);
        }

        return Response::json(['error' => 'not_found'], 404);
    }

    private function setupGet(): Response
    {
        if ($this->admins->hasAdmin()) {
            return Response::redirect('/admin/login');
        }

        return Response::html($this->layout('First Run Setup', $this->authForm(
            action: '/admin/setup',
            title: 'Create admin password',
            button: 'Create admin',
            showConfirmation: true,
        )));
    }

    private function setupPost(): Response
    {
        if ($this->admins->hasAdmin()) {
            return Response::redirect('/admin/login');
        }

        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');

        if (strlen($password) < 12) {
            return $this->setupError('Use at least 12 characters.');
        }

        if (!hash_equals($password, $confirmation)) {
            return $this->setupError('Passwords do not match.');
        }

        $this->admins->createAdmin($password);
        $_SESSION['admin_authenticated'] = true;
        session_regenerate_id(true);
        $this->flash('Admin password created.');

        return Response::redirect('/admin');
    }

    private function setupError(string $message): Response
    {
        return Response::html($this->layout('First Run Setup', $this->notice($message) . $this->authForm(
            action: '/admin/setup',
            title: 'Create admin password',
            button: 'Create admin',
            showConfirmation: true,
        )), 422);
    }

    private function loginGet(): Response
    {
        if ($this->isLoggedIn()) {
            return Response::redirect('/admin');
        }

        return Response::html($this->layout('Admin Login', $this->authForm(
            action: '/admin/login',
            title: 'Admin login',
            button: 'Log in',
        )));
    }

    private function loginPost(): Response
    {
        $password = (string) ($_POST['password'] ?? '');

        if (!$this->admins->verifyPassword($password)) {
            return Response::html($this->layout('Admin Login', $this->notice('Invalid password.') . $this->authForm(
                action: '/admin/login',
                title: 'Admin login',
                button: 'Log in',
            )), 401);
        }

        $_SESSION['admin_authenticated'] = true;
        session_regenerate_id(true);

        return Response::redirect('/admin');
    }

    private function dashboard(): Response
    {
        $domains = $this->domains->all();
        $campaigns = $this->campaigns->all();
        $target = $this->domains->cnameTarget();
        $flash = $this->consumeFlash();

        $domainRows = '';
        foreach ($domains as $domain) {
            $status = $this->e((string) $domain['status']);
            $error = trim((string) ($domain['last_error'] ?? ''));
            $domainRows .= '<tr>'
                . '<td>' . $this->e((string) $domain['hostname']) . '</td>'
                . '<td><span class="pill">' . $status . '</span></td>'
                . '<td>' . $this->e((string) $domain['cname_target']) . '</td>'
                . '<td>' . ($error !== '' ? $this->e($error) : '-') . '</td>'
                . '<td class="actions">'
                . $this->postButton('/admin/domains/' . (int) $domain['id'] . '/verify', 'Verify')
                . $this->postButton('/admin/domains/' . (int) $domain['id'] . '/delete', 'Delete', 'danger')
                . '</td>'
                . '</tr>';
        }

        if ($domainRows === '') {
            $domainRows = '<tr><td colspan="5">No custom domains yet.</td></tr>';
        }

        $campaignRows = '';
        foreach ($campaigns as $campaign) {
            $adUrl = $this->adUrl((string) $campaign['slug']);
            $campaignRows .= '<tr>'
                . '<td>' . $this->e((string) $campaign['slug']) . '</td>'
                . '<td><span class="pill">' . $this->e((string) $campaign['status']) . '</span></td>'
                . '<td><code>' . $this->e($adUrl) . '</code></td>'
                . '<td><a class="button" href="/admin/campaigns/' . (int) $campaign['id'] . '/edit">Edit</a></td>'
                . '</tr>';
        }

        if ($campaignRows === '') {
            $campaignRows = '<tr><td colspan="4">No campaigns yet.</td></tr>';
        }

        $body = $flash
            . '<section><h2>Domains</h2>'
            . '<p>Add a tracking domain, then point its CNAME to <code>' . $this->e($target) . '</code>.</p>'
            . '<form method="post" action="/admin/domains" class="inline">' . $this->csrfField()
            . '<input name="hostname" placeholder="track.example.com" required>'
            . '<button type="submit">Add domain</button></form>'
            . '<table><thead><tr><th>Hostname</th><th>Status</th><th>CNAME target</th><th>Last check</th><th></th></tr></thead><tbody>' . $domainRows . '</tbody></table></section>'
            . '<section><div class="split"><h2>Campaigns</h2><a class="button" href="/admin/campaigns/new">New campaign</a></div>'
            . '<table><thead><tr><th>Slug</th><th>Status</th><th>Meta ad URL</th><th></th></tr></thead><tbody>' . $campaignRows . '</tbody></table></section>'
            . '<form method="post" action="/admin/logout">' . $this->csrfField() . '<button type="submit" class="link">Log out</button></form>';

        return Response::html($this->layout('Admin', $body));
    }

    private function domainAdd(): Response
    {
        $this->assertCsrf();

        try {
            $this->domains->add((string) ($_POST['hostname'] ?? ''));
            $this->flash('Domain saved.');
        } catch (\Throwable $error) {
            $this->flash($error->getMessage());
        }

        return Response::redirect('/admin');
    }

    private function domainVerify(int $id): Response
    {
        $this->assertCsrf();

        try {
            $this->domains->verify($id);
            $this->flash('Domain verification checked.');
        } catch (\Throwable $error) {
            $this->flash($error->getMessage());
        }

        return Response::redirect('/admin');
    }

    private function domainDelete(int $id): Response
    {
        $this->assertCsrf();
        $this->domains->delete($id);
        $this->flash('Domain deleted.');

        return Response::redirect('/admin');
    }

    /**
     * @param array<string, mixed>|null $campaign
     */
    private function campaignForm(?array $campaign = null): Response
    {
        $isEdit = $campaign !== null;
        $campaign ??= $this->defaultCampaign();
        $action = $isEdit ? '/admin/campaigns/' . (int) $campaign['id'] : '/admin/campaigns';
        $title = $isEdit ? 'Edit campaign' : 'New campaign';

        $body = '<section><h2>' . $title . '</h2>'
            . '<form method="post" action="' . $this->e($action) . '" class="stack">' . $this->csrfField()
            . $this->input('slug', 'Slug', (string) $campaign['slug'], $isEdit)
            . $this->select('status', 'Status', (string) $campaign['status'], ['active' => 'Active', 'paused' => 'Paused'])
            . $this->input('landing_url', 'Static lander URL', (string) $campaign['landing_url'])
            . $this->input('form_url', 'Telehealth form URL', (string) $campaign['form_url'])
            . $this->input('public_fallback_url', 'Public fallback URL', (string) $campaign['public_fallback_url'])
            . $this->input('event_source_url', 'CAPI event source URL', (string) $campaign['event_source_url'])
            . $this->textarea('allowed_domains', 'Allowed redirect domains', implode("\n", $campaign['allowed_domains']))
            . $this->textarea('required_params', 'Required Meta params', implode("\n", $campaign['required_params']))
            . $this->textarea('accepted_utm_sources', 'Accepted UTM sources', implode("\n", $campaign['accepted_utm_sources']))
            . $this->input('click_token_ttl_seconds', 'Click token TTL seconds', (string) $campaign['click_token_ttl_seconds'])
            . $this->input('form_token_ttl_seconds', 'Form token TTL seconds', (string) $campaign['form_token_ttl_seconds'])
            . $this->input('click_token_param', 'Click token param', (string) $campaign['click_token_param'])
            . $this->input('form_token_param', 'Form token param', (string) $campaign['form_token_param'])
            . $this->input('capi_event_name', 'CAPI event name', (string) $campaign['capi_event_name'])
            . $this->textarea('capi_custom_data', 'CAPI custom data JSON', json_encode($campaign['capi_custom_data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}')
            . $this->input('fallback_title', 'Fallback title', (string) $campaign['fallback_title'])
            . $this->textarea('fallback_body', 'Fallback body', (string) $campaign['fallback_body'])
            . $this->input('intake_title', 'Intake title', (string) $campaign['intake_title'])
            . $this->textarea('intake_body', 'Intake body', (string) $campaign['intake_body'])
            . '<div><button type="submit">Save campaign</button> <a class="button secondary" href="/admin">Cancel</a></div>'
            . '</form></section>';

        return Response::html($this->layout($title, $body));
    }

    /**
     * @param array<string, mixed>|null $existing
     */
    private function campaignSave(?array $existing = null): Response
    {
        $this->assertCsrf();

        try {
            $data = $this->campaignPostData($existing);
            $this->campaigns->save($data);
            $this->flash('Campaign saved.');
            return Response::redirect('/admin');
        } catch (\Throwable $error) {
            $body = $this->notice($error->getMessage());
            $body .= '<p><a class="button" href="/admin">Back to admin</a></p>';
            return Response::html($this->layout('Campaign Error', $body), 422);
        }
    }

    private function isLoggedIn(): bool
    {
        return ($_SESSION['admin_authenticated'] ?? false) === true;
    }

    private function assertCsrf(): void
    {
        $token = (string) ($_POST['_csrf'] ?? '');
        $expected = (string) ($_SESSION['_csrf'] ?? '');

        if ($expected === '' || !hash_equals($expected, $token)) {
            throw new \RuntimeException('Invalid CSRF token.');
        }
    }

    private function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        }

        return (string) $_SESSION['_csrf'];
    }

    private function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->e($this->csrfToken()) . '">';
    }

    private function flash(string $message): void
    {
        $_SESSION['_flash'] = $message;
    }

    private function consumeFlash(): string
    {
        $message = (string) ($_SESSION['_flash'] ?? '');
        unset($_SESSION['_flash']);

        return $message === '' ? '' : $this->notice($message);
    }

    private function notice(string $message): string
    {
        return '<div class="notice">' . $this->e($message) . '</div>';
    }

    private function authForm(string $action, string $title, string $button, bool $showConfirmation = false): string
    {
        $confirmation = $showConfirmation
            ? '<label>Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" required></label>'
            : '';

        return '<section class="narrow"><h2>' . $this->e($title) . '</h2>'
            . '<form method="post" action="' . $this->e($action) . '" class="stack">'
            . '<label>Password<input type="password" name="password" autocomplete="' . ($showConfirmation ? 'new-password' : 'current-password') . '" required></label>'
            . $confirmation
            . '<button type="submit">' . $this->e($button) . '</button></form></section>';
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultCampaign(): array
    {
        $base = $this->config->string('base_url');
        return [
            'id' => null,
            'slug' => 'new-intake',
            'status' => 'active',
            'landing_url' => $base . '/intake/new-intake',
            'form_url' => 'https://telehealth.example.com/intake/start',
            'public_fallback_url' => $base . '/fallback/new-intake',
            'event_source_url' => $base . '/intake/new-intake',
            'allowed_domains' => ['127.0.0.1', 'localhost', 'telehealth.example.com'],
            'required_params' => ['ad_id', 'adset_id', 'campaign_id', 'utm_source'],
            'accepted_utm_sources' => ['facebook', 'instagram'],
            'click_token_ttl_seconds' => 1800,
            'form_token_ttl_seconds' => 7200,
            'click_token_param' => 'cid',
            'form_token_param' => 'sid',
            'capi_event_name' => 'Lead',
            'capi_custom_data' => [],
            'fallback_title' => 'Online health intake',
            'fallback_body' => 'Review general information about this online intake pathway.',
            'intake_title' => 'Online health intake',
            'intake_body' => 'Start a secure intake flow.',
        ];
    }

    /**
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function campaignPostData(?array $existing): array
    {
        return [
            'id' => $existing['id'] ?? null,
            'slug' => $existing !== null ? (string) $existing['slug'] : (string) ($_POST['slug'] ?? ''),
            'status' => (string) ($_POST['status'] ?? 'paused'),
            'landing_url' => (string) ($_POST['landing_url'] ?? ''),
            'form_url' => (string) ($_POST['form_url'] ?? ''),
            'public_fallback_url' => (string) ($_POST['public_fallback_url'] ?? ''),
            'event_source_url' => (string) ($_POST['event_source_url'] ?? ''),
            'allowed_domains' => (string) ($_POST['allowed_domains'] ?? ''),
            'required_params' => (string) ($_POST['required_params'] ?? ''),
            'accepted_utm_sources' => (string) ($_POST['accepted_utm_sources'] ?? ''),
            'click_token_ttl_seconds' => (int) ($_POST['click_token_ttl_seconds'] ?? 1800),
            'form_token_ttl_seconds' => (int) ($_POST['form_token_ttl_seconds'] ?? 7200),
            'click_token_param' => (string) ($_POST['click_token_param'] ?? 'cid'),
            'form_token_param' => (string) ($_POST['form_token_param'] ?? 'sid'),
            'capi_event_name' => (string) ($_POST['capi_event_name'] ?? 'Lead'),
            'capi_custom_data' => (string) ($_POST['capi_custom_data'] ?? '{}'),
            'fallback_title' => (string) ($_POST['fallback_title'] ?? ''),
            'fallback_body' => (string) ($_POST['fallback_body'] ?? ''),
            'intake_title' => (string) ($_POST['intake_title'] ?? ''),
            'intake_body' => (string) ($_POST['intake_body'] ?? ''),
        ];
    }

    private function adUrl(string $slug): string
    {
        $base = $this->trackingBaseUrl();

        return $base . '/c/' . rawurlencode($slug)
            . '?ad_id={{ad.id}}&adset_id={{adset.id}}&campaign_id={{campaign.id}}'
            . '&utm_source=facebook&utm_medium=paid_social&utm_campaign={{campaign.name}}&utm_content={{ad.name}}';
    }

    private function trackingBaseUrl(): string
    {
        $active = $this->domains->activeHostnames();
        if ($active !== []) {
            $host = $active[0];
            $scheme = in_array($host, ['localhost', '127.0.0.1'], true) ? 'http' : 'https';
            return $scheme . '://' . $host;
        }

        return $this->config->string('base_url');
    }

    private function postButton(string $action, string $label, string $class = ''): string
    {
        return '<form method="post" action="' . $this->e($action) . '">'
            . $this->csrfField()
            . '<button type="submit" class="' . $this->e($class) . '">' . $this->e($label) . '</button></form>';
    }

    private function input(string $name, string $label, string $value, bool $readonly = false): string
    {
        return '<label>' . $this->e($label)
            . '<input name="' . $this->e($name) . '" value="' . $this->e($value) . '"' . ($readonly ? ' readonly' : '') . ' required></label>';
    }

    /**
     * @param array<string, string> $options
     */
    private function select(string $name, string $label, string $value, array $options): string
    {
        $html = '<label>' . $this->e($label) . '<select name="' . $this->e($name) . '">';
        foreach ($options as $option => $text) {
            $html .= '<option value="' . $this->e($option) . '"' . ($option === $value ? ' selected' : '') . '>' . $this->e($text) . '</option>';
        }

        return $html . '</select></label>';
    }

    private function textarea(string $name, string $label, string $value): string
    {
        return '<label>' . $this->e($label)
            . '<textarea name="' . $this->e($name) . '" rows="4">' . $this->e($value) . '</textarea></label>';
    }

    private function layout(string $title, string $body, int $status = 200): string
    {
        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$this->e($title)}</title>
            <style>
                body { margin: 0; font-family: Arial, sans-serif; background: #f5f7f8; color: #17202a; }
                header { background: #132f46; color: #fff; padding: 18px 28px; }
                main { max-width: 1120px; margin: 0 auto; padding: 28px; }
                section { background: #fff; border: 1px solid #dce3e8; border-radius: 8px; padding: 22px; margin-bottom: 20px; }
                h1, h2 { margin-top: 0; }
                table { width: 100%; border-collapse: collapse; margin-top: 16px; }
                th, td { border-bottom: 1px solid #e8edf1; padding: 10px; text-align: left; vertical-align: top; }
                code { overflow-wrap: anywhere; }
                input, textarea, select { width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #bcc8d1; border-radius: 6px; margin-top: 6px; font: inherit; }
                label { display: block; font-weight: 700; }
                button, .button { display: inline-block; border: 0; border-radius: 6px; background: #1769aa; color: #fff; padding: 10px 13px; text-decoration: none; font-weight: 700; cursor: pointer; }
                .secondary { background: #5f6f7a; }
                .danger { background: #aa2d17; }
                .link { background: transparent; color: #1769aa; padding-left: 0; }
                .stack { display: grid; gap: 14px; }
                .inline { display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: end; }
                .split { display: flex; justify-content: space-between; gap: 16px; align-items: center; }
                .actions { display: flex; gap: 8px; }
                .notice { border: 1px solid #d8c785; background: #fff7d6; padding: 12px; border-radius: 6px; margin-bottom: 18px; }
                .pill { display: inline-block; background: #e9f0f5; border-radius: 999px; padding: 3px 8px; font-size: 13px; }
                .narrow { max-width: 460px; margin: 7vh auto; }
            </style>
        </head>
        <body>
            <header><h1>{$this->e($title)}</h1></header>
            <main>{$body}</main>
        </body>
        </html>
        HTML;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
