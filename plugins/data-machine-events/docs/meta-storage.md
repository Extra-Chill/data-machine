# Meta Storage

Core plugin feature that stores event datetime in post meta for efficient SQL queries. Monitors Event Details block changes and syncs to post meta automatically.

## Overview

The meta storage system provides performant event querying by maintaining synchronized datetime metadata. When Event Details blocks are saved, the system automatically extracts datetime information and stores it in dedicated post meta fields for fast database queries.

## Location

`inc/Core/meta-storage.php`

## Key Features

### Automatic Synchronization

Monitors post saves and automatically syncs Event Details block data to post meta.

### Dual Meta Keys

Maintains both start and end datetime metadata for comprehensive event querying.

### Block Parsing

Parses Gutenberg blocks to extract datetime information from Event Details blocks.

## Constants

- `EVENT_DATETIME_META_KEY`: `'_datamachine_event_datetime'` - Stores start datetime
- `EVENT_END_DATETIME_META_KEY`: `'_datamachine_event_end_datetime'` - Stores end datetime

## Key Function

### `datamachine_events_sync_datetime_meta(int $post_id, WP_Post $post, bool $update): void`

Syncs event datetime to post meta on save.

**Process:**
1. Validates post type is `datamachine_events`
2. Skips autosave operations
3. Parses blocks to find Event Details blocks
4. Extracts start/end dates and times from block attributes
5. Combines into MySQL DATETIME format
6. Updates or deletes meta keys based on data presence

**Datetime Logic:**
- Start datetime: `startDate + ' ' + startTime` (defaults to 00:00:00 if no time)
- End datetime: Uses `endDate + endTime` if provided, otherwise calculates start + 3 hours
- If no start date, deletes both meta keys

## Integration Points

- **Event Details Block**: Automatically triggered on block saves
- **Calendar Block**: Uses meta for efficient date-based queries
- **EventUpsert**: Keeps meta synchronized during programmatic updates
- **Admin Interface**: Enables date-based sorting and filtering

## Usage Examples

### Querying Events by Date

```php
// Find events starting today
$today = date('Y-m-d');
$args = [
    'post_type' => 'datamachine_events',
    'meta_query' => [
        [
            'key' => '_datamachine_event_datetime',
            'value' => $today,
            'compare' => 'LIKE'
        ]
    ]
];
$events = get_posts($args);
```

### Sorting Events by Date

```php
$args = [
    'post_type' => 'datamachine_events',
    'meta_key' => '_datamachine_event_datetime',
    'orderby' => 'meta_value',
    'order' => 'ASC'
];
$events = get_posts($args);
```

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

- **Index Support**: Post meta queries can use database indexes
- **Fast Filtering**: Enables efficient date range queries
- **Sorting**: Allows ORDER BY on datetime fields
- **Calendar Queries**: Powers responsive calendar block filtering

## Error Handling

- Invalid dates fall back gracefully
- Missing time defaults to appropriate values
- Parse errors don't break the save process
- Logs datetime calculation issues

## Migration Notes

When upgrading from older versions, existing Event Details blocks will automatically sync their datetime metadata on the next save operation.