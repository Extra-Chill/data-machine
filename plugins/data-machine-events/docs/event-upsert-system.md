# EventUpsert System

Comprehensive event upsert architecture that intelligently creates or updates event posts based on event identity. Searches for existing events by (title, venue, startDate) and updates if found, creates if new, or skips if data unchanged.

## Overview

The EventUpsert system replaces traditional Publisher logic with smarter create/update operations. It provides field-by-field change detection, taxonomy management, and comprehensive event data handling while maintaining data integrity and preventing duplicates.

## Location

`inc/Steps/Upsert/Events/EventUpsert.php`

## Key Features

### Intelligent Event Matching

Finds existing events using title + venue + start date combination for accurate deduplication.

### Change Detection

Compares existing and incoming event data to determine if updates are needed.

### Taxonomy Management

Handles venue and promoter taxonomy assignment with custom handlers.

### Engine Data Integration

Merges engine snapshot data with AI-provided parameters, prioritizing engine data.

## Architecture Components

### Core Classes

- **EventUpsert**: Main handler extending `UpdateHandler`
- **Venue**: Venue taxonomy assignment utilities
- **Promoter**: Promoter taxonomy assignment utilities
- **EventSchemaProvider**: Schema.org data generation
- **VenueParameterProvider**: Venue data extraction

### Key Workflows

1. **Identity Resolution**: Extract title, venue, startDate for matching
2. **Existence Check**: Query for existing events using identity fields
3. **Change Detection**: Compare current vs incoming data
4. **Create/Update/Skip**: Execute appropriate action based on findings
5. **Taxonomy Assignment**: Handle venue and promoter relationships
6. **Image Processing**: Download and attach featured images
7. **Metadata Sync**: Ensure datetime meta stays current

## Key Methods

### `executeUpdate(array $parameters, array $handler_config): array`

Main entry point for event upsert operations.

**Parameters:**
- `$parameters`: AI-provided event data (title, venue, startDate, etc.)
- `$handler_config`: Handler configuration (post status, taxonomy settings, etc.)

**Returns:** Success response with action type and post data

**Actions:**
- `'created'`: New event post created
- `'updated'`: Existing event updated
- `'no_change'`: Event exists with identical data

### `findExistingEvent(string $title, string $venue, string $startDate): ?int`

Locates existing events by identity criteria.

**Matching Logic:**
1. Query by exact title match
2. Filter by start date (LIKE comparison)
3. Verify venue match if venue specified
4. Return post ID or null

### `hasDataChanged(array $existing, array $incoming): bool`

Performs field-by-field comparison of event data.

**Compared Fields:**
- Date/time fields (startDate, endDate, startTime, endTime)
- Location fields (venue, address)
- Commerce fields (price, ticketUrl)
- Schema.org fields (performer, organizer, eventStatus)
- Content fields (description)

### `createEventPost(array $parameters, array $handler_config, EngineData $engine, array $engine_parameters): int|WP_Error`

Creates new event posts with full data population.

**Process:**
1. Resolve post status and author from settings
2. Build merged event data (engine + AI parameters)
3. Generate Event Details block content
4. Create post with block content
5. Process featured images
6. Assign venue and promoter taxonomies
7. Update engine data with post information

### `updateEventPost(int $post_id, array $parameters, array $handler_config, EngineData $engine, array $engine_parameters): void`

Updates existing event posts with changed data.

**Process:**
1. Build merged event data
2. Update post title and content
3. Process featured images
4. Reassign taxonomies
5. Maintain existing relationships

## Taxonomy Handlers

### Venue Taxonomy Handler

- Custom handler registered for `venue` taxonomy
- Extracts venue data from parameters and engine context
- Creates/finds venue terms with metadata
- Assigns venue relationships to events

### Promoter Taxonomy Handler

- Custom handler for `promoter` taxonomy
- Maps Schema.org "organizer" field to promoter terms
- Supports configurable assignment modes (AI-decided, term-specific, skip)
- Handles promoter metadata (URL, type)

## Data Merging Logic

### Engine Data Priority

Engine parameters take precedence over AI-provided values since AI tool parameters are filtered at definition time to exclude already-known data.

### Parameter Resolution Order

1. Engine snapshot data (highest priority)
2. AI-provided parameters
3. Handler configuration overrides
4. System defaults (lowest priority)

## Featured Image Processing

### Engine-Based Images

- Retrieves image paths from engine data
- Downloads and attaches images using `WordPressPublishHelper`
- Respects handler `include_images` setting

### Configuration-Based Images

- Falls back to handler `eventImage` setting
- Supports both URLs and file paths

## Block Content Generation

### Event Details Block

Generates complete Event Details block content with all attributes:

```php
[
    'startDate', 'endDate', 'startTime', 'endTime',
    'venue', 'address', 'price', 'ticketUrl',
    'performer', 'performerType', 'organizer', 'organizerType',
    'eventStatus', 'priceCurrency', 'offerAvailability'
]
```

### Inner Block Processing

Converts HTML descriptions to paragraph blocks for rich content support.

## Error Handling

- Validates required parameters (title)
- Handles post creation failures
- Logs taxonomy assignment issues
- Provides detailed error responses

## Integration Points

- **Data Machine Engine**: Receives engine snapshots and merges data
- **WordPress Taxonomy System**: Manages venue and promoter relationships
- **Event Details Block**: Generates block content for frontend display
- **Schema.org**: Provides structured data through block attributes
- **Meta Storage**: Ensures datetime metadata stays synchronized

## Configuration Options

### Post Settings

- `post_status`: Draft, pending, or publish
- `post_author`: Default author fallback

### Taxonomy Settings

- `taxonomy_venue_selection`: Venue assignment mode
- `taxonomy_promoter_selection`: Promoter assignment mode (ai_decides, term_id, skip)

### Image Settings

- `include_images`: Enable/disable image processing
- `eventImage`: Fallback image URL/path

## Performance Considerations

- Efficient existence queries using post meta indexes
- Minimal database operations for unchanged events
- Batch taxonomy operations where possible
- Memory-efficient block parsing and generation

## Logging and Monitoring

- Debug logging for identity resolution
- Info logging for create/update operations
- Error logging for failed operations
- Context-rich log messages for troubleshooting

## Migration from Publisher

The EventUpsert system replaces the legacy Publisher with:

- Smarter duplicate detection
- Change-based update logic
- Better taxonomy handling
- Enhanced error reporting
- Improved data merging logic