# Eventbrite Handler

Import events from public Eventbrite organizer pages via Schema.org JSON-LD extraction.

## Overview

The Eventbrite handler parses public Eventbrite organizer pages to extract events from embedded JSON-LD structured data. No API key or authentication is required - events are extracted directly from the public HTML page.

## Features

### JSON-LD Extraction
- **Schema.org Parsing**: Extracts Event schema from `<script type="application/ld+json">` tags
- **ItemList Support**: Handles both individual Event objects and ItemList containers
- **Complete Metadata**: Extracts venue, pricing, performer, and image data

### Configuration Options
- **organizer_url**: Full Eventbrite organizer page URL (required)
- **date_range**: Number of days into the future to import (default: 90)

## Usage

### Configuration
```php
$config = [
    'organizer_url' => 'https://www.eventbrite.com/o/lo-fi-brewing-14959647606',
    'date_range' => 90
];
```

### Example Organizer URLs
- `https://www.eventbrite.com/o/lo-fi-brewing-14959647606`
- `https://www.eventbrite.com/o/your-organizer-name-12345678`

## Data Mapping

### Eventbrite Schema.org to Event Details Block

| Eventbrite Field | Event Details Attribute |
|------------------|------------------------|
| `name` | title |
| `startDate` | startDate, startTime |
| `endDate` | endDate, endTime |
| `description` | description |
| `location.name` | venue |
| `location.address.streetAddress` | venueAddress |
| `location.address.addressLocality` | venueCity |
| `location.address.addressRegion` | venueState |
| `location.address.postalCode` | venueZip |
| `location.address.addressCountry` | venueCountry |
| `location.geo` | venueCoordinates |
| `offers.lowPrice` / `offers.highPrice` | price |
| `url` | ticketUrl |
| `performer.name` | artist |
| `image` | imageUrl |

## Integration

### EventIdentifierGenerator
Uses `EventIdentifierGenerator::generate()` for consistent event identity:
```php
$event_identifier = EventIdentifierGenerator::generate(
    $standardized_event['title'],
    $standardized_event['startDate'],
    $standardized_event['venue']
);
```

### Single-Item Processing
Processes one event per job execution with duplicate prevention via ProcessedItems tracking.

### Venue Metadata
Complete venue metadata extraction including:
- Address components (street, city, state, zip, country)
- Geographic coordinates
- Phone and website (when available)

## Handler Registration

Uses `HandlerRegistrationTrait` for self-registration:
```php
self::registerHandler(
    'eventbrite',
    'event_import',
    self::class,
    __('Eventbrite Events', 'data-machine-events'),
    __('Import events from any public Eventbrite organizer page via JSON-LD extraction', 'data-machine-events'),
    false,
    null,
    EventbriteSettings::class,
    null
);
```

## Advantages

- **No API Key Required**: Works with public organizer pages only
- **Schema.org Standard**: Leverages structured data that Eventbrite maintains for SEO
- **Complete Data**: Extracts venue, pricing, performer, and image information
- **Date Range Filtering**: Configurable future date window for imports

## Limitations

- Only works with public organizer pages
- Depends on Eventbrite's JSON-LD structure (standard Schema.org format)
- Cannot access private or draft events
