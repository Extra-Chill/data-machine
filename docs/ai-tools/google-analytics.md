# Google Analytics (GA4) Tool

**Tool ID**: `google_analytics`

**Ability**: `datamachine/google-analytics`

**File Locations**:
- Tool: `inc/Engine/AI/Tools/Global/GoogleAnalytics.php`
- Ability: `inc/Abilities/Analytics/GoogleAnalyticsAbilities.php`

**Registration**: `datamachine_global_tools` filter (available to all AI agents — pipeline + chat)

**@since**: 0.31.0

Fetches visitor analytics from Google Analytics (GA4) Data API. Provides page performance metrics, traffic sources, daily trends, real-time active users, top events, and user demographics.

## Architecture

Two-layer pattern shared by all analytics tools:

1. **Ability** (`GoogleAnalyticsAbilities`) — business logic, API calls, JWT authentication, result formatting. Registered as a WordPress ability (`datamachine/google-analytics`).
2. **Tool** (`GoogleAnalytics`) — AI agent wrapper, settings UI, configuration management. Delegates execution to the ability via `wp_get_ability()`.

All access layers (AI tool, CLI, REST) route through the ability.

## Configuration

### Required Setup

**Service Account JSON**
- Purpose: Authenticates via JWT to Google APIs (RS256 signed)
- Source: Google Cloud Console → IAM & Admin → Service Accounts → Keys
- Format: Full JSON key file contents
- Required fields: `client_email`, `private_key`
- Scope: `https://www.googleapis.com/auth/analytics.readonly`

**GA4 Property ID**
- Purpose: Identifies the GA4 property to query
- Source: Google Analytics Admin → Property Settings
- Format: Numeric string (e.g., `"123456789"`)

The same service account used for Google Search Console can be reused if the Analytics Data API is enabled in the Google Cloud project.

### Configuration Storage

**Option Key**: `datamachine_ga_config`

**Structure**:
```php
[
    'service_account_json' => '{"client_email":"...","private_key":"..."}',
    'property_id'          => '123456789',
]
```

### Authentication Flow

1. Build RS256 JWT with `client_email` as issuer and `analytics.readonly` scope
2. Exchange JWT for OAuth2 access token at `https://oauth2.googleapis.com/token`
3. Cache access token in transient (`datamachine_ga_access_token`) for ~58 minutes
4. Include Bearer token in all API requests

## Tool Parameters

### Required

**action** (string)
- `page_stats` — Per-page views, sessions, bounce rate, average session duration, active users
- `traffic_sources` — Where visitors come from (source/medium breakdown)
- `date_stats` — Daily trends over a date range
- `realtime` — Active users right now (ignores date parameters)
- `top_events` — Most triggered GA4 events with per-user counts
- `user_demographics` — Visitor country and device category breakdown

### Optional

**property_id** (string)
- Override the configured GA4 property ID
- Default: Value from `datamachine_ga_config`

**start_date** (string)
- Format: `YYYY-MM-DD`
- Default: 28 days ago
- Not used for `realtime` action

**end_date** (string)
- Format: `YYYY-MM-DD`
- Default: Yesterday
- Not used for `realtime` action

**limit** (integer)
- Default: 25
- Maximum: 10,000

**page_filter** (string)
- Filter results to pages with paths containing this string
- Only applies to actions that include `pagePath` as a dimension (`page_stats`)
- Silently ignored on incompatible actions

## API Integration

### GA4 Data API v1beta

**Base URL**: `https://analyticsdata.googleapis.com/v1beta/properties/`

**Standard Reports** (`POST /{property_id}:runReport`):
- Used by: `page_stats`, `traffic_sources`, `date_stats`, `top_events`, `user_demographics`
- Request body includes `dateRanges`, `dimensions`, `metrics`, `limit`, and optional `dimensionFilter`

**Realtime Reports** (`POST /{property_id}:runRealtimeReport`):
- Used by: `realtime`
- Dimensions: `unifiedScreenName`
- Metrics: `activeUsers`, `screenPageViews`
- Fixed limit of 25 rows

### Action-to-Report Mapping

| Action | Dimensions | Metrics |
|--------|-----------|---------|
| `page_stats` | `pagePath`, `pageTitle` | `screenPageViews`, `sessions`, `bounceRate`, `averageSessionDuration`, `activeUsers` |
| `traffic_sources` | `sessionSource`, `sessionMedium` | `sessions`, `activeUsers`, `screenPageViews`, `bounceRate` |
| `date_stats` | `date` | `sessions`, `screenPageViews`, `activeUsers`, `bounceRate`, `averageSessionDuration` |
| `top_events` | `eventName` | `eventCount`, `eventCountPerUser` |
| `user_demographics` | `country`, `deviceCategory` | `sessions`, `activeUsers`, `screenPageViews` |

### Response Format

**Standard report**:
```php
[
    'success'       => true,
    'action'        => 'page_stats',
    'date_range'    => ['start_date' => '2026-01-28', 'end_date' => '2026-02-24'],
    'results_count' => 25,
    'results'       => [
        [
            'pagePath'                => '/about/',
            'pageTitle'               => 'About',
            'screenPageViews'         => 1234,
            'sessions'                => 890,
            'bounceRate'              => 0.45,
            'averageSessionDuration'  => 120.5,
            'activeUsers'             => 780,
        ],
    ],
]
```

**Realtime report**:
```php
[
    'success'            => true,
    'action'             => 'realtime',
    'total_active_users' => 12,
    'total_page_views'   => 18,
    'results_count'      => 5,
    'results'            => [
        ['unifiedScreenName' => 'Home', 'activeUsers' => 5, 'screenPageViews' => 8],
    ],
]
```

## CLI Usage

```bash
# Page performance stats
wp datamachine analytics ga page_stats --allow-root

# Traffic sources with limit
wp datamachine analytics ga traffic_sources --limit=50 --allow-root

# Real-time active users
wp datamachine analytics ga realtime --allow-root

# Daily trends for blog pages as JSON
wp datamachine analytics ga date_stats --page-filter=/blog/ --format=json --allow-root

# Top events
wp datamachine analytics ga top_events --allow-root

# User demographics
wp datamachine analytics ga user_demographics --allow-root
```

**Flags**: `--start-date`, `--end-date`, `--limit`, `--page-filter`, `--format` (table|json|csv)

## REST API

```
POST /wp-json/datamachine/v1/analytics/ga
```

**Authentication**: Requires `manage_options` capability.

**Request Body**:
```json
{
    "action": "page_stats",
    "start_date": "2026-01-01",
    "end_date": "2026-02-24",
    "limit": 50,
    "page_filter": "/blog/"
}
```

## Error Handling

**Configuration Errors**:
- Missing service account JSON → `"Google Analytics not configured. Add service account JSON in Settings."`
- Invalid JSON (missing `client_email`/`private_key`) → `"Invalid service account JSON."`
- Missing property ID → `"No GA4 property ID configured or provided."`

**Authentication Errors**:
- JWT signing failure → `"Failed to sign JWT. Check private key."`
- Token exchange failure → `"Failed to get access token: {reason}"`

**API Errors**:
- Network failure → `"Failed to connect to Google Analytics API: {error}"`
- GA4 API error → `"GA4 API error: {message}"`
- Parse failure → `"Failed to parse Google Analytics API response."`

## Performance

**Token Caching**: Access tokens cached for ~58 minutes (3500s transient)
**Timeout**: 30 seconds per API request
**No result caching**: Real-time data priority
