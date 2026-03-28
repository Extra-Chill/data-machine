# DoStuff Media API Handler (Deprecated)

This handler has been consolidated into the Universal Web Scraper as the `DoStuffExtractor`.

Previously the handler lived at `inc/Steps/EventImport/Handlers/DoStuffMediaApi/DoStuffMediaApi.php`. That standalone handler and its settings were removed; existing flows should migrate to the scraper-based extractor using the WP-CLI migration tooling or by reconfiguring the fetch step to use the `UniversalWebScraper` with the DoStuff extractor.

See `docs/universal-web-scraper-handler.md` for extraction behavior and `inc/Steps/EventImport/Handlers/WebScraper/Extractors/DoStuffExtractor.php` for the implementation.

## Workflow

- Each execution fetches the configured JSON feed and follows the single-item pattern: normalize title/date/venue with `Utilities/EventIdentifierGenerator::generate($title, $startDate, $venue)`, check `datamachine_is_item_processed`, mark the identifier, and return the first acceptable event.
- The handler merges parsed payloads into `EventEngineData`, stores venue metadata, and hands the packet to `EventUpsert` for final persistence.

## Configuration

- `feed_url` (required): Public JSON feed URL (e.g., `http://events.waterloorecords.com/events.json`). When migrating to the Universal Web Scraper, supply the same `source_url` / `feed_url` to the scraper settings and enable the DoStuff extractor behavior.
- `search` / `exclude_keywords`: Comma-separated filters applied before normalization.
- `date_range` (optional): Limits how far ahead the handler looks for events.
- No authentication is required since DoStuff publishes public feeds.

## Data Mapping

- **Event Fields**: Title, description, start/end dates/times, price, ticket URL, and source/permalink data map directly to Event Details attributes.
- **Venue Metadata**: Venue name, address, city, state, zip, country, coordinates, phone, and website feed `VenueService` / `Venue_Taxonomy` so venue term meta stays complete for REST endpoints and Event Details rendering.
- **Pricing & Images**: `price`, `offerAvailability`, and best available image URLs travel through `EventEngineData`; `WordPressPublishHelper` downloads images when `include_image` is enabled.
- **Taxonomies**: Artist and category data convert into taxonomy terms via `TaxonomyHandler` to keep badges and filters consistent.

## DoStuff Specifics

- Processes the DoStuff JSON structure (`event_groups[].events[]`) and automatically converts `buy_url`, `is_free`, and artist data.
- Keyword filters run before the EventIdentifier generation, ensuring only desired events are submitted.
- Coordinates (when provided) stay intact so the Event Details block can render Leaflet maps via `enqueue_root_styles()`.

## Event Handoff

`EventUpsert` receives the standardized payload, merges engine data with AI parameters, runs change detection across every field, delegates venue/promoter assignment to `TaxonomyHandler`, syncs `_datamachine_event_datetime`, and optionally downloads images for the event post. Venue metadata maintained by `VenueService` ensures REST routes (`/events/venues/{id}`) reflect the latest contact information.
