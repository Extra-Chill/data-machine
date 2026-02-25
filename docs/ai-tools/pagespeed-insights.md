# PageSpeed Insights Tool

**Tool ID**: `pagespeed`

**Ability**: `datamachine/pagespeed`

**File Locations**:
- Tool: `inc/Engine/AI/Tools/Global/PageSpeed.php`
- Ability: `inc/Abilities/Analytics/PageSpeedAbilities.php`

**Registration**: `datamachine_global_tools` filter (available to all AI agents — pipeline + chat)

**@since**: 0.31.0

Runs Google PageSpeed Insights (Lighthouse) audits on any URL. Returns performance scores, Core Web Vitals, accessibility and SEO scores, and actionable optimization opportunities with estimated savings.

## Architecture

Two-layer pattern shared by all analytics tools:

1. **Ability** (`PageSpeedAbilities`) — API calls, Lighthouse result parsing, action-specific formatting. Registered as a WordPress ability (`datamachine/pagespeed`).
2. **Tool** (`PageSpeed`) — AI agent wrapper, settings UI, configuration management. Delegates execution to the ability via `wp_get_ability()`.

All access layers (AI tool, CLI, REST) route through the ability.

## Configuration

### Optional Setup

PageSpeed Insights works **without any API key** but gets rate-limited (HTTP 429). An optional API key removes rate limits.

**Google API Key**
- Purpose: Increases rate limits for PageSpeed API
- Source: Google Cloud Console → APIs & Services → Credentials
- Format: String (e.g., `AIzaSyD...`)
- Required: No — tool is always considered "configured"

### Configuration Storage

**Option Key**: `datamachine_pagespeed_config`

**Structure**:
```php
[
    'api_key' => 'AIzaSyD...',  // Optional
]
```

### Tool Configuration Flag

`requires_config` is set to `false` — this tool appears as available even without an API key.

## Tool Parameters

### Required

**action** (string)
- `analyze` — Full Lighthouse audit with all category scores (performance, accessibility, best-practices, SEO) and key performance metrics
- `performance` — Focused Core Web Vitals and performance metrics only
- `opportunities` — Optimization suggestions sorted by estimated savings (ms and bytes)

### Optional

**url** (string)
- URL to analyze
- Default: WordPress site home URL (`home_url('/')`)
- Can target any publicly accessible URL

**strategy** (string)
- `mobile` (default) — Simulates mobile device
- `desktop` — Simulates desktop device

## API Integration

### PageSpeed Insights API v5

**Endpoint**: `https://www.googleapis.com/pagespeedonline/v5/runPagespeed`
**Method**: GET
**Authentication**: Optional API key parameter

**Category values**: lowercase (`performance`, `accessibility`, `best-practices`, `seo`)

**Request behavior by action**:
- `analyze` and `opportunities`: Request all four categories
- `performance`: Request only the `performance` category

### Core Web Vitals Metrics

The following metrics are extracted from Lighthouse audits:

| Metric Key | Audit ID | Description |
|-----------|----------|-------------|
| `first_contentful_paint` | `first-contentful-paint` | Time to first visual content |
| `largest_contentful_paint` | `largest-contentful-paint` | Time to largest content element |
| `total_blocking_time` | `total-blocking-time` | Sum of blocking time from long tasks |
| `cumulative_layout_shift` | `cumulative-layout-shift` | Visual stability score |
| `speed_index` | `speed-index` | How quickly content is visually populated |
| `interaction_to_next_paint` | `interaction-to-next-paint` | Responsiveness to user input |

Each metric includes:
- `value` — Human-readable display value (e.g., `"2.1 s"`)
- `numeric` — Raw numeric value in milliseconds (or unitless for CLS)
- `score` — 0-100 integer score

### Response Format

**analyze**:
```php
[
    'success'  => true,
    'action'   => 'analyze',
    'url'      => 'https://chubes.net/',
    'strategy' => 'mobile',
    'scores'   => [
        'performance'    => 85,
        'accessibility'  => 92,
        'best-practices' => 100,
        'seo'            => 91,
    ],
    'metrics'  => [
        'first_contentful_paint'    => ['value' => '1.2 s', 'numeric' => 1234.5, 'score' => 90],
        'largest_contentful_paint'  => ['value' => '2.1 s', 'numeric' => 2100.0, 'score' => 75],
        'total_blocking_time'       => ['value' => '120 ms', 'numeric' => 120.0, 'score' => 85],
        'cumulative_layout_shift'   => ['value' => '0.05', 'numeric' => 0.05, 'score' => 95],
        'speed_index'               => ['value' => '2.5 s', 'numeric' => 2500.0, 'score' => 80],
        'interaction_to_next_paint' => ['value' => '200 ms', 'numeric' => 200.0, 'score' => 70],
    ],
]
```

**performance**:
```php
[
    'success'           => true,
    'action'            => 'performance',
    'url'               => 'https://chubes.net/',
    'strategy'          => 'mobile',
    'performance_score' => 85,
    'metrics'           => [ /* same as analyze */ ],
]
```

**opportunities**:
```php
[
    'success'       => true,
    'action'        => 'opportunities',
    'url'           => 'https://chubes.net/',
    'strategy'      => 'mobile',
    'scores'        => [ /* all category scores */ ],
    'results_count' => 3,
    'results'       => [
        [
            'id'            => 'render-blocking-resources',
            'title'         => 'Eliminate render-blocking resources',
            'description'   => 'Resources are blocking the first paint...',
            'score'         => 20,
            'savings_ms'    => 1200,
            'savings_bytes' => 45000,
        ],
    ],
]
```

Opportunities are sorted by `savings_ms` descending (highest savings first).

## CLI Usage

```bash
# Full Lighthouse audit (mobile)
wp datamachine analytics pagespeed analyze --allow-root

# Desktop performance check
wp datamachine analytics pagespeed performance --strategy=desktop --allow-root

# Optimization opportunities for a specific page
wp datamachine analytics pagespeed opportunities --url=https://chubes.net/blog/ --allow-root

# Full audit as JSON
wp datamachine analytics pagespeed analyze --format=json --allow-root
```

**Flags**: `--url`, `--strategy`, `--format` (table|json|csv)

## REST API

```
POST /wp-json/datamachine/v1/analytics/pagespeed
```

**Authentication**: Requires `manage_options` capability.

**Request Body**:
```json
{
    "action": "analyze",
    "url": "https://chubes.net/",
    "strategy": "desktop"
}
```

## Error Handling

**Input Validation**:
- Invalid action → `"Invalid action. Must be one of: analyze, performance, opportunities"`
- Invalid strategy → `"Invalid strategy. Must be mobile or desktop."`

**API Errors**:
- Network failure → `"Failed to connect to PageSpeed Insights API: {error}"`
- Rate limiting (no API key) → HTTP 429 from Google API
- Parse failure → `"Failed to parse PageSpeed Insights API response."`
- No Lighthouse results → `"No Lighthouse results returned."`
- API error → `"PageSpeed API error: {message}"`

## Performance

**Timeout**: 60 seconds (Lighthouse audits can be slow)
**No result caching**: Each request runs a fresh Lighthouse audit
**Rate limits**: Without API key, Google enforces rate limiting. With API key, standard Google API quotas apply.
