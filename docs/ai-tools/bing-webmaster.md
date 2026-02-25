# Bing Webmaster Tools

**Tool ID**: `bing_webmaster`

**Ability**: `datamachine/bing-webmaster`

**File Locations**:
- Tool: `inc/Engine/AI/Tools/Global/BingWebmaster.php`
- Ability: `inc/Abilities/Analytics/BingWebmasterAbilities.php`

**Registration**: `datamachine_global_tools` filter (available to all AI agents — pipeline + chat)

**@since**: 0.23.0

Fetches search analytics data from Bing Webmaster Tools API. Provides search query stats, traffic and ranking data, page performance, and crawl statistics.

## Architecture

Two-layer pattern shared by all analytics tools:

1. **Ability** (`BingWebmasterAbilities`) — business logic, API calls, result formatting. Registered as a WordPress ability (`datamachine/bing-webmaster`).
2. **Tool** (`BingWebmaster`) — AI agent wrapper, settings UI, configuration management. Delegates execution to the ability via `wp_get_ability()`.

All access layers (AI tool, CLI, REST) route through the ability.

## Configuration

### Required Setup

**API Key**
- Purpose: Authenticates requests to the Bing Webmaster API
- Source: Bing Webmaster Tools → Settings → API Access → API Key
- Format: String

**Site URL**
- Purpose: Identifies the site to query
- Default: Falls back to `get_site_url()` if not configured
- Format: Full URL (e.g., `https://chubes.net`)

### Configuration Storage

**Option Key**: `datamachine_bing_webmaster_config`

**Structure**:
```php
[
    'api_key'  => 'your-bing-api-key',
    'site_url' => 'https://chubes.net',
]
```

## Tool Parameters

### Required

**action** (string)
- `query_stats` — Search query performance (keywords, impressions, clicks)
- `traffic_stats` — Rank and traffic statistics over time
- `page_stats` — Per-page performance metrics
- `crawl_stats` — Bing crawler activity and statistics

### Optional

**site_url** (string)
- Override the configured site URL
- Default: Value from config, then `get_site_url()`

**limit** (integer)
- Default: 20
- Truncates results beyond this count

## API Integration

### Bing Webmaster API

**Base URL**: `https://ssl.bing.com/webmaster/api.svc/json/`

**Authentication**: API key passed as `apikey` query parameter.

**Action-to-Endpoint Mapping**:

| Action | API Endpoint |
|--------|-------------|
| `query_stats` | `GetQueryStats` |
| `traffic_stats` | `GetRankAndTrafficStats` |
| `page_stats` | `GetPageStats` |
| `crawl_stats` | `GetCrawlStats` |

All requests are GET with `apikey` and `siteUrl` query parameters.

### Response Format

```php
[
    'success'       => true,
    'action'        => 'query_stats',
    'results_count' => 20,
    'results'       => [
        // Raw data from Bing's 'd' response key, limited to $limit rows
    ],
]
```

The results array contains the raw response from Bing's API `d` key, truncated to the requested limit.

## CLI Usage

```bash
# Query performance stats
wp datamachine analytics bing query_stats --allow-root

# Traffic stats as JSON
wp datamachine analytics bing traffic_stats --format=json --allow-root

# Crawl stats with limit
wp datamachine analytics bing crawl_stats --limit=50 --allow-root

# Page stats
wp datamachine analytics bing page_stats --allow-root
```

**Flags**: `--limit`, `--format` (table|json|csv)

## REST API

```
POST /wp-json/datamachine/v1/analytics/bing
```

**Authentication**: Requires `manage_options` capability.

**Request Body**:
```json
{
    "action": "query_stats",
    "limit": 50
}
```

## Error Handling

**Configuration Errors**:
- Missing API key → `"Bing Webmaster Tools not configured. Add an API key in Settings."`

**API Errors**:
- Network failure → `"Failed to connect to Bing Webmaster API: {error}"`
- Parse failure → `"Failed to parse Bing Webmaster API response."`

## Performance

**Timeout**: 15 seconds per API request
**No result caching**: Fresh data on each request
**No token caching**: API key auth has no token exchange step
