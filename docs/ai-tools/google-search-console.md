# Google Search Console Tool

**Tool ID**: `google_search_console`

**Ability**: `datamachine/google-search-console`

**File Locations**:
- Tool: `inc/Engine/AI/Tools/Global/GoogleSearchConsole.php`
- Ability: `inc/Abilities/Analytics/GoogleSearchConsoleAbilities.php`

**Registration**: `datamachine_global_tools` filter (available to all AI agents — pipeline + chat)

**@since**: 0.25.0

Fetches search analytics data from Google Search Console. Provides search query performance, page-level stats, URL inspection, and sitemap management.

## Architecture

Two-layer pattern shared by all analytics tools:

1. **Ability** (`GoogleSearchConsoleAbilities`) — business logic, API calls, JWT authentication. Registered as a WordPress ability (`datamachine/google-search-console`).
2. **Tool** (`GoogleSearchConsole`) — AI agent wrapper, settings UI, configuration management. Delegates execution to the ability via `wp_get_ability()`.

All access layers (AI tool, CLI, REST) route through the ability.

## Configuration

### Required Setup

**Service Account JSON**
- Purpose: Authenticates via JWT to Google APIs (RS256 signed)
- Source: Google Cloud Console → IAM & Admin → Service Accounts → Keys
- Format: Full JSON key file contents
- Required fields: `client_email`, `private_key`
- Scope: `https://www.googleapis.com/auth/webmasters.readonly`
- The service account email must be added as a user in GSC property settings

**Site URL**
- Purpose: Identifies the Search Console property to query
- Format: `sc-domain:example.com` (domain property) or `https://example.com/` (URL prefix)

### Configuration Storage

**Option Key**: `datamachine_gsc_config`

**Structure**:
```php
[
    'service_account_json' => '{"client_email":"...","private_key":"..."}',
    'site_url'             => 'sc-domain:chubes.net',
]
```

### Authentication Flow

1. Build RS256 JWT with `client_email` as issuer and `webmasters.readonly` scope
2. Exchange JWT for OAuth2 access token at `https://oauth2.googleapis.com/token`
3. Cache access token in transient (`datamachine_gsc_access_token`) for ~58 minutes
4. Include Bearer token in all API requests

## Tool Parameters

### Required

**action** (string)

Search Analytics actions:
- `query_stats` — Top search queries with clicks, impressions, CTR, position
- `page_stats` — Per-page search performance
- `query_page_stats` — Combined query + page breakdown
- `date_stats` — Daily search performance trends

URL & Sitemap actions:
- `inspect_url` — Check indexing status of a specific URL (requires `url` parameter)
- `list_sitemaps` — List all submitted sitemaps
- `get_sitemap` — Get details for a specific sitemap (requires `sitemap_url` parameter)
- `submit_sitemap` — Submit a sitemap to Google (requires `sitemap_url` parameter)

### Optional

**site_url** (string)
- Override the configured site URL
- Default: Value from `datamachine_gsc_config`

**start_date** (string)
- Format: `YYYY-MM-DD`
- Default: 28 days ago

**end_date** (string)
- Format: `YYYY-MM-DD`
- Default: 3 days ago (for finalized data)

**limit** (integer)
- Default: 25
- Maximum: 25,000

**url_filter** (string)
- Filter results to URLs containing this string

**query_filter** (string)
- Filter results to queries containing this string

**url** (string)
- Required for `inspect_url` action — the full URL to inspect

**sitemap_url** (string)
- Required for `get_sitemap` and `submit_sitemap` actions

## API Integration

### Search Analytics API

**Endpoint**: `POST https://www.googleapis.com/webmasters/v3/sites/{site_url}/searchAnalytics/query`

**Action-to-Dimensions Mapping**:

| Action | Dimensions |
|--------|-----------|
| `query_stats` | `query` |
| `page_stats` | `page` |
| `query_page_stats` | `query`, `page` |
| `date_stats` | `date` |

