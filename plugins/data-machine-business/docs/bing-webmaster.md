# Bing Webmaster Tools

**Owner**: Data Machine Business

**Tool ID**: `bing_webmaster`

**Ability**: `datamachine/bing-webmaster`

**Runtime files**:

- Tool: `inc/Tools/BingWebmaster.php`
- Ability: `inc/Abilities/Analytics/BingWebmasterAbilities.php`
- CLI: `inc/Cli/Commands/BingWebmasterCommand.php`

## Overview

Bing Webmaster Tools fetches search analytics data from the Bing Webmaster API. It provides search query stats, traffic and ranking data, page performance, and crawl statistics.

When Data Machine Business is active, it also contributes the `bing` analytics REST route through Data Machine core's `datamachine_analytics_ability_map` extension point.

## Configuration

### API Key

- Purpose: authenticates requests to the Bing Webmaster API
- Source: Bing Webmaster Tools -> Settings -> API Access -> API Key
- Storage: site option `datamachine_bing_webmaster_config`

Data Machine Business intentionally reuses the original Data Machine core option key so existing saved configurations are adopted without migration.

### Site URL

The configured site URL must match the site registered in Bing Webmaster Tools. If omitted, the ability uses the WordPress site URL.

Example stored config:

```php
array(
    'api_key'  => 'your-bing-api-key',
    'site_url' => 'https://example.com',
)
```

## Actions

- `query_stats` - search query performance
- `traffic_stats` - rank and traffic data
- `page_stats` - per-page metrics
- `crawl_stats` - Bing crawler activity and statistics

## REST Usage

```http
POST /wp-json/datamachine/v1/analytics/bing
Content-Type: application/json

{
  "action": "query_stats",
  "limit": 20,
  "days": 30
}
```

## WP-CLI Usage

```bash
wp datamachine analytics bing query_stats
wp datamachine analytics bing traffic_stats --format=json
wp datamachine analytics bing crawl_stats --limit=50
wp datamachine analytics bing page_stats --days=30
```

## Response Shape

```php
array(
    'success'       => true,
    'action'        => 'query_stats',
    'results_count' => 20,
    'date_range'    => array(
        'start_date' => '2026-01-01',
        'end_date'   => '2026-01-31',
        'days_ago'   => 3,
        'span_days'  => 30,
    ),
    'results'       => array(...),
)
```

The ability parses Bing's `/Date(timestamp)/` response format into `Y-m-d` dates and applies the optional `days` filter client-side because the Bing API endpoints do not accept date parameters.

## Error Messages

- Missing API key: `Bing Webmaster Tools not configured. Add an API key in Settings.`
- Invalid action: `Invalid action. Must be one of: query_stats, traffic_stats, page_stats, crawl_stats`
- Network failure: `Failed to connect to Bing Webmaster API: {error}`
- Parse failure: `Failed to parse Bing Webmaster API response.`
