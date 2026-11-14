# Hetzner Cloud (read-only)

**Owner**: Data Machine Business

**Abilities**: `datamachine/hetzner-list-servers`, `datamachine/hetzner-backup-status`, `datamachine/hetzner-list-volumes`, `datamachine/hetzner-server-metrics`

**Runtime files**:

- Abilities: `inc/Abilities/Hetzner/HetznerAbilities.php`
- Health contributions: `inc/Abilities/Hetzner/HetznerHealth.php`
- CLI: `inc/Cli/Commands/HetznerCommand.php`

## Overview

Read-only visibility into the Hetzner Cloud project that hosts the platform, so agents and health checks can answer "did last night's backup run?", "how full is that volume?", and "is the server under load?" without opening the Hetzner console.

Everything here is a `GET` against the Hetzner Cloud API (`https://api.hetzner.cloud/v1`). Out of scope:

- Mutations such as creating snapshots, resizing volumes, or enabling backups. If these are added later, they must go through the human-approval pending-action pattern (see Stripe Payment Links).
- The Hetzner Robot / Storage Box API.
- Running backups. Backups run independently of WordPress; this integration only observes them.

## Setup

### 1. Create a read-only project API token

1. Open the [Hetzner Cloud Console](https://console.hetzner.cloud/) and select the project that owns the servers.
2. Go to **Security → API tokens → Generate API token**.
3. Name it something like `data-machine-readonly` and pick **Read** permission. **Do not pick "Read & Write"**: this integration never writes, and a read-only token limits the damage if it leaks.
4. Copy the token. Hetzner shows it only once.

### 2. Store it in the network option

Credentials live in the network option `datamachine_hetzner_config` as `{"api_token": "..."}`:

```bash
wp site option update datamachine_hetzner_config '{"api_token":"YOUR_READ_ONLY_TOKEN"}' --format=json
```

On a multisite install, add `--url=<main-site>` if WP-CLI does not target the network by default. The integration also accepts the option stored as a JSON string.

Deployments can inject the token without persisting it by using the `datamachine_hetzner_config` filter:

```php
add_filter( 'datamachine_hetzner_config', function ( array $config ): array {
	$config['api_token'] = getenv( 'HETZNER_API_TOKEN' ) ?: ( $config['api_token'] ?? '' );
	return $config;
} );
```

The token is sent only in the `Authorization: Bearer` header. It is never returned in ability output, printed by the CLI, or logged. Error messages are scrubbed of it as a precaution.

### 3. Verify

```bash
wp datamachine hetzner list-servers
wp datamachine hetzner backup-status
```

If the option is missing, every ability returns `success: false` with `error_code: not_configured` and an error that names `datamachine_hetzner_config`. No HTTP request is made in that case.

## Abilities

| Ability | Input | Purpose |
|---|---|---|
| `datamachine/hetzner-list-servers` | `server_id?` | Servers with status, type, location, public IPv4, `backup_window`, `backups_enabled`, and delete/rebuild protection. Pass `server_id` to get a single server. |
| `datamachine/hetzner-backup-status` | `server_id?`, `stale_hours?` | Backup freshness for each server (details below). |
| `datamachine/hetzner-list-volumes` | none | Volumes with `size_gb`, attached `server_id`, location, status, format, and device; also returns `total_size_gb`. |
| `datamachine/hetzner-server-metrics` | `server_id`, `type?`, `hours?`, `start?`, `end?`, `step?` | `/servers/{id}/metrics` for `cpu`, `disk`, and/or `network` over a window (default: the last hour). Returns the raw `time_series` plus a `points/latest/avg/max` summary for each series. |

All four abilities use Data Machine's management permission gate and are annotated read-only.

### Backup status

For each server, the ability calls `GET /images?type=backup&bound_to=<server_id>`, following pagination, and returns:

| Field | Meaning |
|---|---|
| `backup_window` | The server's Hetzner backup window, or `null` when backups are disabled |
| `backups_disabled` | `true` when `backup_window` is `null` |
| `last_backup_at` | ISO 8601 time of the newest backup image with status `available`. In-progress images are listed but do not count toward freshness. |
| `backup_age_hours` | Hours since `last_backup_at`, to one decimal place |
| `stale` | `true` when there is no completed backup or `backup_age_hours` is above the threshold |
| `count` | Number of backup images |
| `state` | One of `ok`, `stale`, `missing`, or `disabled` |
| `backups` | Image rows sorted newest first: id, created, status, image size, and disk size |

The top level also includes `healthy`, `issues[]` (one human-readable line per problem), `message`, and the `stale_hours` threshold that was applied.

The stale threshold defaults to **26 hours**: Hetzner takes one backup per day, and the extra two hours absorb window jitter. To change it globally, use the `datamachine_hetzner_backup_stale_hours` filter. To change it for one call, pass `stale_hours` (CLI: `--stale-hours`).

## WP-CLI

```bash
wp datamachine hetzner list-servers [<server_id>] [--format=table|json|csv]
wp datamachine hetzner backup-status [<server_id>] [--stale-hours=<hours>] [--format=table|json|csv]
wp datamachine hetzner list-volumes [--format=table|json|csv]
wp datamachine hetzner server-metrics <server_id> [--type=cpu,disk,network] [--hours=<n>] [--start=<iso>] [--end=<iso>] [--step=<seconds>] [--format=table|json|csv]
```

## Health integration

`HetznerHealth` plugs backup status into two surfaces that Data Machine core already provides:

- **System health** (`datamachine_system_health_checks`, used by `datamachine/system-health-check`): adds a `hetzner_backups` check. It is included in the default `all` run only when a token is configured. Per-check options accept `server_id` and `stale_hours`.
- **Wake briefing** (`datamachine_wake_briefing_signals`): when a token is configured, adds a line such as `⚠ **Hetzner backups** — Hetzner backup for prod is 31h old (threshold 26h).` for every stale, missing, or disabled backup. It also adds a line when the API cannot be read. Healthy backups add nothing.

Within one request the status is memoized, so the health check and the briefing share a single set of API calls.

## API behaviour

- **Pagination**: collection endpoints request `per_page=50` (the Hetzner maximum) and follow `meta.pagination.next_page`, up to 50 pages.
- **Errors**: non-2xx responses are mapped from Hetzner's `{"error":{"code","message"}}` body to `success: false`, `error_code` (the Hetzner code, or `unauthorized` / `forbidden` / `rate_limit_exceeded` / `http_<status>`), `status_code`, and a readable `error`:
  - `401`: the token was rejected. Check `datamachine_hetzner_config`.
  - `403`: the token lacks permission for the resource.
  - `429`: rate limited. The message includes the reset time from the `RateLimit-Reset` header when Hetzner sends it.
- **Transport**: requests use `wp_remote_get`, so tests can mock them with the core `pre_http_request` filter. See `tests/Unit/Abilities/Hetzner/HetznerAbilitiesTest.php`.
