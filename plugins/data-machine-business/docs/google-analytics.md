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
- `engagement`
- `new_vs_returning`

## Authentication

GA4 uses a Google service account with the `https://www.googleapis.com/auth/analytics.readonly` scope. Grant the service account access to the target GA4 property, then configure the service account JSON and numeric property ID in the `google_analytics` tool settings.
