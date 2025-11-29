# EventSchemaProvider

Centralized event schema provider for AI tools and Schema.org JSON-LD generation in the Data Machine Events plugin.

## Overview

The `EventSchemaProvider` class serves as the single source of truth for all event field definitions, providing a unified interface for:

- AI tool parameter definitions
- Schema.org JSON-LD structured data generation
- Field validation and defaults
- Parameter routing between engine data and AI inference

## Field Categories

### Core Fields
Essential event information that forms the foundation of every event:

- `title` - Event title (required)
- `startDate` - Start date (YYYY-MM-DD format)
- `endDate` - End date (YYYY-MM-DD format)
- `startTime` - Start time (HH:MM 24-hour format)
- `endTime` - End time (HH:MM 24-hour format)
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

$post_id = 123;
$schema = EventSchemaProvider::generateSchemaOrg($event_data, $venue_data, $post_id);

// Output structured data
echo '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>';
```

### Get AI Tool Parameters

```php
// Get all tool parameters for AI inference
$all_params = EventSchemaProvider::getAllToolParameters();

// Get only core parameters (title, dates, description)
$core_params = EventSchemaProvider::getCoreToolParameters();

// Get schema-specific parameters (offers, performer, organizer, etc.)
$schema_params = EventSchemaProvider::getSchemaToolParameters();
```

### Smart Parameter Routing

```php
// Route parameters between engine data and AI tools
$routing = EventSchemaProvider::engineOrTool($parameters, $handler_config, $engine_data);

// Returns:
// [
//     'engine' => ['startDate' => '2025-07-15', 'venue' => 'Central Park'],
//     'tool' => ['description', 'performer', 'price']
// ]
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
- **Organizer**: Promoter details with website links
- **Offers**: Pricing, availability, purchase URLs
- **Status**: Event scheduling status and rescheduling info
- **Images**: Featured images in multiple sizes
- **Event Type**: Rich result categorization

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