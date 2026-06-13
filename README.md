# Meta Attribution Gateway

PHP 8.2+ gateway for Remedora-style Meta ad funnels. It protects the downstream intake form URL from copied or Ad Library-stripped links while preserving the attribution params Remedora needs for its own direct Meta CAPI setup.

This app does **not** send CAPI events to Meta. Keep Remedora's built-in CAPI enabled and let Remedora send conversions directly.

## What It Does

- Gives Meta ads a first-party tracking URL like `/c/weight-intake`.
- Validates expanded Meta click params server-side.
- Sends copied, stripped, or unexpanded links to a public fallback page that keeps the same ad copy intact.
- Sends valid ad clicks to a static lander with a signed `cid`.
- Sends lander CTA clicks through `/start`, then redirects to Remedora with `sid`, `cid`, `gateway_cid`, `fbclid`, ad IDs, and UTMs preserved.
- Provides a first-run admin setup, domain portal, and campaign editor.

## Flow

```mermaid
sequenceDiagram
    participant U as "Facebook or Instagram user"
    participant M as "Meta"
    participant G as "Gateway"
    participant L as "Static lander"
    participant R as "Remedora form"

    U->>M: Clicks ad
    M->>G: Opens /c/{campaign} with fbclid and ad params
    G->>G: Validates expanded params
    G->>L: Redirects with signed cid
    L->>G: User clicks Start
    G->>R: Redirects with sid, cid, fbclid, ad IDs, UTMs
    R->>R: Stores attribution and sends its own direct Meta CAPI on conversion
```

## One-Click Deploy To Render

[![Deploy to Render](https://render.com/images/deploy-to-render-button.svg)](https://render.com/deploy?repo=https://github.com/coygg/meta-capi-gateway)

Render uses `render.yaml` to create:

- A Docker web service.
- A persistent SQLite disk at `/var/data`.
- A generated `APP_SECRET`.
- `DB_PATH=/var/data/gateway.sqlite`.
- `TRUST_PROXY=true` and secure cookies.

No Meta pixel/dataset ID, Meta access token, webhook secret, or CAPI dry-run setting is required.

After deploy:

1. Open the Render URL.
2. Go to `/admin`.
3. Create the first admin password.
4. Follow the quick setup walkthrough shown at the top of the admin dashboard.
5. Add a tracking domain, such as `track.yourdomain.com`.
6. Point that domain's CNAME to the portal's displayed target.
7. Click **Verify** in the domain table.
8. Create or edit a campaign.
9. Set the static lander URL, Remedora form URL, and public fallback URL.
10. Copy the generated Meta ad URL from the admin dashboard into the Meta ad destination URL.
11. Keep Remedora direct CAPI enabled. Do not configure a Remedora webhook back to this gateway.

## Runway Or Wizard Deploys

If your deploy platform provides a wizard, it can prefill almost everything from the blueprint:

- Generate `APP_SECRET`.
- Create a persistent disk or persistent SQLite volume.
- Set `DB_PATH` to that persistent location.
- Set `APP_BASE_URL` or let the platform provide its external URL.
- Set `TRUST_PROXY=true` when the app is behind a load balancer.
- Optionally set `GATEWAY_CNAME_TARGET` if the platform has a fixed CNAME target.

The wizard does not need to ask users for Meta credentials. Domains and campaigns are configured later in `/admin`.

## Manual Setup

```bash
composer install
cp .env.example .env # optional if you create one
composer serve
```

Minimum environment:

```env
APP_ENV=production
APP_BASE_URL=https://track.yourdomain.com
APP_SECRET=<at least 32 random characters>
DB_PATH=storage/gateway.sqlite
COOKIE_SECURE=true
TRUST_PROXY=true
GATEWAY_CNAME_TARGET=
```

Optional:

```env
CAMPAIGN_CONFIG_PATH=config/campaigns.php
```

## Campaign Logic

Meta ad URL:

```text
https://track.yourdomain.com/c/weight-intake?ad_id={{ad.id}}&adset_id={{adset.id}}&campaign_id={{campaign.id}}&utm_source=facebook&utm_medium=paid_social&utm_campaign={{campaign.name}}&utm_content={{ad.name}}
```

Valid clicks are redirected to the campaign lander with a signed `cid`.

The lander CTA should point to:

```text
https://track.yourdomain.com/start?cid=<cid from lander URL>
```

`/start` redirects to the Remedora form URL with:

- `sid`
- `cid`
- `gateway_cid`
- `fbclid`
- `ad_id`
- `adset_id`
- `campaign_id`
- `utm_source`
- `utm_medium`
- `utm_campaign`
- `utm_content`
- `utm_term` when present

Remedora receives those query params, stores its own attribution context, and sends CAPI directly to Meta when the intake converts.

## DNS

Add the tracking hostname in `/admin`, then create a CNAME record with your DNS provider.

Example:

```text
track.yourdomain.com CNAME your-render-or-platform-target
```

The admin portal shows the expected CNAME target and has a **Verify** action. Once verified, generated ad URLs use the active tracking domain automatically.

## Tests

```bash
composer test
composer test:coverage
```

The E2E test simulates:

- first-run admin setup
- first-run dashboard walkthrough and dismissal
- domain portal actions
- campaign creation and editing
- a valid Meta ad click
- a static lander handoff
- Remedora-style query capture
- copied or unexpanded links going to fallback
- removed gateway CAPI webhook returning 404
- rate limiting

## Notes

- Keep fallback copy aligned with the Facebook ad copy.
- Put the real Remedora form URL only in the protected campaign config.
- Do not send PHI through this gateway.
- Rotate `APP_SECRET` only if you are comfortable invalidating outstanding signed `cid` and `sid` tokens.
- This app is a routing and attribution-preservation gateway. Remedora remains the system that sends conversion events to Meta.
