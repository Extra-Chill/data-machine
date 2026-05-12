# Internal Links Endpoints

**Implementation**: `inc/Api/InternalLinks.php`

**Base URL**: `/wp-json/datamachine/v1/links`

## Overview

Internal links endpoints expose the link graph abilities used by SEO audits and agents. They can build the cached graph, report orphaned posts, fetch backlinks, check broken URLs, and diagnose site-wide internal link coverage.

## Authentication

All routes require `PermissionHelper::can( 'manage_flows' )`. Requests can use WordPress application passwords or cookie auth.

The controller delegates to WordPress Abilities API abilities. If an ability is not registered, the response is `ability_not_found` with HTTP 500.

## Route Table

| Method | Route | Ability | Purpose |
|---|---|---|---|
| POST | `/links/audit` | `datamachine/audit-internal-links` | Scan content, build/cache the link graph, and return aggregates. |
| GET | `/links/orphans` | `datamachine/get-orphaned-posts` | Return posts with zero inbound internal links. |
| GET | `/links/backlinks` | `datamachine/get-backlinks` | Return posts linking to a target post. |
| POST | `/links/broken` | `datamachine/check-broken-links` | Check URLs from the cached graph with HTTP HEAD/GET fallback. |
| GET | `/links/diagnose` | `datamachine/diagnose-internal-links` | Report internal link coverage from stored metadata. |

GET routes read query parameters. POST routes read the JSON body.

## Core Parameters

| Parameter | Routes | Type | Default | Notes |
|---|---|---|---|---|
| `post_type` | audit, orphans, backlinks, broken | string | `post` | Post type scope for graph reads and scans. |
| `category` | audit | string | none | Category slug to limit audit scope. |
| `post_ids` | audit | array<int> | none | Specific posts to scan. |
| `force` | audit | boolean | `false` | Rebuild even when cached data exists. |
| `types` | audit, orphans, backlinks, broken | array<string> | all | Edge types to include, for example `html_anchor` or `wikilink`. GET accepts comma-separated values. |
| `limit` | orphans, broken | integer | route-specific | Maximum results or URLs to process. |
| `post_id` | backlinks | integer | required | Target post ID. |
| `scope` | broken | string | `internal` | `internal`, `external`, or `all`. |
| `timeout` | broken | integer | `5` | HTTP timeout per URL in seconds. |

## Response Shape

Successful responses return ability output directly after internal keys prefixed with `_` are stripped.

Audit response shape:

```json
{
  "success": true,
  "total_scanned": 120,
  "total_links": 640,
  "orphaned_count": 8,
  "avg_outbound": 5.3,
  "avg_inbound": 5.3,
  "orphaned_posts": [],
  "top_linked": [],
  "cached": false
}
```

Backlinks response shape:

```json
{
  "success": true,
  "post_id": 123,
  "backlink_count": 2,
  "backlinks": [
    {
      "source_id": 45,
      "title": "Related guide",
      "permalink": "https://example.com/related-guide/",
      "link_count": 1
    }
  ],
  "from_cache": true
}
```

Errors from abilities are returned as `internal_links_error` with HTTP 400 for ability-declared errors or HTTP 500 for `WP_Error` results.

## Agent Usage Examples

Run a fresh internal-link audit for posts:

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/links/audit \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"post_type":"post","force":true,"types":["html_anchor"]}'
```

Find orphaned pages from the cached graph:

```bash
curl "https://example.com/wp-json/datamachine/v1/links/orphans?post_type=page&limit=25" \
  -u username:application_password
```

Check external broken links with a conservative limit:

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/links/broken \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"scope":"external","limit":50,"timeout":5}'
```
