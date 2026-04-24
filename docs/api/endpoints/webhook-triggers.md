# Webhook Trigger Endpoint

**Implementation**: `inc/Api/WebhookTrigger.php`, `inc/Api/WebhookVerifier.php`,
`inc/Api/WebhookAuthResolver.php`

**Base URL**: `/wp-json/datamachine/v1/trigger/{flow_id}`

**Since**: 0.30.0 (Bearer auth), 0.79.0 (template-based HMAC verifier)

## Overview

Public REST endpoint for triggering flows from inbound HTTP requests. Auth is
**per-flow** and independent of WordPress user capabilities. Two primitives:

| Mode     | Purpose                                              | Auth material          |
|----------|------------------------------------------------------|------------------------|
| `bearer` | First-party callers you control.                     | Per-flow 64-char hex token. |
| `hmac`   | Third-party senders that HMAC-sign request content.  | Shared secret(s) + a signing template. |

**DM core ships zero provider names**, anywhere. All HMAC behaviour is driven
by a declarative **signing template** stored on the flow. Templates can be
hand-written or expanded from a **preset** registered via the
`datamachine_webhook_auth_presets` filter.

## Bearer mode

Generated with `wp datamachine flows webhook enable <flow_id>`. The flow gets a
32-byte hex token. Callers present it in the `Authorization` header:

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/trigger/42 \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"key": "value"}'
```

Token comparison is timing-safe via `hash_equals`. Rotate with
`wp datamachine flows webhook regenerate <flow_id>` — the old token is
invalidated immediately.

## HMAC mode — the template verifier

The verifier is a single engine driven entirely by config. No provider names
exist in DM core; the config describes **how** a sender signs, not **which**
sender is signing.

### Template config

```php
// Stored at scheduling_config['webhook_auth']
[
    'mode'              => 'hmac',
    'algo'              => 'sha256',          // sha1 | sha256 | sha512

    // What to hash. Placeholders:
    //   {body}              — raw request body
    //   {timestamp}         — value extracted from timestamp_source
    //   {id}                — value extracted from id_source
    //   {url}               — full request URL
    //   {header:<name>}     — value of a specific request header
    //   {param:<name>}      — query param first, then body param
    'signed_template'   => '{timestamp}.{body}',

    // Where the signature lives.
    'signature_source'  => [
        'header'   => 'X-Request-Signature',   // OR 'param' => '<name>'
        'extract'  => [
            'kind'           => 'kv_pairs',    // 'raw' | 'prefix' | 'kv_pairs' | 'regex'
            'key'            => 'v1',
            'separator'      => ',',
            'pair_separator' => '=',           // default '='
        ],
        'encoding' => 'hex',                   // 'hex' | 'base64' | 'base64url'
    ],

    // Optional: presence enables replay protection.
    'timestamp_source'  => [
        'header'  => 'X-Request-Signature',
        'extract' => [ 'kind' => 'kv_pairs', 'key' => 't', 'separator' => ',' ],
        'format'  => 'unix',                   // 'unix' | 'unix_ms' | 'iso8601'
    ],

    // Optional: for templates that reference `{id}`.
    'id_source'         => [ 'header' => 'X-Event-Id' ],

    'tolerance_seconds' => 300,                // replay window
    'max_body_bytes'    => 1048576,            // 413 on overflow; 0 = unlimited
]
```

### Extract kinds

- **`raw`** — use the whole source value after trimming.
- **`prefix`** — require `extract.key` at the start; return what follows.
- **`kv_pairs`** — split on `extract.separator`, return the value associated
  with `extract.key`. Use `pair_separator` to change `=` if needed.
- **`regex`** — PCRE pattern; capture group 1 (or full match if none).

### Signature encodings

- **`hex`** — lowercase or uppercase hex.
- **`base64`** — RFC 4648 base64.
- **`base64url`** — URL-safe base64 (`-_` instead of `+/`, padding optional).

### Secrets and rotation

Secrets live in `scheduling_config['webhook_secrets']` as an array. Each entry:

```php
[ 'id' => 'current', 'value' => '...', 'expires_at' => null ]
```

Any active (non-expired) secret whose HMAC matches the incoming signature wins.
**Zero-downtime rotation** is built in:

```bash
# Install a new secret; keep the old one verifying for 7 days (default).
wp datamachine flows webhook rotate 42 --generate
wp datamachine flows webhook rotate 42 --generate --previous-ttl-seconds=86400

