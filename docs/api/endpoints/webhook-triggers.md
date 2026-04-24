# Webhook Trigger Endpoint

**Implementation**: `inc/Api/WebhookTrigger.php`, `inc/Api/WebhookSignatureVerifier.php`

**Base URL**: `/wp-json/datamachine/v1/trigger/{flow_id}`

**Since**: 0.30.0 (Bearer auth), 0.79.0 (HMAC-SHA256 auth)

## Overview

Public REST endpoint for triggering flows from external services — webhooks from
GitHub, Stripe, Shopify, Slack, Linear, or any custom upstream. Complements the
admin-only `/execute` endpoint and the mid-pipeline `WebhookGate` step.

Authentication is per-flow and independent of WordPress capabilities. Each flow
chooses between two auth modes:

| Mode          | When to use                                            | Header                         |
|---------------|--------------------------------------------------------|--------------------------------|
| `bearer`      | Default. First-party callers you control.              | `Authorization: Bearer <token>` |
| `hmac_sha256` | Third-party providers that sign the raw request body.  | Provider-specific (configurable) |

Existing flows with no `webhook_auth_mode` value default to `bearer` — there is
zero behavior change for anything shipped before 0.79.0.

## Auth mode: `bearer`

Generated with `wp datamachine flows webhook enable <flow_id>`. The flow gets a
32-byte hex token. Callers present it in the `Authorization` header:

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/trigger/42 \
  -H "Authorization: Bearer <64-char-hex-token>" \
  -H "Content-Type: application/json" \
  -d '{"key": "value"}'
```

Token comparison is timing-safe via `hash_equals`. Token rotation is
`wp datamachine flows webhook regenerate <flow_id>` — old tokens are
invalidated immediately.

## Auth mode: `hmac_sha256`

Industry-standard webhook signature verification against the **raw request
body**. Supported by GitHub, Stripe, Shopify, Slack, Linear, Mailgun, PayPal,
SendGrid, Twilio, Plaid, and most major SaaS providers.

### Flow

1. Flow stores a shared `webhook_secret` (never exposed via status).
2. Upstream signs the raw request body with that secret using HMAC-SHA256.
3. Signature is sent in a provider-specific header.
4. DM recomputes the signature with `hash_hmac('sha256', $raw_body, $secret)`
   and compares via `hash_equals`.

### Supported signature formats

| `signature_format` | Encoding                          | Example provider      |
|--------------------|-----------------------------------|-----------------------|
| `sha256=hex`       | `sha256=` + lowercase hex (default) | GitHub                |
| `hex`              | raw hex                           | Linear                |
| `base64`           | base64-encoded raw digest         | Shopify               |

### Payload size cap

By default, HMAC flows reject bodies larger than 1 MB (`413 Payload Too Large`)
before running HMAC, so unauthenticated clients cannot force the server to hash
arbitrarily large payloads. Override with:

```php
scheduling_config['webhook_max_body_bytes'] = 2097152; // 2 MB
```

Set to `0` to disable the cap (not recommended).

### What gets passed to the flow

On successful authentication, the flow runs with this structure in
`initial_data.webhook_trigger`:

```json
{
  "payload":     { ... decoded JSON body ... },
  "received_at": "2026-04-24T12:34:56Z",
  "remote_ip":   "203.0.113.10",
  "headers":     { "content-type": "...", "x-github-event": "...", ... },
  "auth_mode":   "hmac_sha256"
}
```

## GitHub webhook walkthrough (end-to-end)

1. **Generate a secret and enable HMAC auth on the flow**:

   ```bash
   wp datamachine flows webhook enable 42 \
     --auth-mode=hmac_sha256 \
     --generate-secret
   ```

   Output:

   ```
   Success: Webhook trigger enabled for flow 42 (hmac_sha256).
   URL:       https://example.com/wp-json/datamachine/v1/trigger/42
   Auth mode: hmac_sha256
   Header:    X-Hub-Signature-256
   Format:    sha256=hex
   Secret:    <64-char-hex>
   Warning: Save this secret now — it will not be shown again.
   ```

2. **Copy the secret** into GitHub:
   - Repo → Settings → Webhooks → Add webhook
   - **Payload URL**: the `URL` printed above
   - **Content type**: `application/json`
   - **Secret**: paste the secret from step 1
   - **Which events**: select the events you want (e.g. Pull requests)
   - Save.

3. **Test**: GitHub sends a ping event. The flow executes with the payload in
   `initial_data.webhook_trigger.payload`.

### Rotating the secret later

```bash
wp datamachine flows webhook set-secret 42 --generate
```

The old secret is invalidated immediately. Paste the new value into the
provider UI.

## Other providers

The pattern is identical — only the default header and format differ.

### Shopify (base64)

```bash
wp datamachine flows webhook enable 42 \
  --auth-mode=hmac_sha256 \
  --signature-header=X-Shopify-Hmac-Sha256 \
  --signature-format=base64 \
  --secret=<shopify_webhook_secret>
