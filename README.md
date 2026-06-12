# Meta CAPI Gateway

Small PHP gateway for a CAPI-only Meta funnel. It protects the downstream intake/form URL from copied or Ad Library-stripped links while keeping Meta-visible ad flow intact.

## What It Does

- Accepts Meta ad clicks at `/c/{campaign}`.
- Requires expanded Meta URL parameters like `ad_id`, `adset_id`, and `campaign_id`.
- Sends missing/unexpanded parameter traffic to a safe public fallback page.
- Stores first-party click context for CAPI attribution.
- Issues short-lived signed tokens for the static lander and telehealth form start.
- Receives a conversion webhook from the intake form and sends a sanitized CAPI event.
- Provides a first-run admin portal for domains and campaigns backed by SQLite.
- Defaults to `CAPI_DRY_RUN=true`.

It does not block Meta crawlers, reviewers, countries, VPNs, user agents, or device types.

## Ad URL

Use the gateway URL as the ad destination:

```text
https://track.yourdomain.com/c/weight-intake?ad_id={{ad.id}}&adset_id={{adset.id}}&campaign_id={{campaign.id}}&utm_source=facebook&utm_medium=paid_social&utm_campaign={{campaign.name}}&utm_content={{ad.name}}
```

If those macros do not expand, the click routes to the configured public fallback.

## Deployment Options

There are two ways to run this app:

- **Recommended:** one-click Render deploy using the hosted wizard.
- **Alternative:** manual local or self-hosted install.

If you use Render, you do not need to copy `.env`, manually create the SQLite database, or set every environment variable yourself.

## Recommended: One-Click Render Wizard

