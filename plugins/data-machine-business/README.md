# Data Machine Business

Business and enterprise integrations for [Data Machine](https://github.com/Extra-Chill/data-machine).

Data Machine Business connects WordPress automation, agents, and pipelines to analytics, search intelligence, productivity, messaging, email marketing, affiliate commerce, and media-maintenance services. Features are exposed through the WordPress Abilities API, Data Machine AI tools, pipeline handlers, REST endpoints, and WP-CLI as appropriate for each integration.

## Requirements

- WordPress 6.9+
- PHP 8.2+
- Data Machine core plugin, installed and active

## Installation

1. Install and activate Data Machine.
2. Install and activate Data Machine Business.
3. Configure only the integrations you intend to use in Data Machine settings.
4. Connect OAuth providers or add service credentials as described below.

## Feature Overview

| Integration | What it provides | Primary surfaces |
|---|---|---|
| Google Analytics 4 | Page, acquisition, audience, engagement, realtime, comparison, and cross-site journey reports | Ability, AI tool, WP-CLI |
| Google Search Console | Search analytics, URL inspection, sitemap management, and ranked SEO opportunities | Abilities, AI tool, REST, WP-CLI |
| Bing Webmaster Tools | Query, traffic, page, and crawl analytics | Ability, AI tool, WP-CLI |
| PageSpeed Insights | Lighthouse audits, Core Web Vitals, and optimization opportunities | Ability, AI tool, REST, WP-CLI |
| Google Search | Google Programmable Search results for agents and pipelines | AI tool |
| Google Sheets | Read worksheets and append structured rows | Abilities, fetch/publish handlers |
| Google Drive | List folders, export native Google files, download binaries, and feed files into pipelines | Abilities, fetch handler |
| Slack | Fetch channel messages and publish messages or thread replies | Abilities, fetch/publish handlers |
| Discord | Fetch channel messages and publish text or embeds | Abilities, fetch/publish handlers |
| Amazon Associates | Search products and generate Creators API affiliate links | AI tool |
| Sendy | Subscribe users, create or update campaigns, and read email-funnel metrics | Abilities |
| Mediavine | Fetch page-level and aggregate publisher revenue reports | Ability |
| Content analytics | Category-level engagement audits, editorial triage flags, and GSC opportunity ranking | Abilities |
| Media hygiene | Detect and safely remove orphan files and unreferenced attachments | Ability, WP-CLI |
| Agent context | Generate an `AGENTS.md` section from the registered business CLI command map | Data Machine memory composition |

## Abilities

The plugin registers the following WordPress abilities. Management-oriented abilities use Data Machine's management permission gate unless noted otherwise.

### Analytics And Search

| Ability | Purpose |
|---|---|
| `datamachine/google-analytics` | Run GA4 reports, realtime analytics, period comparisons, network-density reports, and ordered cross-host funnel reports |
| `datamachine/google-search-console` | Query search performance, inspect URLs, and list, inspect, or submit sitemaps |
| `datamachine/gsc-opportunity` | Rank snippet/CTR gaps, page-two demand, and SERP-captured queries using GSC data |
| `datamachine/bing-webmaster` | Query Bing search, traffic, page, and crawl statistics |
| `datamachine/pagespeed` | Run full, performance-focused, or opportunity-focused PageSpeed audits |
| `datamachine/mediavine-reports` | Fetch page reports, aggregate summaries, or multi-period revenue backfills with source provenance |
| `datamachine/content-performance` | Rank posts within a category by GA4 engagement while accounting for traffic and sample size |
| `datamachine/content-flags` | Produce an outcome-based editorial triage screen with confidence and query-intent caveats |

### Google Workspace

| Ability | Purpose |
|---|---|
| `datamachine/fetch-googlesheets` | Fetch raw values from a spreadsheet worksheet |
| `datamachine/publish-googlesheets` | Append rows to a spreadsheet worksheet |
| `datamachine/fetch-googledrive` | Fetch a folder for pipeline use, export native files, and optionally materialize binaries in a flow-scoped directory |
| `datamachine/list-googledrive-files` | Recursively or non-recursively list Drive file metadata with optional MIME and modification filters |
| `datamachine/read-googledrive-doc` | Export Docs, Sheets, or Slides as Markdown, text, or CSV |
| `datamachine/download-googledrive` | Download a non-Google binary file into the WordPress uploads tree or another allowed destination |

### Messaging And Email

| Ability | Purpose |
|---|---|
| `datamachine/post-message-slack` | Post Slack text or Block Kit content, including thread replies and link-unfurl controls |
| `datamachine/fetch-messages-slack` | Fetch and filter Slack channel history |
| `datamachine/post-message-discord` | Post a Discord message or embed |
| `datamachine/fetch-messages-discord` | Fetch Discord channel history with before/after pagination |
| `datamachine/sendy-subscribe` | Subscribe an email address to a Sendy list; intentionally supports public callers |
| `datamachine/sendy-push-campaign` | Create or update a Sendy campaign |
| `datamachine/sendy-metrics` | Read subscriber, campaign, or combined funnel metrics from Sendy |

### WordPress Operations

| Ability | Purpose |
|---|---|
| `datamachine/media-hygiene` | Diagnose, list, preview deletion, or delete orphan files and unused attachments |

## AI Tools

Data Machine Business contributes these tools to Data Machine chat and pipeline AI contexts:

| Tool | Purpose |
|---|---|
| `google_search` | Search Google Programmable Search, optionally restricted to a domain |
| `google_analytics` | Run GA4 analytics actions |
| `google_search_console` | Run Search Console analytics and inspection actions |
| `bing_webmaster` | Query Bing Webmaster Tools |
| `pagespeed` | Run PageSpeed Insights audits |
| `amazon_affiliate_link` | Search Amazon products and return an affiliate URL, title, thumbnail, and ASIN |

## Pipeline Handlers

The plugin adds these handlers to Data Machine's flow builder:

| Handler | Step type | Behavior |
|---|---|---|
| `googlesheets_fetch` | Fetch | Read a worksheet by row, by column, or as a complete spreadsheet |
| `googlesheets_publish` | Publish | Append mapped pipeline data to spreadsheet columns |
| `google_drive_fetch` | Fetch | Emit one unprocessed Drive file at a time, exporting native files and streaming binaries |
| `slack_fetch` | Fetch | Read channel history with count and timestamp filters and per-message deduplication |
| `slack_publish` | Publish | Send messages, append source URLs, reply in threads, and control link unfurling |
| `discord_fetch` | Fetch | Read channel history with message-ID pagination and per-message deduplication |
| `discord_publish` | Publish | Send plain messages or embeds and optionally append source URLs |

## WP-CLI

Every business-owned command uses a positional action. Run `wp help <command>` for the complete option reference.

### Google Analytics

```bash
wp datamachine analytics ga <action>
```

Actions:

- `page_stats`
- `traffic_sources`
- `date_stats`
- `realtime`
- `top_events`
- `user_demographics`
- `landing_pages`
- `landing_page_acquisition`
- `page_acquisition`
- `page_audience`
- `engagement`
- `new_vs_returning`
- `network_density`
- `path_sequence`

Common options include `--start-date`, `--end-date`, `--limit`, `--page-filter`, `--hostname`, `--sort-by`, `--order`, `--compare`, and `--format`.

```bash
wp datamachine analytics ga page_stats --hostname=example.com --compare
wp datamachine analytics ga landing_page_acquisition --hostname=example.com
wp datamachine analytics ga page_acquisition --page-filter=/blog/
wp datamachine analytics ga path_sequence --format=json
```

### Google Search Console

```bash
wp datamachine analytics gsc <action>
```

Actions:

- `query_stats`
- `page_stats`
- `query_page_stats`
- `date_stats`
- `inspect_url`
- `list_sitemaps`
- `get_sitemap`
- `submit_sitemap`

```bash
wp datamachine analytics gsc query_stats --limit=50
wp datamachine analytics gsc inspect_url --inspect-url=https://example.com/page/
wp datamachine analytics gsc submit_sitemap --sitemap-url=https://example.com/sitemap.xml
```

### Bing Webmaster Tools

```bash
wp datamachine analytics bing <action>
```

Actions: `query_stats`, `traffic_stats`, `page_stats`, and `crawl_stats`.

```bash
wp datamachine analytics bing traffic_stats --days=30
```

### PageSpeed Insights

```bash
wp datamachine analytics pagespeed <action>
```

Actions: `analyze`, `performance`, and `opportunities`.

```bash
wp datamachine analytics pagespeed analyze --page-url=https://example.com --strategy=mobile
wp datamachine analytics pagespeed opportunities --page-url=https://example.com --format=json
```

### Media Hygiene

```bash
wp datamachine media <action>
```

Actions:

- `diagnose` - summarize all detected dead weight
- `orphan-files` - list files with no attachment record
- `unused` - list attachments with no detected content reference
- `delete-orphans` - preview or delete orphan files
- `delete-unused` - preview or delete unused attachments

Delete actions are dry runs unless `--apply` is provided. Use `--all-sites` to scan an entire multisite network.

```bash
wp datamachine media diagnose --all-sites
wp datamachine media delete-unused --limit=50
wp datamachine media delete-unused --limit=50 --apply
```

## REST

The plugin owns two compatibility REST controllers in addition to ability routes exposed by the WordPress Abilities API:

| Endpoint | Purpose |
|---|---|
| `POST /wp-json/datamachine/v1/analytics/pagespeed` | Dispatch PageSpeed actions |
| `POST /wp-json/datamachine/v1/analytics/gsc` | Dispatch Search Console actions |

Both endpoints require Data Machine's `view_analytics` permission.

## Agent Context

Data Machine Business registers its own section with Data Machine's composable `AGENTS.md` memory system. The section is generated from the same `CommandRegistry` used to register WP-CLI commands, including each command's positional actions, so agent instructions stay aligned with the executable CLI surface.

## Configuration

### Unified Google OAuth: Sheets And Drive

Google Sheets and Google Drive share one `google` OAuth provider. Enable the Google Sheets API and Google Drive API in the same Google Cloud project, create OAuth 2.0 web credentials, and configure the client ID and secret in Data Machine settings.

The provider requests the Sheets scope plus the read-only Drive scopes required by the Drive handlers. Tokens created before Drive support was enabled must be disconnected and reconnected so Google can grant the additional scopes.

### Google Analytics 4

1. Enable the Google Analytics Data API in Google Cloud.
2. Create a service account and JSON key.
3. Grant the service account access to the GA4 property.
4. Configure the service account JSON and numeric property ID in Data Machine settings.

The integration supports both the stable Data API and the alpha funnel-report API used by `path_sequence`. See [Google Analytics](docs/google-analytics.md) for details.

### Google Search Console

1. Enable the Search Console API and URL Inspection API.
2. Create a service account and JSON key.
3. Add the service account email to the Search Console property.
4. Configure the JSON key and property URL in Data Machine settings.

Search analytics accepts both URL-prefix and `sc-domain:` properties. See [Google Search Console](docs/ai-tools/google-search-console.md).

### Google Search

Enable the Custom Search JSON API, create an API key, and create a Programmable Search Engine. Save the API key and Search Engine ID in the `google_search` tool settings.

### PageSpeed Insights

PageSpeed works without credentials but may be rate-limited. An optional Google API key can be added to the `pagespeed` tool settings for higher quotas.

### Slack

Create a Slack app with a bot token and grant the scopes needed by your flows:

- `chat:write` for publishing
- `channels:history` for public-channel history
- `groups:history` for private-channel history
- `channels:read` and `groups:read` when channel listing is needed

Add the bot to each channel it should access, then configure its `xoxb-...` token in Data Machine settings.

### Discord

Create a Discord application and bot, grant it Send Messages and Read Message History in the target channels, and configure the bot token in Data Machine settings.

### Bing Webmaster Tools

Configure the Bing Webmaster API key and site URL in Data Machine settings. Existing values stored under the original Data Machine option are adopted automatically. See [Bing Webmaster Tools](docs/bing-webmaster.md).

### Amazon Associates

Join Amazon Associates, create Amazon Creators API credentials, and configure the credential ID, credential secret, partner tag, and marketplace in the `amazon_affiliate_link` tool settings. See [Amazon Affiliate Link](docs/amazon-affiliate-link.md).

### Sendy

Sendy is config-driven: callers pass the Sendy installation URL and API key to each ability. `datamachine/sendy-metrics` can also receive an optional read-only database connection for metrics unavailable through Sendy's API. The plugin does not store Sendy credentials or consumer-specific list and brand IDs.

### Mediavine

Store the publisher account email and password in the `datamachine_mediavine_config` option. Reports use the publisher GraphQL API and cache a short-lived access token. Keep fetches low-frequency, such as monthly imports and one-time backfills.

Mediavine page reports expose paths but not hostnames. Results therefore include explicit provenance and report `host_attribution.available=false`; multisite consumers must resolve path ownership without guessing a host.

## Content Analytics Notes

`datamachine/content-performance` and `datamachine/content-flags` compare posts only within a category and require GA4 engagement data. A hostname must be supplied directly or through the `datamachine_analytics_default_hostname` filter on multisite properties.

The flags ability is a triage screen, not a quality score. It flags strong-demand pages with dwell far below their category median, reports sample confidence, and treats structural observations only as advisory notes. Query intent and editorial judgment remain outside the metric.

`datamachine/gsc-opportunity` builds on the Search Console ability to identify recoverable CTR gaps, ranking opportunities, and likely SERP-captured queries. It reuses the configured Search Console authentication and does not own a separate transport.

## Media Safety

Media-hygiene scan actions are read-only. Delete actions enforce a bounded batch limit and return a preview unless explicitly called with `apply=true` or `--apply`. Review previews before deleting media, especially on multisite installations.

## Compatibility

Several integrations were moved from Data Machine core into this plugin. Existing Google Analytics, Search Console, PageSpeed, Bing, Google Search, and Amazon settings continue to use their established option keys so activation does not require credential migration.

## License

GPL v2 or later

## Author

Chris Huber - [chubes.net](https://chubes.net)
