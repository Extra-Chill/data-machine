# ICS Calendar Handler

Generic ICS/iCal feed integration for importing events from any calendar feed supporting the ICS format.

## Overview

The ICS Calendar handler provides comprehensive support for importing events from any ICS (iCalendar) feed, including popular platforms like Tockify, Outlook, Apple Calendar, and Google Calendar ICS exports. Features automatic protocol conversion, venue override options, and keyword filtering.

## Features

### Calendar Feed Support
- **Universal ICS Support**: Works with any ICS/iCal feed URL
- **Protocol Conversion**: Automatic `webcal://` to `https://` conversion
- **Multiple Platforms**: Supports Tockify, Outlook, Apple Calendar, Google Calendar exports, and custom ICS feeds

### Venue Management
- **Venue Override**: Optional venue name override for consistent venue assignment
- **Address Override**: Venue address override for accurate location data
- **Automatic Venue Creation**: Creates venues with complete metadata when overrides are provided

### Event Filtering
- **Keyword Filtering**: Include/exclude keywords to filter relevant events
- **Case-Insensitive Matching**: Flexible keyword matching in event titles and descriptions
- **Multiple Keywords**: Support for comma-separated keyword lists

### Technical Features
- **Single-Item Processing**: Processes one event per job execution with duplicate prevention
- **Event Identifier Generation**: Uses EventIdentifierGenerator for consistent event identity
- **Processed Items Tracking**: Prevents duplicate imports using Data Machine's processed items system

## Configuration

### Required Settings
- **ICS URL**: The URL of the ICS feed to import from
- **Venue Name Override** (optional): Override venue names for consistent assignment
- **Venue Address Override** (optional): Override venue addresses for accurate geocoding

### Optional Settings
- **Include Keywords**: Comma-separated keywords to include (events must contain at least one)
- **Exclude Keywords**: Comma-separated keywords to exclude (events containing these are skipped)

## Usage Examples

### Basic ICS Import
```php
$config = [
    'ics_url' => 'https://example.com/calendar.ics',
    'venue_name_override' => 'Custom Venue Name',
    'venue_address_override' => '123 Main St, City, State 12345'
];
```

### Filtered Import
```php
$config = [
    'ics_url' => 'https://calendar.google.com/calendar/ical/.../basic.ics',
    'include_keywords' => 'concert,music,jazz',
    'exclude_keywords' => 'cancelled,postponed'
];
```

### WebCal Protocol
```php
$config = [
    'ics_url' => 'webcal://example.com/calendar.ics' // Automatically converted to https://
];
```

## Event Processing

### Data Mapping
- **Title**: Event summary from ICS VEVENT
- **Start/End Dates**: DTSTART/DTEND from ICS event
- **Description**: Event description from ICS DESCRIPTION
- **Location**: Venue information from ICS LOCATION (overridden if configured)
- **Time Zone**: Respects ICS timezone information

### Duplicate Prevention
```php
use DataMachineEvents\Utilities\EventIdentifierGenerator;

// Generate consistent identifier
$event_identifier = EventIdentifierGenerator::generate($title, $startDate, $venue);

// Check if already processed
if (apply_filters('datamachine_is_item_processed', false, $flow_step_id, 'ics_calendar', $event_identifier)) {
    continue;
}

// Mark as processed
do_action('datamachine_mark_item_processed', $flow_step_id, 'ics_calendar', $event_identifier, $job_id);
```

## Integration Architecture

### Handler Structure
- **IcsCalendar.php**: Main import handler with ICS parsing and event processing
- **IcsCalendarSettings.php**: Admin interface and configuration forms
- **johngrogg/ics-parser**: PHP library for reliable ICS parsing

### Data Flow
1. **Feed Retrieval**: Downloads ICS feed from configured URL
2. **Protocol Conversion**: Converts webcal:// to https:// if needed
3. **ICS Parsing**: Parses VEVENT components using ics-parser library
4. **Event Filtering**: Applies include/exclude keyword filtering
5. **Venue Processing**: Applies venue overrides or extracts from event location
6. **Event Mapping**: Converts ICS data to Data Machine event structure
7. **Duplicate Check**: Uses EventIdentifierGenerator for identity verification
8. **Event Upsert**: Creates/updates events using EventUpsert handler

## Supported ICS Features

### Event Properties
- **SUMMARY**: Event title
- **DESCRIPTION**: Event description
- **DTSTART/DTEND**: Event start and end times
- **LOCATION**: Venue information
- **TZID**: Timezone information

### Recurring Events
- **RRULE**: Recurrence rules (expanded to individual events)
- **EXDATE**: Exception dates
- **RDATE**: Additional recurrence dates

## Error Handling

### Feed Errors
- **Invalid URL**: Clear error messages for malformed URLs
- **Network Errors**: Timeout and connection error handling
- **Parse Errors**: Malformed ICS file detection

### Configuration Errors
- **Missing URL**: Validation for required ICS URL
- **Invalid Keywords**: Warning for malformed keyword lists

## Performance Considerations

### Feed Size Limits
- **Memory Usage**: Efficient parsing of large ICS feeds
- **Processing Limits**: Single-item processing prevents timeouts
- **Batch Handling**: Handles feeds with hundreds of events

### Optimization Features
- **Incremental Processing**: Only processes new/changed events
- **Caching**: Feed content caching for repeated imports
- **Error Recovery**: Continues processing after individual event errors

## Troubleshooting

### Common Issues
- **WebCal Links**: Ensure webcal:// URLs are accessible (may need manual conversion)
- **Timezone Issues**: Verify ICS feed timezone settings
- **Large Feeds**: Consider date range limiting for very large calendars
- **Encoding Problems**: Check ICS file encoding (UTF-8 recommended)

### Debug Information
- **Feed Validation**: Test ICS URL accessibility
- **Event Parsing**: Review parsed event data structure
- **Filter Matching**: Verify keyword filtering logic
- **Venue Assignment**: Check venue override application

The ICS Calendar handler provides flexible, reliable event import from any ICS-compatible calendar feed with comprehensive filtering and venue management capabilities.