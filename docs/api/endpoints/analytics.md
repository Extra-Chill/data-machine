# Analytics Endpoints

**File Location**: `inc/Api/Analytics.php`

**@since**: 0.31.0

Unified REST API for core and extension-backed analytics integrations. Each endpoint delegates to its respective WordPress ability via `wp_get_ability()`. Extensions can add analytics routes with the `datamachine_analytics_ability_map` filter.

## Endpoints

| Method | Route | Tool |
|--------|-------|------|
| `POST` | `/datamachine/v1/analytics/gsc` | Google Search Console |
| `POST` | `/datamachine/v1/analytics/ga` | Google Analytics (GA4), registered by Data Machine Business |

## Authentication

All endpoints require `PermissionHelper::can( 'manage_flows' )`. Administrators also pass through the `manage_options` fallback in `PermissionHelper`.

## Request Format

All endpoints accept JSON POST body with `action` as the only required field. Additional parameters vary by tool.

### Common Parameters

**action** (string, required)
- The analytics action to perform
- Valid values depend on the tool (see individual tool documentation)

### Google Search Console Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | Yes | `query_stats`, `page_stats`, `query_page_stats`, `date_stats`, `inspect_url`, `list_sitemaps`, `get_sitemap`, `submit_sitemap` |
| `start_date` | string | No | `YYYY-MM-DD` (default: 28 days ago) |
| `end_date` | string | No | `YYYY-MM-DD` (default: 3 days ago) |
| `limit` | integer | No | Row limit (default: 25, max: 25000) |
| `url_filter` | string | No | Filter to URLs containing this string |
| `query_filter` | string | No | Filter to queries containing this string |
| `url` | string | No | URL for `inspect_url` action |
| `sitemap_url` | string | No | URL for `get_sitemap`/`submit_sitemap` |

### Google Analytics (GA4) Parameters

The `/analytics/ga` route remains in Data Machine core for compatibility, but the `datamachine/google-analytics` ability is registered by the Data Machine Business extension. Activate Data Machine Business before using this route.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | Yes | `page_stats`, `traffic_sources`, `date_stats`, `realtime`, `top_events`, `user_demographics` |
| `property_id` | string | No | GA4 property ID (overrides config) |
| `start_date` | string | No | `YYYY-MM-DD` (default: 28 days ago) |
| `end_date` | string | No | `YYYY-MM-DD` (default: yesterday) |
| `limit` | integer | No | Row limit (default: 25, max: 10000) |
| `page_filter` | string | No | Filter to pages containing this path string |

## Response Format

### Success Response

HTTP 200 with the ability result:

```json
{
    "success": true,
    "action": "page_stats",
    "results_count": 25,
    "results": [...]
}
```

Response structure varies by tool and action. See individual tool documentation for details.

### Error Responses

**Permission Denied** (HTTP 403):
```json
{
    "code": "rest_forbidden",
    "message": "You do not have permission to access analytics data.",
    "data": { "status": 403 }
}
```

**Invalid Tool** (HTTP 400):
```json
{
    "code": "invalid_tool",
    "message": "Invalid analytics tool.",
    "data": { "status": 400 }
}
```

**Ability Not Found** (HTTP 500):
```json
{
    "code": "ability_not_found",
    "message": "Analytics ability \"datamachine/google-analytics\" not registered. Ensure WordPress 6.9+ and Data Machine Business is active.",
    "data": { "status": 500 }
}
```

**Not Configured** (HTTP 422):
```json
{
    "code": "analytics_error",
    "message": "Google Analytics not configured. Add service account JSON in Settings.",
    "data": { "status": 422 }
}
```

**Action/Parameter Error** (HTTP 400):
```json
{
    "code": "analytics_error",
    "message": "Invalid action. Must be one of: page_stats, traffic_sources, ...",
    "data": { "status": 400 }
}
```

## Architecture

### Routing

The `Analytics` class registers a single route pattern for each tool. The handler extracts the tool name from the request route path and maps it to an ability slug via `ABILITY_MAP`, after applying `datamachine_analytics_ability_map`:

```php
const ABILITY_MAP = [
    'gsc'       => 'datamachine/google-search-console',
    'ga'        => 'datamachine/google-analytics',
];
```

### Execution Flow

1. `register_routes()` registers the core POST routes plus filtered extension routes
2. `check_permission()` validates the scoped `manage_flows` permission
3. `handle_request()` extracts tool name from route, looks up ability slug
4. Ability is retrieved via `wp_get_ability()` and executed with the JSON body
5. Error responses use HTTP 422 for configuration errors, 400 for input errors

### Ability Delegation

The REST layer is intentionally thin — it performs no data transformation. All business logic (authentication, API calls, result formatting) lives in the ability layer. Extension-backed routes, including Google Analytics, work when the extension registers the mapped ability slug.

## Examples

### cURL — Google Analytics Page Stats

```bash
curl -X POST https://chubes.net/wp-json/datamachine/v1/analytics/ga \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: {nonce}" \
  --cookie "{auth_cookie}" \
  -d '{"action": "page_stats", "limit": 10}'
```

### cURL — GSC Query Stats

```bash
curl -X POST https://chubes.net/wp-json/datamachine/v1/analytics/gsc \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: {nonce}" \
  --cookie "{auth_cookie}" \
  -d '{"action": "query_stats", "limit": 50, "query_filter": "wordpress"}'
```
