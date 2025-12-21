# GoDaddy Calendar Handler

The GoDaddy Calendar handler imports events from GoDaddy Website Builder calendar widgets by pulling from the same public JSON endpoint the widget uses.

## Setup

1. Open the calendar page on the GoDaddy site.
2. Open DevTools → Network.
3. Reload the page.
4. Filter requests by `calendar` or `secureserver`.
5. Click the request to `.../v1/events/...`.
6. Copy the **Request URL**.
7. Paste it into the handler setting **Events JSON URL**.

Example endpoint:

`https://calendar.apps.secureserver.net/v1/events/{websiteId}/{pageId}/{widgetId}`

## Handler Settings

- `events_url` (required): Full JSON endpoint URL.
- `search` (optional): Include keywords (comma-separated).
- `exclude_keywords` (optional): Exclude keywords (comma-separated).
- Venue fields (optional): If provided, these override/augment event venue data.

## Data Mapping

Each JSON `events[]` item maps to a standardized event payload:

- `title` → `title`
- `desc` → `description`
- `start` → `startDate` + `startTime`
- `end` → `endDate` + `endTime`
- `location` → `venue` (only if no venue is configured)
- `allDay` → clears `startTime`/`endTime`

## Filters

- Global exclusion: titles containing `closed` are skipped.
- Keyword filtering: `search` and `exclude_keywords` apply to title + description.

## Notes

- The handler processes a single eligible event per run (incremental import pattern).
- Past events are skipped.