All search analytics responses include: `clicks`, `impressions`, `ctr`, `position` per row, plus dimension keys.

### URL Inspection API

**Endpoint**: `POST https://searchconsole.googleapis.com/v1/urlInspection/index:inspect`

Returns indexing status, mobile usability, and rich results information for a specific URL.

### Sitemaps API

**List**: `GET https://www.googleapis.com/webmasters/v3/sites/{site_url}/sitemaps`
**Get**: `GET https://www.googleapis.com/webmasters/v3/sites/{site_url}/sitemaps/{sitemap_url}`
**Submit**: `PUT https://www.googleapis.com/webmasters/v3/sites/{site_url}/sitemaps/{sitemap_url}`

### Response Format

**Search analytics**:
```php
[
    'success'       => true,
    'action'        => 'query_stats',
    'results_count' => 25,
    'results'       => [
        [
            'keys'        => ['wordpress analytics'],
            'clicks'      => 45,
            'impressions' => 1200,
            'ctr'         => 0.0375,
            'position'    => 8.2,
        ],
    ],
]
```

**URL inspection**:
```php
[
    'success'          => true,
    'action'           => 'inspect_url',
    'url'              => 'https://chubes.net/about/',
    'index_status'     => [
        'verdict'          => 'PASS',
        'coverage_state'   => 'Submitted and indexed',
        'indexing_state'   => 'INDEXING_ALLOWED',
        'last_crawl_time'  => '2026-02-20T10:30:00Z',
        'page_fetch_state' => 'SUCCESSFUL',
        'google_canonical' => 'https://chubes.net/about/',
        'user_canonical'   => 'https://chubes.net/about/',
        'crawled_as'       => 'DESKTOP',
        'robots_txt_state' => 'ALLOWED',
        'referring_urls'   => [],
        'sitemap'          => [],
    ],
    'mobile_usability' => ['verdict' => 'PASS', 'issues' => []],
    'rich_results'     => ['verdict' => 'PASS', 'detected_items' => []],
]
```

## CLI Usage

```bash
# Top search queries
wp datamachine analytics gsc query_stats --allow-root

# Page stats with URL filter
wp datamachine analytics gsc page_stats --url-filter=/blog/ --limit=50 --allow-root

# Daily trends as JSON
wp datamachine analytics gsc date_stats --format=json --allow-root

# Inspect a URL
wp datamachine analytics gsc inspect_url --url=https://chubes.net/about/ --allow-root

# List sitemaps
wp datamachine analytics gsc list_sitemaps --allow-root

# Submit a sitemap
wp datamachine analytics gsc submit_sitemap --sitemap-url=https://chubes.net/sitemap.xml --allow-root
```

**Flags**: `--start-date`, `--end-date`, `--limit`, `--url-filter`, `--query-filter`, `--url`, `--sitemap-url`, `--format` (table|json|csv)

## REST API

```
POST /wp-json/datamachine/v1/analytics/gsc
```

**Authentication**: Requires `manage_options` capability.

**Request Body**:
```json
{
    "action": "query_stats",
    "start_date": "2026-01-01",
    "end_date": "2026-02-24",
    "limit": 50,
    "query_filter": "wordpress"
}
```

## Error Handling

**Configuration Errors**:
- Missing service account JSON → `"Google Search Console not configured. Add service account JSON in Settings."`
- Invalid JSON → `"Invalid service account JSON. Ensure it contains client_email and private_key."`
- Missing site URL → `"No site URL configured or provided."`

**Authentication Errors**:
- JWT signing failure → `"Failed to sign JWT. Check private key."`
- Token exchange failure → `"Failed to get access token: {reason}"`

**API Errors**:
- Network failure → `"Failed to connect to Google Search Console API: {error}"`
- GSC API error → `"GSC API error: {message}"`

## Performance

**Token Caching**: Access tokens cached for ~58 minutes (3500s transient)
**Timeout**: 30 seconds per API request
**Data Freshness**: GSC data is finalized ~3 days after collection (default end_date is 3 days ago)
