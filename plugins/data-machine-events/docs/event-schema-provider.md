# EventSchemaProvider

Centralized event schema provider for AI tools and Schema.org JSON-LD generation in the Data Machine Events plugin.

## Overview

The `EventSchemaProvider` class serves as the single source of truth for all event field definitions, providing a unified interface for:

- AI tool parameter definitions
- Schema.org JSON-LD structured data generation
- Field validation and defaults
- Parameter routing between engine data and AI inference

The class uses `DynamicToolParametersTrait` for dynamic parameter filtering based on engine data availability.

## Field Categories

### Core Fields
Essential event information that forms the foundation of every event:

- `title` - Event title (required)
- `startDate` - Start date (YYYY-MM-DD format). Falls back to `_datamachine_event_datetime` meta when attributes are empty so Schema.org output always has a start timestamp.
- `endDate` - End date (YYYY-MM-DD format). Falls back to `_datamachine_event_end_datetime` meta or synthetic +3h window to satisfy Google requirements.
- `startTime` - Start time (HH:MM 24-hour format) with the same meta fallback as `startDate`.
- `endTime` - End time (HH:MM 24-hour format) with the same meta fallback as `endDate`.
- `description` - Rich HTML description (required)

### Offer Fields
Pricing and ticket information:

- `price` - Ticket price (e.g., "$25" or "$20 adv / $25 door")
- `priceCurrency` - ISO 4217 currency code (default: USD)
- `ticketUrl` - URL to purchase tickets
- `offerAvailability` - Ticket availability status (InStock, SoldOut, PreOrder)
- `validFrom` - Sale start date/time (ISO-8601 format)

### Performer Fields
Artist and performer details:

- `performer` - Performing artist name
- `performerType` - Type of performer (Person, PerformingGroup, MusicGroup)

### Organizer Fields
Event promoter information:

- `organizer` - Organizer name
- `organizerType` - Type of organizer (Person, Organization)
- `organizerUrl` - Organizer website URL

### Status Fields
Event scheduling status:

- `eventStatus` - Event status (EventScheduled, EventPostponed, EventCancelled, EventRescheduled)
- `previousStartDate` - Original start date if rescheduled

### Type Fields
Event categorization for rich results:

- `eventType` - Event type (Event, MusicEvent, Festival, ComedyEvent, etc.)

## Usage Examples

### Generate Schema.org JSON-LD

```php
use DataMachineEvents\Core\EventSchemaProvider;

$event_data = [
    'title' => 'Summer Music Festival',
    'startDate' => '2025-07-15',
    'startTime' => '19:00',
    'venue' => 'Central Park',
    'performer' => 'The Jazz Quartet',
    'price' => '45.00',
    'ticketUrl' => 'https://example.com/tickets'
];

$venue_data = [
    'name' => 'Central Park',
    'address' => '123 Park Ave',
    'city' => 'New York',
    'state' => 'NY'
];

$organizer_data = [
    'name' => 'Live Nation',
    'type' => 'Organization',
    'url' => 'https://livenation.com'
];

$post_id = 123;
$schema = EventSchemaProvider::generateSchemaOrg($event_data, $venue_data, $organizer_data, $post_id);

// Output structured data
echo '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>';
```

### Get AI Tool Parameters

All parameter methods accept optional `$engine_data` to filter out parameters that already have values in engine data. This ensures the AI only sees parameters it needs to provide.

The `getEngineAwareKeys()` method excludes `description` and `title` from engine data filtering, ensuring the AI always generates these fields regardless of engine data contents.

```php
// Get all tool parameters, filtered by engine data
$all_params = EventSchemaProvider::getAllToolParameters($engine_data);

// Get core parameters (title, dates, description), filtered by engine data
$core_params = EventSchemaProvider::getCoreToolParameters($engine_data);

// Get schema-specific parameters (offers, performer, organizer, etc.), filtered by engine data
$schema_params = EventSchemaProvider::getSchemaToolParameters($engine_data);

// Example: If engine_data contains ['startDate' => '2025-07-15', 'venue' => 'Central Park']
// Then getCoreToolParameters($engine_data) will NOT include 'startDate' in the returned parameters
// The AI will never see or provide a value for 'startDate' since it already exists
```

### Field Validation and Defaults

```php
// Get all field definitions with defaults
$fields = EventSchemaProvider::getAllFields();

// Get default values for all fields
$defaults = EventSchemaProvider::getDefaults();

// Extract event data from parameters
$event_data = EventSchemaProvider::extractFromParameters($parameters);
```

## Schema.org Integration

The provider generates comprehensive Google Event structured data including:

- **Event Details**: Name, dates, times, description
- **Location**: Venue name, address, coordinates, contact info
- **Performer**: Artist information with proper typing
- **Organizer**: Promoter details with website links (via `buildOrganizerSchema()`)
- **Offers**: Pricing, availability, purchase URLs
- **Status**: Event scheduling status and rescheduling info
- **Images**: Featured images in multiple sizes
- **Event Type**: Rich result categorization

### Organizer Schema Generation

The `buildOrganizerSchema()` method generates organizer structured data with the following priority:

1. Uses organizer taxonomy data (`$organizer_data`) when available
2. Falls back to event_data fields (`organizer`, `organizerType`, `organizerUrl`) when taxonomy data is empty
3. Returns empty array if no organizer name is found

## Integration with VenueParameterProvider

Works alongside `VenueParameterProvider` to handle venue-specific parameters:

```php
// Venue parameters are handled separately
$venue_params = VenueParameterProvider::getToolParameters($handler_config);
$venue_data = VenueParameterProvider::extractFromParameters($parameters);

// Event schema focuses on event-specific data
$event_data = EventSchemaProvider::extractFromParameters($parameters);
```

## Architecture Benefits

- **Single Source of Truth**: All event field definitions centralized
- **Consistent AI Integration**: Unified parameter definitions across all handlers
- **Rich Structured Data**: Comprehensive Schema.org compliance
- **Maintainable**: Easy to add new fields or modify existing ones
- **Type Safety**: Proper field typing and validation
- **Extensible**: Easy to add new event types and field categories</content>
<parameter name="filePath">docs/event-schema-provider.md