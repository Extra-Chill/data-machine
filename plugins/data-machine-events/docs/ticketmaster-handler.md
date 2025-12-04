# Ticketmaster Handler

The Ticketmaster handler (`inc/Steps/EventImport/Handlers/Ticketmaster/Ticketmaster.php`, `TicketmasterSettings`) plugs into `EventImportStep` through `HandlerRegistrationTrait` so it can be selected inside a Data Machine pipeline. Each execution follows the single-item pattern: it normalizes the incoming title/date/venue via `Utilities/EventIdentifierGenerator::generate($title, $startDate, $venue)`, checks `datamachine_is_item_processed`, marks the identifier, and immediately returns the first eligible `DataPacket` so the pipeline stays incremental.

## Configuration & Authentication

- **API Key** (`api_key`): Ticketmaster Discovery API credential (required).
- **Geographic Location** (`geo_ip`, `city`, or `coordinates`): Defines the search area for discovery queries.
- **Optional Scope**: `classification_type`, `genre`, `venue_id`, `keyword` help limit the feed.
- **Authentication**: Uses `TicketmasterAuth` helper to manage API headers and rate limiting.

## Data Mapping

- **Event Details**: Title, start/end dates, start/end times, descriptions, classifications, and genre IDs map directly to Event Details block attributes.
- **Venue Metadata**: Name, address components, city/state/zip/country, capacity, website, phone, and coordinates are passed to `VenueService`/`Venue_Taxonomy` so the term meta stays synced for REST consumption.
- **Pricing & Tickets**: Ticket price ranges, availability, and purchase URLs populate the block’s pricing fields plus `offerAvailability` and `ticketUrl`.
- **Images & Media**: Poster artwork or gallery images are stored in `EventEngineData`, later downloaded by `WordPressPublishHelper` if `include_image` is enabled.
- **Taxonomy Info**: Classification and genre metadata attaches via `TaxonomyHandler` so badges and filters reflect Ticketmaster categories.

## Unique Capabilities

Ticketmaster handles genre/classification IDs, automatically offsets the API time window, and respects Ticketmaster’s rate limits. It also surfaces `promoter` data when available so `Venue_Taxonomy` or `promoter` taxonomy terms stay complete.

## Event Flow

1. `EventImportStep` instantiates `Ticketmaster` and reads `TicketmasterSettings` values.
2. Handler fetches the first eligible event, normalizes identity, and stores venue metadata via `VenueParameterProvider` helpers.
3. `EventEngineData` carries the structured payload into the pipeline.
4. `EventUpsert` receives the data, merges engine parameters, runs field-by-field change detection, assigns venue/promoter via `TaxonomyHandler`, syncs `_datamachine_event_datetime`, and optionally downloads featured images.

Every EventUpsert run uses the same identifier hash so duplicates never slip through, and venue metadata stays consistent thanks to `VenueService`/`Venue_Taxonomy` helpers.