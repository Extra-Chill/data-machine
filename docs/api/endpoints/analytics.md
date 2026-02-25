# Analytics Endpoints

**File Location**: `inc/Api/Analytics.php`

**@since**: 0.31.0

Unified REST API for all analytics integrations. Each endpoint delegates to its respective WordPress ability via `wp_get_ability()`.

## Endpoints

| Method | Route | Tool |
|--------|-------|------|
| `POST` | `/datamachine/v1/analytics/gsc` | Google Search Console |
| `POST` | `/datamachine/v1/analytics/bing` | Bing Webmaster Tools |
| `POST` | `/datamachine/v1/analytics/ga` | Google Analytics (GA4) |
| `POST` | `/datamachine/v1/analytics/pagespeed` | PageSpeed Insights |

## Authentication

All endpoints require `manage_options` capability. Returns HTTP 403 if the current user lacks permission.

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

### Bing Webmaster Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | Yes | `query_stats`, `traffic_stats`, `page_stats`, `crawl_stats` |
| `limit` | integer | No | Row limit (default: 20) |

### Google Analytics (GA4) Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | Yes | `page_stats`, `traffic_sources`, `date_stats`, `realtime`, `top_events`, `user_demographics` |
| `property_id` | string | No | GA4 property ID (overrides config) |
| `start_date` | string | No | `YYYY-MM-DD` (default: 28 days ago) |
| `end_date` | string | No | `YYYY-MM-DD` (default: yesterday) |
| `limit` | integer | No | Row limit (default: 25, max: 10000) |
| `page_filter` | string | No | Filter to pages containing this path string |

### PageSpeed Insights Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | Yes | `analyze`, `performance`, `opportunities` |
| `url` | string | No | URL to analyze (default: site home URL) |
| `strategy` | string | No | `mobile` (default) or `desktop` |

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
    "message": "Analytics ability \"datamachine/google-analytics\" not registered. Ensure WordPress 6.9+ and the ability class is loaded.",
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

The `Analytics` class registers a single route pattern for each tool. The handler extracts the tool name from the request route path and maps it to an ability slug via `ABILITY_MAP`:

```php
const ABILITY_MAP = [
    'gsc'       => 'datamachine/google-search-console',
    'bing'      => 'datamachine/bing-webmaster',
    'ga'        => 'datamachine/google-analytics',
    'pagespeed' => 'datamachine/pagespeed',
];
```

### Execution Flow

1. `register_routes()` registers all four POST routes
2. `check_permission()` validates `manage_options` capability
3. `handle_request()` extracts tool name from route, looks up ability slug
4. Ability is retrieved via `wp_get_ability()` and executed with the JSON body
5. Error responses use HTTP 422 for configuration errors, 400 for input errors

### Ability Delegation

The REST layer is intentionally thin — it performs no data transformation. All business logic (authentication, API calls, result formatting) lives in the ability layer.

## Examples

### cURL — Google Analytics Page Stats

```bash
curl -X POST https://chubes.net/wp-json/datamachine/v1/analytics/ga \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: {nonce}" \
  --cookie "{auth_cookie}" \
  -d '{"action": "page_stats", "limit": 10}'
```

### cURL — PageSpeed Audit

```bash
curl -X POST https://chubes.net/wp-json/datamachine/v1/analytics/pagespeed \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: {nonce}" \
  --cookie "{auth_cookie}" \
  -d '{"action": "analyze", "url": "https://chubes.net/", "strategy": "desktop"}'
```

### cURL — GSC Query Stats

```bash
curl -X POST https://chubes.net/wp-json/datamachine/v1/analytics/gsc \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: {nonce}" \
  --cookie "{auth_cookie}" \
  -d '{"action": "query_stats", "limit": 50, "query_filter": "wordpress"}'
```