```

### Linear (hex)

```bash
wp datamachine flows webhook enable 42 \
  --auth-mode=hmac_sha256 \
  --signature-header=Linear-Signature \
  --signature-format=hex \
  --generate-secret
```

### Slack / Stripe (v2 template-based verifier)

As of 0.79.0 the verifier also accepts a **provider-agnostic template config**
that covers any HMAC-family signature format — including timestamp-prefixed
schemes like Stripe's `t=...,v1=...` and Slack's `v0:{ts}:{body}`. See the
[v2 template verifier](#v2-template-based-verifier-provider-agnostic) section
below.

## v2: template-based verifier (provider-agnostic)

**Since 0.79.0** — see [#1179](https://github.com/Extra-Chill/data-machine/issues/1179).

The v2 verifier describes webhook authentication as a **signing template** and
**extraction rules** rather than an enum of provider-specific formats. One
engine covers GitHub, Stripe, Slack, Shopify, Linear, Svix / Standard Webhooks,
Mailgun, PayPal, Clerk, Twilio-style URL+param signing, and anything else in
the HMAC family — with **zero provider-specific code** in DM core.

### Config shape

```php
scheduling_config['webhook_auth'] = [
    'mode'             => 'hmac',              // 'hmac' | any mode from the filter registry
    'algo'             => 'sha256',            // 'sha1' | 'sha256' | 'sha512'

    // How to build the signed message. Placeholders:
    //   {body}              — raw request body
    //   {timestamp}         — value extracted from timestamp_source
    //   {id}                — value extracted from id_source
    //   {url}               — full request URL
    //   {header:<name>}     — value of a specific request header
    //   {param:<name>}      — query param first, then body param
    'signed_template'  => '{timestamp}.{body}',

    // Where the signature lives.
    'signature_source' => [
        'header'   => 'Stripe-Signature',      // OR 'param' => '<name>'
        'extract'  => [
            'kind'           => 'kv_pairs',    // 'raw' | 'kv_pairs' | 'prefix' | 'regex'
            'key'            => 'v1',          // for kv_pairs / prefix
            'separator'      => ',',           // for kv_pairs
            'pair_separator' => '=',           // for kv_pairs (default =)
        ],
        'encoding' => 'hex',                   // 'hex' | 'base64' | 'base64url'
    ],

    // Optional — presence enables replay protection.
    'timestamp_source' => [
        'header'  => 'Stripe-Signature',
        'extract' => [ 'kind' => 'kv_pairs', 'key' => 't', 'separator' => ',' ],
        'format'  => 'unix',                   // 'unix' | 'unix_ms' | 'iso8601'
    ],

    // Optional — for providers that sign an event id (Svix, Clerk).
    'id_source' => [ 'header' => 'Webhook-Id' ],

    'tolerance_seconds' => 300,

    // Multi-secret rotation: any active secret that verifies wins.
    // Expired entries are skipped automatically.
    'secrets' => [
        [ 'id' => 'current',  'value' => '...' ],
        [ 'id' => 'previous', 'value' => '...', 'expires_at' => '2026-05-01T00:00:00Z' ],
    ],

    'max_body_bytes' => 1048576,               // 0 = unlimited
]
```

### Provider coverage table

Every row below is exercised end-to-end by the PHPUnit provider matrix in
`tests/Unit/Api/WebhookVerifierTest.php`.

| Provider | `signed_template` | `signature_source` | `timestamp_source` | `encoding` |
|---|---|---|---|---|
| **GitHub** | `{body}` | header `X-Hub-Signature-256`, `prefix=sha256=` | — | hex |
| **Shopify** | `{body}` | header `X-Shopify-Hmac-Sha256` | — | base64 |
| **Linear** | `{body}` | header `Linear-Signature` | — | hex |
| **Stripe** | `{timestamp}.{body}` | header `Stripe-Signature`, `kv_pairs key=v1 separator=,` | same header, `kv_pairs key=t` | hex |
| **Slack** | `v0:{timestamp}:{body}` | header `X-Slack-Signature`, `prefix=v0=` | header `X-Slack-Request-Timestamp` | hex |
| **Svix / Standard Webhooks** | `{id}.{timestamp}.{body}` | header `Webhook-Signature`, `kv_pairs key=v1 separator=" "` | header `Webhook-Timestamp` | base64 |
| **Mailgun** | `{timestamp}{header:X-Mailgun-Token}` | header `X-Mailgun-Signature` | header `X-Mailgun-Timestamp` | hex |
| **PayPal** | `{body}` | header `Paypal-Transmission-Sig` | header `Paypal-Transmission-Time` | base64 |
| **Clerk / Svix-compatible** | `{header:svix-id}.{header:svix-timestamp}.{body}` | header `svix-signature`, `kv_pairs key=v1 separator=" "` | header `svix-timestamp` | base64 |

### Presets: filter-registered shorthands

DM core ships **zero presets**. Register them via
`datamachine_webhook_auth_presets` in a companion plugin or mu-plugin:

```php
add_filter( 'datamachine_webhook_auth_presets', function ( $p ) {
    $p['stripe'] = [
        'mode'             => 'hmac',
        'algo'             => 'sha256',
        'signed_template'  => '{timestamp}.{body}',
        'signature_source' => [
            'header'   => 'Stripe-Signature',
            'extract'  => [ 'kind' => 'kv_pairs', 'key' => 'v1', 'separator' => ',' ],
            'encoding' => 'hex',
        ],
        'timestamp_source' => [
            'header'  => 'Stripe-Signature',
            'extract' => [ 'kind' => 'kv_pairs', 'key' => 't', 'separator' => ',' ],
            'format'  => 'unix',
        ],
        'tolerance_seconds' => 300,
    ];
    return $p;
} );
```

Then enable a flow by preset name:

```bash
wp datamachine flows webhook enable 42 --preset=stripe --secret=whsec_...
wp datamachine flows webhook presets  # list all registered presets
```

### Replay protection

When `timestamp_source` is set and `tolerance_seconds > 0`, the verifier
rejects any request whose timestamp skew exceeds the tolerance. 5 minutes is a
good default for most providers (matches Stripe's and Slack's published
recommendation).

### Zero-downtime secret rotation

```bash
# Install a new secret; keep the old one verifying for 7 days (default).
wp datamachine flows webhook rotate 42 --generate