# Once the upstream has been updated, drop the old secret.
wp datamachine flows webhook forget 42 previous
```

## Presets (filter-based, provider-agnostic)

DM core ships **zero presets**. Third parties register them via a filter, then
users select one by name. The preset name is a **lookup key** — it expands
server-side into a full template, which is what's persisted on the flow.
Changing a preset registration after a flow is enabled does not silently
mutate the flow's resolved template.

```php
add_filter( 'datamachine_webhook_auth_presets', function ( $presets ) {
    $presets['my-upstream'] = [
        'mode'             => 'hmac',
        'algo'             => 'sha256',
        'signed_template'  => '{timestamp}.{body}',
        'signature_source' => [
            'header'   => 'X-Upstream-Signature',
            'extract'  => [ 'kind' => 'kv_pairs', 'key' => 'v1', 'separator' => ',' ],
            'encoding' => 'hex',
        ],
        'timestamp_source' => [
            'header'  => 'X-Upstream-Signature',
            'extract' => [ 'kind' => 'kv_pairs', 'key' => 't', 'separator' => ',' ],
            'format'  => 'unix',
        ],
        'tolerance_seconds' => 300,
    ];
    return $presets;
} );
```

```bash
# Enable a flow via preset
wp datamachine flows webhook enable 42 --preset=my-upstream --generate-secret

# List registered presets (table / json / yaml)
wp datamachine flows webhook presets
```

## Explicit templates (no preset required)

When you're wiring up a one-off sender, skip the filter and hand the template
directly to `enable`:

```bash
wp datamachine flows webhook enable 42 \
  --config=@template.json \
  --overrides=@overrides.json \
  --generate-secret
```

The `--overrides` file deep-merges on top of the config — useful for bumping
a tolerance window or swapping a header without rewriting the template.

## Payload passed into the flow

On successful authentication, the flow runs with this structure in
`initial_data.webhook_trigger`:

```json
{
  "payload":     { ... decoded JSON body ... },
  "received_at": "2026-04-24T12:34:56Z",
  "remote_ip":   "203.0.113.10",
  "headers":     { ... pattern-filtered headers ... },
  "auth_mode":   "hmac"
}
```

`headers` is built from a **pattern-based deny-list**: everything is included
except headers matching `/(secret|token|sig|hmac|signature|auth|password|bearer|api[-_]?key)/i`
plus the hard-coded `authorization` / `cookie` / `proxy-authorization`. No
provider-specific allow-list.

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

Returned for all auth failures — missing token, bad signature, missing
signature header, stale timestamp, no active secret, no resolved template on
an HMAC flow. No distinguishable failure codes are surfaced to the caller;
the real failure reason is logged server-side for the flow owner.

### 413 Payload Too Large

Raw body exceeded `webhook_auth.max_body_bytes` on an HMAC flow.

### 429 Too Many Requests

Rate limit exceeded. See `wp datamachine flows webhook rate-limit`.

## Backward compatibility

Flows configured with the v1 shorthand (`webhook_auth_mode = hmac_sha256` +
`webhook_signature_header` + `webhook_signature_format` + `webhook_secret`)
are migrated **silently, once, at first read**. After migration the legacy
fields are deleted from the flow row and the canonical v2 shape
(`webhook_auth_mode = hmac` + `webhook_auth` + `webhook_secrets`) replaces
them.

Bearer flows are untouched.

No code path outside the migration helper reads the legacy field names.

## Non-HMAC primitives

For Ed25519 (Discord), x509 (AWS SNS), JWT-signed webhooks, or mTLS, register
a mode class via `datamachine_webhook_verifier_modes`:

```php
add_filter( 'datamachine_webhook_verifier_modes', function ( $modes ) {
    $modes['ed25519'] = \My\Ed25519Verifier::class;
    return $modes;
} );
```

Each mode class implements a single static `verify()` method with the same
signature as `WebhookVerifier::verify()`. Core ships `hmac`; everything else
is pluggable.

## Security considerations

- **Raw body is sacred.** HMAC verification uses `$request->get_body()` — the
  exact bytes the sender signed. Any middleware that re-serializes JSON before
  this endpoint will break verification.
- **Constant-time comparison** via `hash_equals` in both auth modes.
- **Generic 401 on failure** — the endpoint does not distinguish auth failure
  modes to the caller.
- **Secret storage** — secrets live in the flow's `scheduling_config` JSON.
  Treat flow configs as secret-bearing until a dedicated credentials table
  lands.
- **Replay protection** requires `timestamp_source` and
  `tolerance_seconds > 0`. Nonce storage (reject duplicate event ids) is a
  future follow-up.

## Related

- CLI: [`wp datamachine flows webhook`](../../core-system/wp-cli.md#datamachine-flows-webhook)
- Abilities: `datamachine/webhook-trigger-enable`, `…-disable`,
  `…-regenerate`, `…-set-secret`, `…-rotate-secret`, `…-forget-secret`,
  `…-rate-limit`, `…-status`
