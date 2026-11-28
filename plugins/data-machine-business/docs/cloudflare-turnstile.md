# Cloudflare Turnstile

**Owner**: Data Machine Business

**Abilities**: `datamachine/cloudflare-turnstile-list-widgets`, `datamachine/cloudflare-turnstile-get-widget`, `datamachine/cloudflare-turnstile-update-widget-domains`

**Runtime files**:

- Ability: `inc/Abilities/Cloudflare/CloudflareTurnstileAbilities.php`
- CLI: `inc/Cli/Commands/CloudflareTurnstileCommand.php`

## Overview

A Cloudflare provider, starting with Turnstile widget read + hostname management. Cloudflare is part of the operating surface (Turnstile protects every network form; DNS, cache, and zone settings live there too), but this is intentionally scoped to only what is needed now — see issue #143 for the premature-consolidation rationale. DNS, cache purge, WAF, and zone settings are out of scope until a real need appears.

## Configuration

### API Token + Account ID

- Purpose: authenticates requests to the Cloudflare API (`api.cloudflare.com/client/v4`)
- Source: Cloudflare dashboard -> My Profile -> API Tokens -> Create Token, scoped to **Account -> Turnstile -> Edit** (least privilege for this integration)
- Storage: network option `datamachine_cloudflare_config`

The token is never logged, echoed, or committed. This is a network option (`get_site_option`/`update_site_option`), not a per-site option, so WP-CLI's built-in `wp option` command (which targets `wp_options`) does not apply — set it with `wp eval`:

```bash
wp eval '
update_site_option(
    "datamachine_cloudflare_config",
    array(
        "api_token"  => "your-cloudflare-api-token",
        "account_id" => "your-cloudflare-account-id",
    )
);
'
```

Deployments may also inject configuration through the `datamachine_cloudflare_config` filter instead of persisting it in the option:

```php
add_filter( 'datamachine_cloudflare_config', function ( array $config ): array {
    $config['api_token']  = getenv( 'CLOUDFLARE_API_TOKEN' );
    $config['account_id'] = getenv( 'CLOUDFLARE_ACCOUNT_ID' );
    return $config;
} );
```

Missing configuration returns a clear error naming the option: `Cloudflare is not configured. Set the "datamachine_cloudflare_config" network option with api_token and account_id.`

## Actions

- **List widgets** (read) — every Turnstile widget on the account.
- **Get widget** (read) — one widget by sitekey.
- **Update widget domains** (read-modify-write, mutation) — add and/or remove hostnames from a widget's allowed domains.

### Update widget domains

Cloudflare's `PUT .../challenges/widgets/{sitekey}` replaces the widget's entire config, so a naive PUT with only the changed domains would silently drop every other domain. This ability always:

1. `GET`s the current widget.
2. Computes the new domain list from the existing domains plus the requested adds, minus the requested removes — preserving `name` and `mode` unchanged.
3. Reports the computed change **without writing anything** unless `apply=true` (CLI: `--apply`).
4. Skips the `PUT` entirely when the computed change is a no-op (e.g. adding a domain that's already present, or removing one that's already absent) — reported as `no_op: true`.

Hostnames are validated: no scheme (`https://...`), no path (`/foo`), no wildcard (`*.example.com`), no whitespace.

## WP-CLI Usage

```bash
wp datamachine cloudflare turnstile list-widgets
wp datamachine cloudflare turnstile get-widget 0x4AAAAAAAExampleSiteKey

# Preview adding a hostname (no write)
wp datamachine cloudflare turnstile update-domains 0x4AAAAAAAExampleSiteKey --add=example.net

# Write it
wp datamachine cloudflare turnstile update-domains 0x4AAAAAAAExampleSiteKey --add=example.net --apply

# Remove a stale hostname
wp datamachine cloudflare turnstile update-domains 0x4AAAAAAAExampleSiteKey --remove=old.example.com --apply

# Add and remove in one call
wp datamachine cloudflare turnstile update-domains 0x4AAAAAAAExampleSiteKey --add=new.example.com --remove=old.example.com --apply
```

## Response Shape

List:

```php
array(
    'success' => true,
    'widgets' => array( array( 'sitekey' => '...', 'name' => '...', 'mode' => 'managed', 'domains' => array( '...' ) ), ... ),
    'count'   => 3,
)
```

Get:

```php
array(
    'success' => true,
    'widget'  => array( 'sitekey' => '...', 'name' => '...', 'mode' => 'managed', 'domains' => array( '...' ) ),
)
```

Update (dry-run, the default):

```php
array(
    'success'        => true,
    'dry_run'        => true,
    'no_op'          => false,
    'sitekey'        => '0x4AAAAAAAExampleSiteKey',
    'name'           => 'Example Network',
    'mode'           => 'managed',
    'domains_before' => array( 'example.com', 'www.example.com' ),
    'domains_after'  => array( 'example.com', 'www.example.com', 'example.net' ),
    'added'          => array( 'example.net' ),
    'removed'        => array(),
    'message'        => 'Dry run — no changes written. Pass apply=true (or --apply) to write these changes to Cloudflare.',
)
```

## Error Messages

- Missing config: `Cloudflare is not configured. Set the "datamachine_cloudflare_config" network option with api_token and account_id.`
- Invalid hostname: `Invalid hostname "https://example.com". Hostnames must not include a scheme, path, or wildcard.`
- Cloudflare API error: the response's `errors[]` messages, joined and surfaced directly (e.g. `Invalid request headers (code 6003)`).
- Unparseable response: `Cloudflare API returned an unparseable response.`