# Or set the TTL explicitly.
wp datamachine flows webhook rotate 42 --generate --previous-ttl-seconds=86400

# After updating the provider side, forget the old secret.
wp datamachine flows webhook forget 42 previous
```

Both `current` and `previous` verify signatures until `previous` expires — so
there's no window where one side is broken.

### Offline dry-run

Debug a provider-side signature configuration without touching production
flow execution:

```bash
wp datamachine flows webhook test 42 \
  --body=@fixtures/github-ping.json \
  --header="X-Hub-Signature-256: sha256=abc123..." \
  --header="X-GitHub-Event: ping"
```

Prints the verification outcome, which secret matched, and the extracted
timestamp skew. No job spawned, no rate-limit state touched.

### Non-HMAC modes (extension point)

The v2 config's `mode` field is open-ended. Register additional modes via
the `datamachine_webhook_verifier_modes` filter:

```php
add_filter( 'datamachine_webhook_verifier_modes', function ( $modes ) {
    $modes['ed25519'] = MyEd25519Verifier::class;   // e.g. Discord
    $modes['aws_sns'] = MyAwsSnsVerifier::class;    // x509
    return $modes;
} );
```

Each mode implements a single static `verify(...)` method with the same
signature as `WebhookVerifier::verify()`.

## Responses

### 200 OK

```json
{
  "success":   true,
  "flow_id":   42,
  "flow_name": "...",
  "job_id":    123,
  "message":   "Flow triggered successfully"
}
```

### 401 Unauthorized

Returned for **all** auth failures (missing/wrong Bearer token, missing/bad HMAC
signature, flow not found, webhook not enabled) to prevent information leakage:

```json
{ "code": "unauthorized", "message": "Invalid or missing authorization.", "data": { "status": 401 } }
```

### 413 Payload Too Large

Raw body exceeded `webhook_max_body_bytes` (HMAC mode only):

```json
{ "code": "payload_too_large", "message": "Payload too large.", "data": { "status": 413 } }
```

### 429 Too Many Requests

Rate limit exceeded. See `wp datamachine flows webhook rate-limit`.

## Security considerations

- **Raw body is sacred.** HMAC verification uses `$request->get_body()` — the
  exact bytes the sender signed. Any middleware that re-serializes JSON before
  this endpoint will break verification.
- **Constant-time comparison** via `hash_equals` in both auth modes.
- **Generic 401 on failure** — the endpoint never distinguishes between
  "no such flow", "wrong token", "bad signature", or "missing header".
- **Secret storage** — the secret lives in the flow's `scheduling_config` JSON,
  same column as `webhook_token`. Treat flow configs as secret-bearing.
- **Replay protection** is out of scope for this endpoint. Providers like Slack
  and Stripe include signed timestamps that could be validated with a replay
  window — tracked as a follow-up.

## Related

- CLI: [`wp datamachine flows webhook`](../../core-system/wp-cli.md#datamachine-flows-webhook)
- Abilities: `datamachine/webhook-trigger-enable`, `…-disable`, `…-regenerate`,
  `…-set-secret`, `…-rate-limit`, `…-status`
- Outbound counterpart: [Agent Ping tool](../../ai-tools/tools-overview.md)
