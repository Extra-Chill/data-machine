# Ticketmaster Handler

The Ticketmaster handler (`inc/Steps/EventImport/Handlers/Ticketmaster/Ticketmaster.php`, `TicketmasterSettings`) plugs into `EventImportStep` through `HandlerRegistrationTrait` so it can be selected inside a Data Machine pipeline. Each execution follows the single-item pattern with automatic pagination: it fetches events from the Ticketmaster API (paginating through results up to MAX_PAGE=19), normalizes the incoming title/date/venue via `Utilities/EventIdentifierGenerator::generate($title, $startDate, $venue)`, checks `datamachine_is_item_processed`, marks the identifier, and immediately returns the first eligible `DataPacket` so the pipeline stays incremental.

## Configuration & Authentication

- **Auth**: Ticketmaster uses an auth provider (`TicketmasterAuth`) that supplies an `api_key`.
- **Handler settings** (from `TicketmasterSettings`): `classification_type` (required), `location` (lat,lng string), `radius` (miles), optional `genre`, optional `venue_id`, optional `search`, optional `exclude_keywords`.

## Data Mapping

- The handler maps Ticketmaster API responses into a standardized `event` payload (title, dates/times, venue name, ticket URL, etc.), plus a separate `venue_metadata` array.
- The handler stores venue context into `EventEngineData::storeVenueContext()` and merges `price` into engine data (when available).
- The handler returns a `DataPacket` whose `body` contains JSON: `{ event, venue_metadata, import_source: "ticketmaster" }`.

## Unique Capabilities

Ticketmaster automatically paginates through API results (up to `MAX_PAGE = 19`) and returns as soon as it finds the first eligible, unprocessed event. It sets `startDateTime` to one hour in the future to avoid importing near-immediate past events, filters to events with `dates.status.code === "onsale"`, and supports include/exclude keyword filters.

## Pagination

The handler paginates Ticketmaster API results up to `MAX_PAGE = 19`, advancing pages until it finds the first eligible unprocessed event.

## Event Flow

1. `EventImportStep` instantiates `Ticketmaster` and reads `TicketmasterSettings` values.
2. Handler fetches the first eligible event, normalizes identity with `EventIdentifierGenerator`, stores venue context via `EventEngineData::storeVenueContext()`, and returns a single `DataPacket`.
3. `EventEngineData` carries the structured payload into the pipeline.
4. `EventUpsert` receives the data, merges engine parameters, runs field-by-field change detection, assigns venue/promoter via `TaxonomyHandler`, syncs `_datamachine_event_datetime`, and optionally downloads featured images.

Every EventUpsert run uses the same identifier hash so duplicates do not slip through.