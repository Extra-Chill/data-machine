# Google Analytics (GA4)

Data Machine Business provides the Google Analytics (GA4) integration that previously lived in Data Machine core.

## Runtime Surfaces

| Surface | Identifier |
| --- | --- |
| Ability | `datamachine/google-analytics` |
| AI tool | `google_analytics` |
| REST route | `POST /wp-json/datamachine/v1/analytics/ga` |
| WP-CLI | `wp datamachine analytics ga <action>` |

The REST route and WP-CLI command are provided by Data Machine core for compatibility. They execute successfully when Data Machine Business is active and has registered the GA4 ability.

## Configuration Adoption

Existing GA4 installations do not need a migration step. Data Machine Business uses the same stored configuration keys as the former core implementation:

- `datamachine_ga_config` for service account JSON and property ID
- `datamachine_ga_access_token` for the cached access token transient

## Supported Actions

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

### Bounded page breakdowns

- **`landing_page_acquisition`** groups `landingPage` by `sessionSource` and `sessionMedium`. It answers how sessions were acquired for the page where each session began.
- **`page_acquisition`** groups `pagePath` by `sessionSource` and `sessionMedium`. It answers which acquisition channels drove sessions that touched a page, whether or not that page was the session entry.
- **`page_audience`** groups `pagePath` by `country` and `deviceCategory` for geographic and device analysis of touched pages.

These are fixed report presets rather than an arbitrary GA4 report builder. All support `page_filter`, `hostname`, sorting, row limits, and comparison. Standard report responses include pagination metadata with the requested limit, returned and fetched row counts, GA4's reported row count, and a truncation flag.

#### Unknown landing-page coverage

`landing_page_acquisition` preserves GA4's `(not set)` rows and adds `unknown_dimension_coverage` metadata. Google defines `landingPage` as the page path associated with the first `page_view` in a session and documents `(not set)` for this dimension when a session has no `page_view`. This identifies an attribution/collection gap, but the Data API response cannot prove whether an individual gap came from Measurement Protocol, consent or tag sequencing, automated traffic, or another collection path. Separately, `(not set)` session source/medium can indicate a missing `session_start` event.

The metadata reports observed unknown sessions, sessions represented by fetched rows, total sessions from GA4's `TOTAL` metric aggregation, unknown-cohort engagement, and a 5% materiality status. `share` and exact unknown/engagement counts are populated only when all dimensional rows were fetched. If GA4's row count exceeds the fetched rows, `status` is `partial`, exact fields are `null`, and `observed_share_lower_bound` makes the top-N limitation explicit. A partial cohort is still `material` when that lower bound is at least 5%; otherwise materiality is `unknown`.

Table and CSV CLI output warn when the exact share or observed lower bound is material. JSON retains the complete metadata. For page-level acquisition conclusions, operators should preserve the cohort in source data but exclude or segment it from ranked landing-page analysis, disclose the omitted share, and investigate tagging/collection separately. Do not relabel it as bot or Measurement Protocol traffic without independent evidence.

References: [Google's `(not set)` guidance](https://support.google.com/analytics/answer/13504892) and the [GA4 Data API schema](https://developers.google.com/analytics/devguides/reporting/data/v1/api-schema).

### `network_density` vs `path_sequence`

Both measure cross-site behavior on a multisite GA4 property, but they are not the same thing:

- **`network_density`** groups `hostName` x `pageReferrer`. `pageReferrer` is the immediately-preceding URL — a **single hop**, not an ordered session path — and is subject to referrer-policy stripping, sampling, and high-cardinality `(other)` bucketing. It is a *proxy* for "% of a site's sessions whose referrer was another site."

- **`path_sequence`** returns **true ordered, session-scoped cross-host journeys**. It is the answer to the instrument gap that `network_density` could only approximate.

#### How `path_sequence` works

1. Discovers the hosts present in the property/date range with a `hostName` runReport (top hosts by sessions).
2. For each **ordered pair** of distinct hosts (A, B), runs a 2-step **closed** `runFunnelReport` (v1alpha) — the only Data API surface that expresses ordered steps: step 1 = `hostName EXACT A`, step 2 = `hostName EXACT B`. In a closed funnel users must enter at step 1, so step 2's `activeUsers` = users who reached B **after** A, in order. (`funnelNextAction` can't be used for this: GA4 restricts the next-action dimension to `eventName` / page / screen dimensions and rejects `hostName`, so explicit ordered step pairs are the correct construction.)
3. Returns, per host: `entry_users` (funnel step-1 activeUsers), `onward_users` (the largest single ordered next-hop — a lower-bound floor for "reached ≥1 other site"; per-destination funnels can't be summed without double-counting users who reached multiple hosts), and `next_hosts` (ordered host -> next-host transitions with `activeUsers`, descending).

Fan-out is optimized: host pairs are probed unordered (A↔B once); the reverse `B -> A` funnel is only run when `A -> B` had at least one user, since zero shared users in one direction means zero in the other. The `limit` input selects how many top hosts (by sessions) to pair — default **6**, clamped to **12**.

This lets a consumer compute "% of each host's users reaching ≥1 other site" (`onward_users / entry_users`) and rank the top ordered cross-site paths. **In-network bucketing is the consumer's job** — the action is property-agnostic and returns every ordered host-to-host transition; the calling agent decides which hosts count as "in network."

#### `path_sequence` caveats

- **Data source: GA4 Data API v1alpha funnel report.** `runFunnelReport` is an alpha API and may change.
- **User-scoped metric:** funnels count `activeUsers`, not sessions (the funnel surface exposes no sessions metric). "Ordered" means the user reached B after A; without a `withinDurationFromPriorStep` constraint the two steps may span sessions.
- **Sampling:** funnel reports are subject to GA4 sampling on large date ranges.
- **2-hop transitions:** each pair funnel yields an ordered `A -> B` hop. Arbitrary N-hop chains (`A -> B -> C`) are not returned in one row; deeper chains are composed by the consumer from the transition matrix.
- **Host cap:** only the top hosts (by sessions) are paired, so fan-out is bounded — N hosts cost up to N×(N−1) funnel calls.

#### Long-term target: BigQuery export

The fully-accurate source of truth is a **BigQuery export tap**: with the GA4 property's events exported to BigQuery, a session-level query (events nested per session, ordered by `event_timestamp`, hostname per hit) yields exact, unsampled, arbitrary-depth ordered host paths. No BigQuery credentials/config exist today, so `path_sequence` implements the best available Data-API approximation. When BigQuery config is added, it becomes the intended replacement data source for this action.

## Authentication

GA4 uses a Google service account with the `https://www.googleapis.com/auth/analytics.readonly` scope. Grant the service account access to the target GA4 property, then configure the service account JSON and numeric property ID in the `google_analytics` tool settings.
