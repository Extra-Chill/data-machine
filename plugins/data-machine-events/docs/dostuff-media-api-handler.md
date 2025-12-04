# DoStuff Media API Handler

**Handler**: `inc/Steps/EventImport/Handlers/DoStuffMediaApi/DoStuffMediaApi.php` 
**Settings**: `DoStuffMediaApiSettings` 
Discovered automatically by `EventImportStep` via `HandlerRegistrationTrait`.

## Workflow

- Each execution fetches the configured JSON feed and follows the single-item pattern: normalize title/date/venue with `Utilities/EventIdentifierGenerator::generate($title, $startDate, $venue)`, check `datamachine_is_item_processed`, mark the identifier, and return the first acceptable event.
- The handler merges parsed payloads into `EventEngineData`, stores venue metadata, and hands the packet to `EventUpsert` for final persistence.

## Configuration

- `feed_url` (required): Public JSON feed URL (e.g., `http://events.waterloorecords.com/events.json`).
- `include_keywords` / `exclude_keywords`: Comma-separated filters applied before normalization.
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