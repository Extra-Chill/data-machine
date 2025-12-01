# Ticketmaster Handler

Ticketmaster Discovery API integration for importing events with comprehensive venue metadata and pricing information.

## Overview

The Ticketmaster handler provides complete integration with Ticketmaster's Discovery API, extracting event data with full venue details, pricing, and availability. Features single-item processing and EventIdentifierGenerator for consistent duplicate detection across all import sources.

**Note**: The handler uses a hardcoded start time of +1 hour from the current time for consistent API behavior and timezone consistency across all imports.

## Features

### Event Data Extraction
- **Comprehensive Event Data**: Title, dates, times, descriptions, performers, organizers
- **Venue Information**: Complete venue metadata including address, capacity, coordinates
- **Pricing Data**: Ticket pricing, availability, and purchase links
- **Image Assets**: Event images and promotional materials
- **Event Classification**: Categories, genres, and event types

### Technical Features
- **Single-Item Processing**: Processes one event per job execution to prevent timeouts
- **Event Identity Normalization**: Uses EventIdentifierGenerator for consistent duplicate detection
- **Processed Items Tracking**: Prevents duplicate imports using Data Machine's tracking system
- **API Rate Limiting**: Respects Ticketmaster API quotas and implements backoff strategies
- **Fixed Start Time**: Hardcoded +1 hour from current time for consistent API behavior and timezone consistency

## Configuration

### Required Settings
- **API Key**: Ticketmaster Discovery API key
- **Location**: Geographic location for event discovery (city, state, or coordinates)

### Optional Settings
- **Search Radius**: Geographic search radius in miles/kilometers
- **Event Classification**: Filter by event categories or genres
- **Genre ID**: Specific Ticketmaster Genre ID for sub-filtering within the selected event type
- **Venue ID**: Specific Ticketmaster Venue ID to search

## Usage Examples

### Basic Configuration
```php
$config = [
    'api_key' => 'your_ticketmaster_api_key',
    'classification_type' => 'music',
    'location' => '32.7765,-79.9311', // Charleston, SC coordinates
    'radius' => 50
];
```

### Advanced Filtering
```php
$config = [
    'api_key' => 'your_ticketmaster_api_key',
    'location' => '39.9526,-75.1652', // Philadelphia coordinates
    'classification_type' => 'music',
    'radius' => 25,
    'genre' => 'KnvZfZ7vAeA', // Rock music genre ID
    'venue_id' => 'KovZpZAJledA' // Specific venue ID
];
```

## Event Processing

### Data Mapping
- **Title**: Event name from Ticketmaster API
- **Start/End Dates**: Event timing with timezone information
- **Description**: Event description and additional details
- **Venue**: Complete venue data with address, coordinates, and capacity
- **Performers**: Artist and performer information
- **Pricing**: Ticket price ranges and availability
- **Images**: Event promotional images and venue photos
- **Categories**: Event classification and genre information

### Duplicate Prevention
```php
use DataMachineEvents\Utilities\EventIdentifierGenerator;

// Generate normalized identifier
$event_identifier = EventIdentifierGenerator::generate($title, $startDate, $venue);

// Check if already processed
if (apply_filters('datamachine_is_item_processed', false, $flow_step_id, 'ticketmaster', $event_identifier)) {
    continue;
}

// Mark as processed and return immediately
do_action('datamachine_mark_item_processed', $flow_step_id, 'ticketmaster', $event_identifier, $job_id);
array_unshift($data, $event_entry);
return $data;
```

## Integration Architecture

### Handler Structure
- **Ticketmaster.php**: Main import handler with API integration and event processing
- **TicketmasterAuth.php**: API authentication and key management
- **TicketmasterSettings.php**: Admin configuration interface and form handling

### Data Flow
1. **API Authentication**: Validates Ticketmaster API credentials
2. **Location Search**: Queries events within specified geographic area
3. **Event Retrieval**: Fetches detailed event information including venue data
4. **Data Processing**: Maps Ticketmaster data to Data Machine event structure
5. **Venue Handling**: Processes venue information with coordinate lookup
6. **Duplicate Check**: Uses EventIdentifierGenerator for identity verification
7. **Event Upsert**: Creates/updates events using EventUpsert handler

## API Integration

### Ticketmaster Discovery API
- **Events Endpoint**: Location-based event discovery with filtering
- **Event Details**: Comprehensive event information and metadata
- **Venue Data**: Complete venue information including coordinates
- **Classification**: Event categorization and genre information

### Rate Limiting & Quotas
- **API Limits**: Respects Ticketmaster's rate limiting (5000 requests/hour)
- **Backoff Strategy**: Exponential backoff for rate limit violations
- **Quota Management**: Tracks API usage and prevents overages

## Error Handling

### Authentication Errors
- **Invalid API Key**: Clear error messages for authentication failures
- **Expired Credentials**: Token validation and refresh prompts

### API Errors
- **Rate Limiting**: Automatic retry with appropriate delays
- **Network Issues**: Timeout and connection error handling
- **Data Validation**: Event data validation and error logging

## Performance Features

### Efficient Processing
- **Single-Item Pattern**: Prevents timeout on large event sets
- **Incremental Sync**: Only processes new or changed events
- **Memory Optimization**: Efficient data structure handling

### Caching & Optimization
- **API Response Caching**: Reduces redundant API calls
- **Geocoding Caching**: Optimizes venue coordinate lookups
- **Event Identity Caching**: Fast duplicate detection

## Troubleshooting

### Common Issues
- **API Key Problems**: Verify Ticketmaster API credentials and quotas
- **Location Formatting**: Ensure proper city/state or coordinate format
- **Rate Limiting**: Monitor API usage and implement delays if needed
- **Geocoding Failures**: Check venue address data quality

### Debug Information
- **API Response Logging**: Detailed API request/response logs
- **Event Processing**: Step-by-step data transformation information
- **Venue Assignment**: Venue data extraction and geocoding results
- **Duplicate Detection**: EventIdentifierGenerator output verification

The Ticketmaster handler provides reliable, comprehensive event import from Ticketmaster's extensive event database with full venue metadata and efficient duplicate prevention.