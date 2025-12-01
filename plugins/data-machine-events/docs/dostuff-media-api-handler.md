# DoStuff Media API Handler

DoStuff Media JSON feed integration for importing events from venues using DoStuff Media platform (Waterloo Records, etc.).

## Overview

The DoStuff Media API handler provides seamless integration with DoStuff Media's JSON feed format, extracting event data from public feeds without requiring authentication. Features single-item processing and EventIdentifierGenerator for consistent duplicate detection across all import sources.

## Features

### Event Data Extraction
- **Comprehensive Event Data**: Title, dates, times, descriptions, and pricing
- **Venue Information**: Complete venue metadata including address, city, state, zip, and coordinates
- **Image Assets**: Event promotional images from multiple size options
- **Pricing Information**: Free event detection and ticket URLs
- **Artist Data**: Performer information including descriptions and external IDs
- **Category Classification**: Event categorization from DoStuff Media

### Technical Features
- **Single-Item Processing**: Processes one event per job execution to prevent timeouts
- **Event Identity Normalization**: Uses EventIdentifierGenerator for consistent duplicate detection
- **Processed Items Tracking**: Prevents duplicate imports using Data Machine's tracking system
- **No Authentication Required**: Works with public JSON feeds
- **Keyword Filtering**: Include/exclude events based on keyword matching

## Configuration

### Required Settings
- **Feed URL**: DoStuff Media JSON feed URL (e.g., `http://events.waterloorecords.com/events.json`)

### Optional Settings
- **Include Keywords**: Only import events containing any of these keywords (comma-separated)
- **Exclude Keywords**: Skip events containing any of these keywords (comma-separated)

## Usage Examples

### Basic Configuration
```php
$config = [
    'feed_url' => 'http://events.waterloorecords.com/events.json'
];
```

### Filtered Import
```php
$config = [
    'feed_url' => 'http://events.venue-name.com/events.json',
    'search' => 'concert, live music, band',
    'exclude_keywords' => 'trivia, karaoke, brunch'
];
```

## Event Processing

### Single-Item Pattern
The handler follows the single-item processing pattern, returning immediately after finding the first eligible event:

```php
foreach ($raw_events as $raw_event) {
    $standardized_event = $this->map_dostuff_event($raw_event);

    // Generate normalized identifier
    $event_identifier = EventIdentifierGenerator::generate(
        $standardized_event['title'],
        $standardized_event['startDate'],
        $standardized_event['venue']
    );

    // Check if already processed
    if ($this->isItemProcessed($event_identifier, $flow_step_id)) {
        continue;
    }

    // Mark as processed and return immediately
    $this->markItemProcessed($event_identifier, $flow_step_id, $job_id);
    return $this->successResponse([$dataPacket]);
}
```

### Data Mapping
The handler maps DoStuff Media's event structure to the standardized Data Machine event format:

- **Title**: Event title from `title` field
- **Description**: HTML-cleaned description from `description` field
- **Dates/Times**: Parsed from `begin_time` and `end_time` fields
- **Venue**: Complete venue data from nested `venue` object
- **Pricing**: "Free" designation from `is_free` boolean
- **Images**: Best available image from multiple AWS-hosted sizes
- **Artists**: Array of performer data with descriptions and external IDs
- **Ticket URL**: Purchase link from `buy_url` field
- **Source URL**: Constructed permalink using `https://do512.com` + `permalink`

### Venue Handling
Venue data is extracted from the event's nested `venue` object:

```php
if (!empty($event['venue']) && is_array($event['venue'])) {
    $venue = $event['venue'];
    $standardized_event['venue'] = sanitize_text_field($venue['title']);
    $standardized_event['venueAddress'] = sanitize_text_field($venue['address']);
    $standardized_event['venueCity'] = sanitize_text_field($venue['city']);
    $standardized_event['venueState'] = sanitize_text_field($venue['state']);
    $standardized_event['venueZip'] = sanitize_text_field($venue['zip']);

    if (!empty($venue['latitude']) && !empty($venue['longitude'])) {
        $standardized_event['venueCoordinates'] = $venue['latitude'] . ',' . $venue['longitude'];
    }
}
```

