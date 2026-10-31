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

Ticketmaster uses its stable upstream event ID plus a revision hash of the mapped event fields. A shared atomic source claim prevents neighboring city flows from selecting the same ID concurrently. Successful child jobs persist the revision in Data Machine's tracked-item ledger; failed jobs release the claim without persisting it. Unchanged revisions are skipped before fan-out, while changed dates, times, venues, titles, prices, and ticket URLs continue to EventUpsert. The generic `datamachine_should_reprocess_item` policy can also make an unchanged revision eligible again.

## Pagination

The handler paginates Ticketmaster API results up to `MAX_PAGE = 19`. Fan-out defaults to 100 child jobs per run; flows can set `max_items` explicitly when a lower rollout bound is appropriate. Items beyond the cap remain unclaimed so later runs can process them.

Each run logs an `Import fan-out summary` with fetched, unchanged, contended, overflow, source-claimed, and packets-ready counts. `source_claimed` and `packets_ready` are measured after the handler's bound and atomic claims; Data Machine's pipeline-batch log remains authoritative for child jobs actually scheduled. When adding cities, start with `max_items` between 25 and 50, compare `pre_fanout_deduped` with `packets_ready`, and confirm parent completion time and worker queue health before increasing toward the 100-item default.

## Event Flow

1. `EventImportStep` instantiates `Ticketmaster` and reads `TicketmasterSettings` values.
2. Handler dedupes stable Ticketmaster IDs and existing event identities before returning bounded `DataPacket` candidates.
3. `EventEngineData` carries the structured payload into the pipeline.
4. `EventUpsert` receives the data, merges engine parameters, runs field-by-field change detection, assigns venue/promoter via `TaxonomyHandler`, syncs the `datamachine_event_dates` table, and optionally downloads featured images.

Every EventUpsert run uses the same identifier hash so duplicates do not slip through.
