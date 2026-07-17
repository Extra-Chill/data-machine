# Mediavine Reports

Data Machine Business exposes bounded, read-only publisher reports through the `datamachine/mediavine-reports` ability. It calls Mediavine's source GraphQL operations directly and does not persist responses, join them to GA4, or model revenue attribution.

## Report Matrix

| Ability action | GraphQL operation | Input type | Public grain | WP-CLI |
|---|---|---|---|---|
| `pages` | `pagesSummary` | `GetPagesSummaryInput!` | URL path | Ability only |
| `summary` | `metricsSummary` | `GetMetricsSummaryInput!` | Site total | Ability only |
| `backfill` | `pagesSummary` | `GetPagesSummaryInput!` | URL path across requested periods | Ability only |
| `devices` | `devicesMetricsSummary` | `GetDevicesMetricsSummaryInput!` | Device label | `wp datamachine analytics mediavine devices` |
| `countries` | `countriesReport` | `GetCountriesReportInput!` | Country | `wp datamachine analytics mediavine countries` |
| `sources` | `sourceReports` | `GetSourceReportsInput!` | Mediavine normalized source | `wp datamachine analytics mediavine sources` |
| `ad_units` | `adunitsMetrics` | `GetAdunitsMetricsInput!` | Parent ad unit or child ad unit x device | `wp datamachine analytics mediavine ad_units` |

The dimensional CLI accepts `--site-id`, `--start-date`, `--end-date`, `--period`, and `--format=table|json|csv`. JSON emits the complete ability envelope. Table and CSV emit stable action-specific row columns.

## Dimensional Fields

`devices` preserves `label`, pageview/session counts and RPM, revenue, monetizable counts, and monetizable RPM.

`countries` preserves `country`, pageview/session counts and percentages, net/page revenue, impressions, paid impressions, CPM, fill rate, viewability, pageview/session RPM, monetizable counts and percentages, and monetizable RPM.
Mediavine returns country `netRevenue` in 1/10,000 dollar units; the ability normalizes it to dollars so it matches the source report's public `netRevenue` semantics.

`sources` preserves `source`, revenue/net revenue, pageviews, sessions, impressions, pageview/session RPM, monetizable counts/RPM, and impression-per-pageview/session metrics. `source` is Mediavine's normalized acquisition bucket. It is not a raw referrer URL/domain and is not GA4 source/medium.

`ad_units` flattens the two source collections without losing their meaning:

- Parent rows have `grain=parent` and `deviceType=null`.
- Child rows have `grain=child` and preserve `deviceType`.
- Both retain ad-unit name, revenue, paid impressions, viewability, fill rate, session/pageview RPM, monetizable session/pageview RPM, and CPM.

Unknown string literals from Mediavine are preserved. An absent dimension remains JSON `null`, which is distinct from a literal `Unknown` bucket.

## Provenance

Every result includes:

- The requested and normalized Mediavine site identifiers.
- Requested dates and canonical upstream report dates.
- The ability action and GraphQL operation identity.
- The returned row count.
- `host_attribution.available=false` with a source-specific reason.

Country is the finest geography exposed here. These reports do not expose page x source/device/country/ad-unit combinations or row-level host attribution.

## Security

Publisher credentials remain in the server-side `datamachine_mediavine_config` option. Access tokens are cached transiently and are never part of ability output, CLI output, fixtures, or error payloads. The integration selects bounded report fields and does not log authenticated response bodies.
