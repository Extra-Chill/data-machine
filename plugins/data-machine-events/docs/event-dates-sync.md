# Event Dates Sync

Syncs event dates from Event Details block attributes to the `datamachine_event_dates` table on save. Also handles ticket URL normalization for duplicate detection.

## Architecture

Event datetimes are stored in **two** places:

1. **Event Details block attributes** (`startDate`, `startTime`, `endDate`, `endTime`) — the authoring source of truth. Edited by humans and AI in the block editor.
2. **`datamachine_event_dates` table** — the query source of truth. Calendar queries, upcoming-event filters, admin columns, and Schema.org fallbacks all read from this table.

The `save_post` hook parses the block attributes and writes the computed MySQL DATETIME to the table. There is no postmeta middle layer — the redundant `_datamachine_event_datetime` / `_datamachine_event_end_datetime` meta keys were removed in issue #424.

## Location

`inc/Core/event-dates-sync.php`

## Key Features

### Automatic Synchronization

Monitors post saves and automatically syncs Event Details block data to the `datamachine_event_dates` table.

### Block Parsing

Parses Gutenberg blocks to extract datetime information from Event Details blocks.

### Denormalized post_status

The table includes a `post_status` column kept in sync via `transition_post_status` so date queries can filter to published events without joining the posts table.

## Key Function

### `data_machine_events_sync_datetime_meta(int $post_id, WP_Post $post, bool $update): void`

Syncs event datetime to the `datamachine_event_dates` table on save.

**Process:**
1. Validates post type is `data_machine_events`
2. Skips autosave operations
3. Parses blocks to find Event Details blocks
4. Extracts start/end dates and times from block attributes
5. Guards against malformed dates (issue #394) — skips the datetime write cleanly instead of writing a junk `0000-00-00 00:00:00` row
6. Combines into MySQL DATETIME format
7. Upserts or deletes the table row based on data presence
8. Syncs ticket URL meta for duplicate detection queries

**Datetime Logic:**
- Start datetime: `startDate + ' ' + startTime` (defaults to 00:00:00 if no time)
- End datetime: Uses `endDate + endTime` if provided, otherwise null
- If no start date, deletes the table row

## Integration Points

- **Event Details Block**: Automatically triggered on block saves
- **Calendar Block**: Queries the table for efficient date-based filtering (via `DateFilter` / `UpcomingFilter`)
- **EventUpsert**: Block content is generated from AI parameters; save_post handles table sync
- **Admin Interface**: Enables date-based sorting and filtering via table JOIN
- **EventSchemaProvider**: Falls back to the table (not meta) when block attributes are empty
- **EventHydrator**: Reads datetime from the table to hydrate calendar event data

## Data Format

**Storage Format:** MySQL DATETIME (`Y-m-d H:i:s`)

**Examples:**
- `2024-12-25 14:30:00` - December 25, 2024 at 2:30 PM
- `2024-01-15 00:00:00` - January 15, 2024 (midnight)

## Block Attribute Mapping

The system reads these attributes from Event Details blocks:

- `startDate`: Date in Y-m-d format
- `startTime`: Time in H:i:s format (optional)
- `endDate`: End date in Y-m-d format (optional)
- `endTime`: End time in H:i:s format (optional)

## Performance Benefits

- **Index Support**: Dedicated table with indexes on `start_datetime`, `end_datetime`, and `(post_status, start_datetime)`
- **Fast Filtering**: Enables efficient date range queries without postmeta JOINs
- **Sorting**: Allows ORDER BY on indexed datetime columns
- **Calendar Queries**: Powers responsive calendar block filtering

## Migration

The `EventDatesTable::backfill()` method performs a one-time migration of events that still have legacy `_datamachine_event_datetime` postmeta but no row in the table. New events are written directly to the table by `save_post`.
