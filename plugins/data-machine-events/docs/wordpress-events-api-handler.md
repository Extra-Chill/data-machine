# WordPress Events API Handler

Import events from external WordPress sites running Tribe Events Calendar or similar event plugins.

## Overview

The WordPress Events API handler connects to external WordPress sites via their REST API endpoints. It automatically detects the API format (Tribe Events v1, Tribe WP REST, or generic WordPress) and maps events to the Data Machine Events format.

## Features

### Auto-Format Detection
The handler automatically detects three API formats:
- **Tribe Events v1**: Native Tribe Events Calendar REST API (`/wp-json/tribe/events/v1/events`)
- **Tribe WP REST**: Tribe Events exposed via WordPress REST API (`/wp-json/wp/v2/tribe_events`)
- **Generic WordPress**: Standard WordPress posts with event metadata

### Complete Venue Extraction
- Full venue metadata extraction (name, address, city, state, zip, country, coordinates)
- Venue name override support for multi-stage venues
- Geographic coordinates for map display

### Keyword Filtering
- **Include Keywords**: Only import events containing specified keywords
- **Exclude Keywords**: Skip events containing specified keywords
- Search applies to event title and description

## Configuration

### Settings Fields

| Field | Description | Required |
|-------|-------------|----------|
| `endpoint_url` | Full REST API endpoint URL | Yes |
| `venue_name_override` | Consolidate all events under one venue name | No |
| `per_page` | Results per API request (1-100, default: 50) | No |
| `categories` | Category slugs filter (comma-separated) | No |
| `search` | Include keywords (comma-separated) | No |
| `exclude_keywords` | Exclude keywords (comma-separated) | No |

### Example Configuration
```php
$config = [
    'endpoint_url' => 'https://example.com/wp-json/tribe/events/v1/events',
    'venue_name_override' => 'Charleston Pour House',  // Optional
    'per_page' => 50,
    'categories' => 'concerts,festivals',
    'search' => 'live music,comedy',
    'exclude_keywords' => 'private,members only'
];
```

### Common Endpoint URLs

**Tribe Events Calendar v1 API:**
```
https://example.com/wp-json/tribe/events/v1/events
```

**Tribe Events via WordPress REST API:**
```
https://example.com/wp-json/wp/v2/tribe_events?_embed
```

**Generic WordPress Events:**
```
https://example.com/wp-json/wp/v2/events?_embed
```

## API Format Detection

### Detection Logic
```php
private function detect_api_format(array $response): string {
    // Tribe Events v1: Has 'events' and 'rest_url' keys
    if (isset($response['events']) && isset($response['rest_url'])) {
        return 'tribe_v1';
    }

    // Tribe WP REST: Array with 'start_date' or '_EventStartDate' meta
    if (is_array($response) && isset($response[0]['id'])) {
        if (isset($response[0]['start_date']) || isset($response[0]['meta']['_EventStartDate'])) {
            return 'tribe_wp';
        }
        return 'generic_wp';
    }

    return 'generic_wp';
}
```

## Data Mapping

### Tribe Events v1 Format

| Tribe Field | Event Details Attribute |
|-------------|------------------------|
| `title` | title |
| `description` | description |
| `start_date` | startDate, startTime |
| `end_date` | endDate, endTime |
| `venue.venue` | venue |
| `venue.address` | venueAddress |
| `venue.city` | venueCity |
| `venue.state` | venueState |
| `venue.zip` | venueZip |
| `venue.country` | venueCountry |
| `venue.phone` | venuePhone |
| `venue.website` | venueWebsite |
| `venue.geo_lat`, `venue.geo_lng` | venueCoordinates |
| `cost` | price |
| `website` | ticketUrl |
| `image.url` | image |

### Tribe WP REST Format

| Meta Field | Event Details Attribute |
|------------|------------------------|
| `title.rendered` | title |
| `content.rendered` | description |
| `meta._EventStartDate` | startDate, startTime |
| `meta._EventEndDate` | endDate, endTime |
| `meta._VenueName` | venue |
| `meta._VenueAddress` | venueAddress |
| `meta._VenueCity` | venueCity |
| `meta._VenueState` | venueState |
| `meta._VenueZip` | venueZip |
| `meta._VenueCountry` | venueCountry |
| `meta._VenueLat`, `meta._VenueLng` | venueCoordinates |
| `meta._EventCost` | price |
| `meta._EventURL` | ticketUrl |

### Generic WordPress Format

The handler attempts to extract event data from common field patterns:
- Title: `title.rendered`, `title`, `name`
- Description: `content.rendered`, `description`, `body`
- Dates: `start_date`, `event_date`, `date`, `startDate`
- Venue: `venue`, `venue_name`, `location`

## Venue Name Override

Use `venue_name_override` to consolidate events from venues with multiple stages:

**Example Problem:**
A venue has separate pages for "Main Stage" and "Deck Stage", but you want all events under one venue for a single map pin.

**Solution:**
```php
$config = [
    'endpoint_url' => 'https://example.com/wp-json/tribe/events/v1/events',
    'venue_name_override' => 'Charleston Pour House'
];
```

All imported events will use "Charleston Pour House" as the venue, regardless of the original venue name.

## Integration

### Handler Registration
Uses `HandlerRegistrationTrait` for self-registration:
```php
self::registerHandler(
    'wordpress_events_api',
    'event_import',
    self::class,
    __('WordPress Events API', 'datamachine-events'),
    __('Import events from external WordPress sites running Tribe Events or similar plugins', 'datamachine-events'),
    false,
    null,
    WordPressEventsAPISettings::class,
    null
);
```

### EventIdentifierGenerator
Uses consistent event identity for duplicate detection:
```php
$event_identifier = EventIdentifierGenerator::generate(
    $standardized_event['title'],
    $standardized_event['startDate'],
    $standardized_event['venue']
);
```

### Image Handling
Featured images are stored in engine data for downstream processing:
```php
if (!empty($standardized_event['image'])) {
    datamachine_merge_engine_data($job_id, [
        'image_url' => $standardized_event['image']
    ]);
}
```

## Keyword Filtering

### Include Keywords
Only import events containing at least one of the specified keywords:
```php
'search' => 'live music, comedy, jazz'
```
Events must contain "live music", "comedy", OR "jazz" in their title or description.

### Exclude Keywords
Skip events containing any of the specified keywords:
```php
'exclude_keywords' => 'private, members only, canceled'
```
Events containing "private", "members only", OR "canceled" will be skipped.

## Authentication

No authentication is required for public REST API endpoints. If the target site requires authentication, the handler will receive a 401/403 response.

## Error Handling

### Common Errors
- **Invalid URL**: URL validation fails before request
- **Non-200 Status**: API returned error status
- **Invalid JSON**: Response could not be parsed
- **No Events**: API returned empty event list

### Logging
All requests and responses are logged for debugging:
```php
$this->log('info', 'Starting WordPress Events API import', [...]);
$this->log('error', 'WordPress Events API request failed: ' . $error);
```

## Limitations

- Requires public REST API access on target site
- Cannot access private or draft events
- Rate limits depend on target site configuration
- Large result sets may require multiple API calls
