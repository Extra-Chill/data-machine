# Internal Links Endpoints

**Implementation**: `inc/Api/InternalLinks.php`

**Base URL**: `/wp-json/datamachine/v1/links`

Internal link audit and diagnostics endpoints. Each route maps to a WordPress ability.

## Endpoints

| Method | Route | Ability | Purpose |
|--------|-------|---------|---------|
| `POST` | `/links/audit` | `datamachine/audit-internal-links` | Build and cache the internal link graph. |
| `GET` | `/links/orphans` | `datamachine/get-orphaned-posts` | Return orphaned posts from the cached graph. |
| `GET` | `/links/backlinks` | `datamachine/get-backlinks` | Return posts linking to a target post. |
| `POST` | `/links/broken` | `datamachine/check-broken-links` | Check links for broken targets. |
| `GET` | `/links/diagnose` | `datamachine/diagnose-internal-links` | Return meta/index coverage diagnostics. |

## Permission

All routes require `PermissionHelper::can( 'manage_flows' )`.

## Request Shape

GET routes read query parameters. POST routes read JSON request bodies. The controller normalizes comma-separated `types` query parameters into arrays before calling the ability.
