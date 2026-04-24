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

### Slack / Stripe

Slack and Stripe use timestamp-prefixed signatures (`v0=...`, `t=...,v1=...`)
that aren't directly representable by the three built-in formats. Support for
these is tracked as a follow-up — see issue #1177.

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