[![Deploy to Render](https://render.com/images/deploy-to-render-button.svg)](https://render.com/deploy?repo=https://github.com/coygg/meta-capi-gateway)

Click the button above for the easiest setup. Render reads `render.yaml` and walks the deployer through a wizard.

This repository includes `Dockerfile` and `render.yaml` for Render Blueprint deploys. The Blueprint:

- Builds the PHP app from Docker.
- Mounts a persistent disk at `/var/data`.
- Stores SQLite at `/var/data/gateway.sqlite`.
- Generates `APP_SECRET` and `INTAKE_WEBHOOK_SECRET`.
- Prompts for `META_PIXEL_ID` and `META_ACCESS_TOKEN`.
- Uses Render's generated service URL automatically through `RENDER_EXTERNAL_URL`.

### Render Wizard Steps

1. Click **Deploy to Render**.
2. Connect or sign in to Render.
3. Enter `META_PIXEL_ID` and `META_ACCESS_TOKEN` when prompted.
4. Let Render generate `APP_SECRET` and `INTAKE_WEBHOOK_SECRET`.
5. Wait for the first deploy to finish.
6. Open:

```text
https://YOUR-RENDER-SERVICE.onrender.com/admin/setup
```

7. Create the admin password.
8. Open `/admin`, add a tracking domain, and create a campaign.
9. Copy the generated Meta ad URL into Meta Ads Manager.
10. Configure the telehealth platform to preserve `sid` and send completed intake webhooks to `/capi/intake-completed` with `X-Webhook-Secret`.

### What Render Handles

- Docker build and start command.
- Persistent SQLite disk.
- `DB_PATH=/var/data/gateway.sqlite`.
- `COOKIE_SECURE=true`.
- `TRUST_PROXY=true`.
- `CAPI_DRY_RUN=false`.
- Generated `APP_SECRET`.
- Generated `INTAKE_WEBHOOK_SECRET`.
- Prompted `META_PIXEL_ID`.
- Prompted `META_ACCESS_TOKEN`.
- Health check at `/health`.
- Public service URL through `RENDER_EXTERNAL_URL`.

### What The Deployer Still Does

- Create the first admin password at `/admin/setup`.
- Add domains and campaigns in `/admin`.
- Add DNS records for custom tracking domains.
- Configure the telehealth platform to store `sid` and call the webhook.
- Copy the generated webhook secret from Render into the telehealth platform.
- Optional: attach a custom domain in Render.

If you attach a custom domain, set `APP_BASE_URL` to that custom URL in Render's environment settings so generated fallback/demo URLs use the custom host.

## Alternative: Manual Local Install

Use this path only for development or self-hosting outside Render.

```bash
cp .env.example .env
php -S 127.0.0.1:8080 -t public
```

Edit `.env` for local testing:

```text
APP_ENV=local
APP_BASE_URL=http://127.0.0.1:8080
APP_SECRET=<long random secret>
DB_PATH=storage/gateway.sqlite
COOKIE_SECURE=false
CAPI_DRY_RUN=true
INTAKE_WEBHOOK_SECRET=<long random secret>
```

## Alternative: Manual Production Hosting

Use this path only if you are deploying to your own server instead of Render.

For manual self-hosted production, point Nginx/Apache at `public/index.php`, set HTTPS, and set:

```text
APP_ENV=production
APP_BASE_URL=https://track.yourdomain.com
COOKIE_SECURE=true
APP_SECRET=<long random secret>
INTAKE_WEBHOOK_SECRET=<long random secret>
CAPI_DRY_RUN=false
META_PIXEL_ID=<your pixel/dataset id>
META_ACCESS_TOKEN=<your access token>
DB_PATH=/secure/persistent/path/gateway.sqlite
```

Manual production hosting also needs:

- Persistent storage for SQLite.
- HTTPS and proxy headers configured correctly.
- Backups for the SQLite database.
- A process manager such as systemd, Supervisor, or your platform's service runner.

## After Any Deployment: First Run Admin Portal

After deploy, open:

```text
https://track.yourdomain.com/admin/setup
```

Create the admin password there. After the first admin exists, setup redirects to login and the portal is available at `/admin`.

Use the portal to:

- Add tracking domains.
- Verify CNAME status.
- Create or pause campaigns.
- Generate the exact Meta ad URL for each campaign.
- Edit lander, form, fallback, token, allowlist, and CAPI settings.

`config/campaigns.php` is now a seed/fallback file. On boot, any campaigns in that file are copied into the database if the slug does not already exist. Portal-edited database campaigns take priority after that.

## DNS

The gateway does not automatically create DNS records. The portal stores the hostnames users add and tells them what CNAME target to point at.

Set `GATEWAY_CNAME_TARGET` when you have a canonical gateway host:

```text
GATEWAY_CNAME_TARGET=gateway.yourdomain.com
```

Then a user can add `track.clientdomain.com` in the portal and create:

```text
track.clientdomain.com CNAME gateway.yourdomain.com
```

If `GATEWAY_CNAME_TARGET` is blank, the portal derives the target from `APP_BASE_URL`. Domain verification marks local hosts active immediately and checks CNAME records for real hostnames.

## Configure Campaigns

Use `/admin` for normal campaign management. Edit `config/campaigns.php` only when you want to ship default seed campaigns.

Production checklist:

- Replace `landing_url` with your static intake page.
- Replace `form_url` with the telehealth form start URL.
- Replace `public_fallback_url` with a public page matching the ad offer/category.
- Add your exact hosts to `allowed_domains`.
- Keep `capi_event_name` generic, such as `Lead`.
- Keep `capi_custom_data` empty unless legal/compliance approves every field.

## Static Lander CTA

Your static lander should preserve the `cid` query parameter and point the CTA to:

```html
<a href="https://track.yourdomain.com/start?cid=THE_CID_FROM_QUERY">Start intake</a>
```

This app includes a demo lander at `/intake/weight-intake`.

## Telehealth Form

`/start` redirects to your configured `form_url` with a signed `sid` query parameter. The form provider should store that `sid` in a hidden field and include it when posting the conversion webhook.

Webhook request:

```http
POST /capi/intake-completed
X-Webhook-Secret: your-secret
Content-Type: application/json
```

```json
{
  "sid": "signed-form-session-token",
  "event_id": "optional-idempotency-id"
}
```

Do not post diagnosis, treatment, medication, symptoms, answers, condition names, or page URLs containing sensitive information to this endpoint.

## Hosted Remedora-Style Forms

Hosted form platforms such as Remedora-style React/Inertia intake pages can work as long as they preserve query parameters or let you include webhook metadata.

For a campaign using a hosted form URL like:

```text
https://try.remedora.com/f/reta-form
```

configure the campaign in `/admin` with:

- `form_url` set to the hosted form URL.
- `allowed_domains` including the hosted form host, for example `try.remedora.com`.
- `form_token_param` set to `sid` unless the platform requires a different parameter name.

When a valid visitor clicks **Start intake**, the gateway redirects to the hosted form with:

- `sid`: signed form-session token.
- `cid`: signed click token fallback.
- `gateway_cid`: explicit click-token alias.
- `fbclid`, `ad_id`, `adset_id`, `campaign_id`, and safe UTM fields when present on the original ad click.

The hosted platform webhook should send back one of these:

```json
{
  "sid": "signed-form-session-token",
  "event_id": "stable-submission-or-session-id"
}
```

or nested metadata:

```json
{
  "event": { "id": "stable-submission-or-session-id" },
  "metadata": { "sid": "signed-form-session-token" }
}
```

or a captured form URL/referrer containing `sid` or `cid`:

```json
{
  "submission_id": "stable-submission-or-session-id",
  "page_url": "https://try.remedora.com/f/reta-form?sid=SIGNED_TOKEN"
}
```

The gateway also accepts token fields under `tracking`, `metadata`, `meta`, `query`, `query_params`, `url_params`, `custom_fields`, `customFields`, `hidden_fields`, and `hiddenFields`. For idempotency, send a stable `event_id`, `submission_id`, `checkout_id`, `order_id`, `session_id`, or nested `event.id`/`session.id`. Without a stable event id, retries may be treated as new CAPI events.

## CAPI Payload

The app sends only:

- `event_name`
- `event_time`
- `event_id`
- `action_source`
- `event_source_url`
- `client_ip_address`
- `client_user_agent`
- `fbc` when `fbclid` was present
- `fbp` from a first-party gateway cookie

No Pixel is used.

## Test

```bash
composer test
```

The test suite includes unit coverage plus a full mock e2e run:

- mock Facebook/Instagram ad click into the gateway
- mock static lander handoff
- mock telehealth platform receiving `sid`
- mock telehealth conversion webhook back to the gateway
- mock Meta CAPI endpoint receiving the final server event
- duplicate webhook idempotency
- stripped macro fallback
- invalid token, invalid webhook, bad config, and rate-limit paths
- cURL and stream CAPI transports
- first-run admin setup, login/logout, CSRF-protected portal mutations
- DB-backed domain and campaign management

For PCOV line coverage:

```bash
composer test:coverage
```

Or directly:

```bash
php tests/run.php
```

Valid click:

```bash
curl -i "http://127.0.0.1:8080/c/weight-intake?ad_id=123&adset_id=456&campaign_id=789&utm_source=facebook&fbclid=test-click-id"
```

Fallback click:

```bash
curl -i "http://127.0.0.1:8080/c/weight-intake?ad_id={{ad.id}}&adset_id=456&campaign_id=789&utm_source=facebook"
```

## Privacy Notes

This is a technical implementation, not legal advice. For telehealth, keep the gateway and CAPI payload free of PHI and sensitive health data. Store raw IP and user-agent only as long as needed for CAPI attribution, document retention, and confirm the setup with your privacy/compliance counsel.
