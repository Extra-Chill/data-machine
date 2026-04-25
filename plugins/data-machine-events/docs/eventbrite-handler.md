# Eventbrite Handler (Deprecated)

This handler has been consolidated into the Universal Web Scraper as the `EventbriteExtractor`.

Previously the handler lived at `inc/Steps/EventImport/Handlers/Eventbrite/Eventbrite.php` with `EventbriteSettings`. That standalone handler and its settings were removed; existing flows should migrate to the scraper-based extractor by reconfiguring the fetch step to use the `UniversalWebScraper` handler, which auto-detects Eventbrite organizer and event pages.

See `docs/universal-web-scraper-handler.md` for extraction behavior and `inc/Steps/EventImport/Handlers/WebScraper/Extractors/EventbriteExtractor.php` for the implementation.

## Migration

The `EventbriteExtractor` (since v0.15.5) parses Eventbrite pages by:

1. Detecting `eventbrite.com/o/` or `eventbrite.com/e/` URLs (or `evbuc.com` short links) in the page HTML.
2. Extracting **all** events from `ItemList` JSON-LD on organizer pages (not just the first event).
3. Handling individual event pages with a direct `Event` JSON-LD object.

No configuration changes are needed — the extractor auto-detects Eventbrite pages from the `source_url`.

## Data Mapping

The extractor preserves the same Schema.org field mapping:

| Eventbrite JSON-LD Field | Event Details Attribute |
|--------------------------|------------------------|
| `name` | title |
| `startDate` / `endDate` | date/time fields |
| `description` | description |
| `location.name` | venue |
| `location.address.*` | venue address components |
| `location.geo` | venue coordinates |
| `offers.lowPrice` / `offers.highPrice` | price |
| `url` | ticketUrl |
| `performer.name` | artist |
| `image` | imageUrl |