## API Integration

### Feed Structure
DoStuff Media provides JSON feeds with the following structure:
```json
{
  "event_groups": [
    {
      "events": [
        {
          "title": "Event Title",
          "description": "HTML description",
          "begin_time": "2024-01-15T20:00:00Z",
          "end_time": "2024-01-15T23:00:00Z",
          "venue": {
            "title": "Venue Name",
            "address": "123 Main St",
            "city": "Austin",
            "state": "TX",
            "zip": "78701",
            "latitude": 30.2672,
            "longitude": -97.7431
          },
          "buy_url": "https://tickets.example.com",
          "is_free": false,
          "imagery": {
            "aws": {
              "cover_image_h_630_w_1200": "https://...",
              "cover_image_w_1200_h_450": "https://...",
              "poster_w_800": "https://..."
            }
          },
          "artists": [
            {
              "title": "Artist Name",
              "description": "Artist bio",
              "hometown": "Austin, TX",
              "spotify_id": "spotify_id",
              "youtube_id": "youtube_id"
            }
          ],
          "category": "Music",
          "permalink": "/events/event-slug"
        }
      ]
    }
  ]
}
```

### HTTP Request
- **Method**: GET
- **Headers**: `Accept: application/json`, `User-Agent: Data Machine Events WordPress Plugin`
- **Timeout**: 30 seconds
- **Authentication**: None required

### Error Handling
- **Network Errors**: Logged with URL and error details
- **HTTP Errors**: Status codes other than 200 logged with URL and status
- **JSON Parsing**: Invalid JSON responses logged with error details
- **Empty Feeds**: Graceful handling of feeds with no events

## Supported Venues

The DoStuff Media API handler works with any venue using the DoStuff Media platform. Known examples include:

- **Waterloo Records** (`http://events.waterloorecords.com/events.json`)
- **Other Austin-area venues** using the DoStuff Media platform
- **Any venue** providing JSON feeds in the DoStuff Media format

## Integration Architecture

### Handler Structure
- **DoStuffMediaApi.php**: Main import handler with feed fetching and event mapping
- **DoStuffMediaApiSettings.php**: Configuration interface and validation

### Data Flow
1. **Feed URL Validation**: Ensures valid URL format and required configuration
2. **JSON Feed Fetching**: Retrieves event data from DoStuff Media feed
3. **Event Parsing**: Extracts events from `event_groups[].events[]` structure
4. **Keyword Filtering**: Applies include/exclude keyword filters
5. **Event Mapping**: Converts DoStuff Media format to standardized event structure
6. **Duplicate Prevention**: Uses EventIdentifierGenerator for identity verification
7. **Venue Processing**: Extracts and stores venue metadata separately
8. **Event Upsert**: Creates/updates events using EventUpsert handler

## Performance Features

### Efficient Processing
- **Single-Item Pattern**: Prevents timeout on large feeds by processing one event per execution
- **Early Filtering**: Applies keyword filters before full event processing
- **Memory Optimization**: Processes events individually without loading entire feed into memory

### Duplicate Prevention
- **Event Identity Normalization**: Consistent identification using EventIdentifierGenerator
- **Processed Items Tracking**: Database-backed tracking prevents duplicate imports
- **Past Event Filtering**: Automatically skips events that have already occurred

## Troubleshooting

### Common Issues
- **Invalid Feed URL**: Verify the URL format and accessibility
- **Empty Results**: Check if the venue has upcoming events or if keyword filters are too restrictive
- **JSON Parsing Errors**: Ensure the feed returns valid JSON in the expected format

### Debug Information
- **Feed Fetching**: Logs successful fetches with event counts
- **Event Processing**: Step-by-step processing information for each event
- **Filtering Results**: Keyword match/mismatch logging
- **Venue Extraction**: Venue data parsing verification

The DoStuff Media API handler provides reliable event import from DoStuff Media-powered venues with comprehensive venue data and efficient duplicate prevention.</content>
<parameter name="filePath">docs/dostuff-media-api-handler.md