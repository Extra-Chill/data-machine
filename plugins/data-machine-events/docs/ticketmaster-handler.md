# Ticketmaster Handler

The Ticketmaster handler (`inc/Steps/EventImport/Handlers/Ticketmaster/Ticketmaster.php`, `TicketmasterSettings`) plugs into `EventImportStep` through `HandlerRegistrationTrait` so it can be selected inside a Data Machine pipeline. Each execution paginates through Ticketmaster results up to `MAX_PAGE = 19`, rejects previously imported events before packet fan-out, and returns the remaining events as individual `DataPacket` objects.

## Configuration & Authentication

- **Auth**: Ticketmaster uses an auth provider (`TicketmasterAuth`) that supplies an `api_key`.
- **Handler settings** (from `TicketmasterSettings`): `classification_type` (required), `location` (lat,lng string), `radius` (miles), optional `genre`, optional `venue_id`, optional `search`, optional `exclude_keywords`, and optional `max_items`.

## Data Mapping

- The handler maps Ticketmaster API responses into a standardized `event` payload (title, dates/times, venue name, ticket URL, etc.), plus a separate `venue_metadata` array.
- The handler stores venue context into `EventEngineData::storeVenueContext()` and merges `price` into engine data (when available).
- The handler returns a `DataPacket` whose `body` contains JSON: `{ event, venue_metadata, import_source: "ticketmaster" }`.

## Unique Capabilities

Ticketmaster uses its stable upstream event ID for Data Machine processed-item tracking. Before creating packets, it removes repeated IDs from the current paginated response, skips processed or claimed IDs for the current flow, and checks the event identity index so neighboring city flows do not schedule child jobs for events that another city already imported. It sets `startDateTime` to one hour in the future, filters to events with `dates.status.code === "onsale"`, and supports include/exclude keyword filters.

## Pagination

The handler paginates Ticketmaster API results up to `MAX_PAGE = 19`. Fan-out defaults to 100 child jobs per run; flows can set `max_items` explicitly when a lower rollout bound is appropriate. Items beyond the cap remain unclaimed so later runs can process them.

Each run logs an `Import fan-out summary` with fetched, pre-fan-out deduped, eligible, fan-out limit, and schedule-candidate counts. When adding cities, start with `max_items` between 25 and 50, verify the deduped-to-scheduled ratio and parent completion time, then increase toward the default only if the worker queue remains healthy.

## Event Flow

1. `EventImportStep` instantiates `Ticketmaster` and reads `TicketmasterSettings` values.
2. Handler dedupes stable Ticketmaster IDs and existing event identities before returning bounded `DataPacket` candidates.
3. `EventEngineData` carries the structured payload into the pipeline.
4. `EventUpsert` receives the data, merges engine parameters, runs field-by-field change detection, assigns venue/promoter via `TaxonomyHandler`, syncs the `datamachine_event_dates` table, and optionally downloads featured images.

Every EventUpsert run uses the same identifier hash so duplicates do not slip through.
