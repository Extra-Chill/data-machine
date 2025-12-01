# Dice FM Handler

Dice FM event integration for importing events from Dice FM platform with venue metadata extraction.

## Overview

The Dice FM handler provides seamless integration with Dice FM's event platform, extracting event data with venue information for location-based display. Features single-item processing and EventIdentifierGenerator for consistent duplicate detection.

## Features

### Event Data Extraction
- **Comprehensive Event Data**: Extracts title, dates, times, descriptions, and ticket URLs
- **Venue Metadata**: Venue name and address information (coordinates not provided by Dice FM API)
- **Image Support**: Event images and promotional materials
- **Pricing Information**: Ticket pricing and availability data

### Technical Features
- **Single-Item Processing**: Processes one event per job execution
- **Event Identity Normalization**: Uses EventIdentifierGenerator for consistent duplicate detection
- **Processed Items Tracking**: Prevents duplicate imports using Data Machine's tracking system

## Configuration

### Required Settings
- **API Key**: Dice FM API authentication key
- **City**: Target city for event discovery (required)

### Optional Settings
- **Include Keywords**: Only import events containing specified keywords (comma-separated)
- **Exclude Keywords**: Skip events containing specified keywords (comma-separated)

### Hardcoded Parameters
For consistent behavior and API optimization, the following parameters are hardcoded:
- **Page Size**: 100 events per API request
- **Event Types**: 'linkout,event' (includes both promoted and regular events)

## Usage Examples

### Basic Configuration
```php
$config = [
    'city' => 'Charleston, SC'
];
```

### Filtered Import
```php
$config = [
    'city' => 'New York, NY',
    'search' => 'concert, live music, band',
    'exclude_keywords' => 'trivia, karaoke, brunch'
];
```

## Event Processing

### Data Mapping
- **Title**: Event name from Dice FM API
- **Start/End Dates**: Event timing information
- **Description**: Event description and details
- **Venue**: Venue name and address information
- **Pricing**: Ticket information and pricing
- **Images**: Event promotional images

### Duplicate Prevention
```php
use DataMachineEvents\Utilities\EventIdentifierGenerator;

// Generate normalized identifier
$event_identifier = EventIdentifierGenerator::generate($title, $startDate, $venue);

// Check processing status
if (apply_filters('datamachine_is_item_processed', false, $flow_step_id, 'dice_fm', $event_identifier)) {
    continue;
}

// Mark as processed
do_action('datamachine_mark_item_processed', $flow_step_id, 'dice_fm', $event_identifier, $job_id);
```

## Integration Architecture

### Handler Structure
- **DiceFm.php**: Main import handler with API integration
- **DiceFmAuth.php**: Authentication and API key management
- **DiceFmSettings.php**: Admin configuration interface

### Data Flow
1. **API Authentication**: Validates Dice FM API credentials
2. **Location Search**: Queries events for specified location
3. **Event Retrieval**: Fetches detailed event information
4. **Venue Processing**: Extracts and normalizes venue data
5. **Event Mapping**: Converts to Data Machine event structure
6. **Duplicate Check**: Uses EventIdentifierGenerator for identity verification
7. **Event Upsert**: Creates/updates events using EventUpsert handler

## API Integration

### Dice FM API
- **Events Endpoint**: Location-based event discovery
- **Event Details**: Comprehensive event information
- **Venue Data**: Venue name and address information
- **Image Assets**: Event promotional materials

### Rate Limiting
- **API Quotas**: Respects Dice FM API rate limits
- **Batch Processing**: Efficient event retrieval
- **Error Recovery**: Automatic retry with backoff

## Error Handling

### Authentication Errors
- **Invalid API Key**: Clear error messages for authentication failures
- **Expired Credentials**: Token refresh and re-authentication prompts

### Data Errors
- **Missing Events**: Graceful handling of empty result sets
- **Malformed Data**: Event validation and error logging
- **Venue Issues**: Fallback handling for incomplete venue data

## Performance Features

### Efficient Processing
- **Single-Item Pattern**: Prevents timeout on large imports
- **Incremental Sync**: Only processes new events
- **Memory Optimization**: Efficient data processing

### Caching
- **API Response Caching**: Reduces redundant API calls
- **Venue Data Caching**: Optimizes venue lookups
- **Event Identity Caching**: Fast duplicate detection

## Troubleshooting

### Common Issues
- **API Key Problems**: Verify Dice FM API credentials
- **Location Mismatch**: Check location formatting and availability
- **Rate Limiting**: Implement appropriate delays between imports

### Debug Information
- **API Response Logging**: Detailed API interaction logs
- **Event Processing**: Step-by-step processing information
- **Venue Assignment**: Venue data extraction verification

The Dice FM handler provides reliable event import from Dice FM with venue data and efficient duplicate prevention.