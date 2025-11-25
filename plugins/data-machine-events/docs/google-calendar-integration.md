# Google Calendar Integration

Comprehensive Google Calendar integration with calendar ID/URL handling and ICS generation capabilities.

## Overview

Data Machine Events provides robust Google Calendar integration through the Data Machine pipeline. Features automatic calendar detection, URL resolution, and ICS generation for seamless event imports.

## Features

### Calendar Detection & Resolution
- **Smart Detection**: Automatically identifies calendar URLs and calendar IDs
- **URL Support**: Handles both Google Calendar web URLs and calendar IDs
- **ICS Generation**: Creates public ICS URLs from calendar IDs
- **Flexible Configuration**: Supports both `calendar_id` and `calendar_url` configuration options

### Authentication System
- **OAuth2 Integration**: Secure Google Calendar API authentication
- **Token Management**: Automatic token refresh and validation
- **Error Handling**: Comprehensive authentication error management
- **User-Friendly Setup**: Step-by-step authentication wizard

### Event Processing
- **Real-time Sync**: Import events directly from Google Calendar
- **Event Mapping**: Automatic field mapping to Event Details block attributes
- **Venue Extraction**: Intelligent venue location parsing
- **Recurring Events**: Support for recurring event series

## Configuration

### Calendar Setup Options

#### Option 1: Calendar ID
```php
$calendar_config = [
    'calendar_id' => 'calendar_123@example.com'
];
```

#### Option 2: Calendar URL
```php
$calendar_config = [
    'calendar_url' => 'https://calendar.google.com/calendar/embed?src=calendar_123@example.com'
];
```

### Authentication Configuration
- **Google Cloud Project**: Requires Google Calendar API enabled
- **OAuth2 Credentials**: Client ID and client secret required
- **Redirect URI**: Must match Google Cloud Console configuration

## Usage

### Manual Configuration
1. **Navigate**: Data Machine → Event Import → Add Handler
2. **Select**: Google Calendar handler
3. **Authenticate**: Complete OAuth2 flow
4. **Configure**: Enter calendar ID or URL
5. **Schedule**: Set up automatic import schedule

### Automatic Detection
```php
use DataMachineEvents\Steps\EventImport\Handlers\GoogleCalendar\GoogleCalendarUtils;

// Detect if value is a calendar URL
$is_url = GoogleCalendarUtils::is_calendar_url_like($value);

// Generate ICS URL from calendar ID
$ics_url = GoogleCalendarUtils::generate_ics_url_from_calendar_id($calendar_id);

// Resolve calendar URL from ID or URL
$resolved_url = GoogleCalendarUtils::resolve_calendar_url($config);
```

## Integration Architecture

### Handler Structure
- **GoogleCalendar.php**: Main import handler with event processing logic
- **GoogleCalendarAuth.php**: OAuth2 authentication and token management
- **GoogleCalendarUtils.php**: Calendar ID/URL utilities and ICS generation
- **GoogleCalendarFilters.php**: Handler registration and configuration
- **GoogleCalendarSettings.php**: Admin interface and configuration forms

### Data Flow
1. **Authentication**: OAuth2 flow validates access to Google Calendar
2. **Calendar Resolution**: GoogleCalendarUtils resolves calendar ID/URL
3. **Event Fetching**: API retrieves events from specified date range
4. **Event Processing**: Events mapped to Data Machine event structure
5. **Venue Handling**: Location data processed through venue taxonomy
6. **Event Upsert**: Events created/updated using EventUpsert handler

## Event Mapping

### Google Calendar → Event Details Block
- **Title** → Event title
- **Start/End Dates** → startDate/endDate + startTime/endTime
- **Description** → InnerBlocks content
- **Location** → venue + address
- **Extended Properties** → Custom event attributes

### Venue Processing
- **Location Parsing**: Intelligent extraction of venue names and addresses
- **Geocoding**: Automatic coordinate resolution
- **Venue Matching**: Duplicate prevention using EventIdentifierGenerator
- **Metadata Population**: Complete venue taxonomy meta fields

## API Integration

### Google Calendar API
- **Events List**: Primary endpoint for event retrieval
- **Calendar Info**: Calendar metadata and configuration
- **Freebusy**: Availability checking for conflict detection

### Rate Limiting
- **Quota Management**: Respects Google API rate limits
- **Batch Processing**: Efficient event retrieval
- **Error Recovery**: Automatic retry with exponential backoff

## Error Handling

### Authentication Errors
- **Token Expiration**: Automatic token refresh
- **Invalid Credentials**: Clear error messages with re-authentication prompts
- **Permission Denied**: User-friendly permission request guidance

### Data Errors
- **Invalid Calendar ID**: Clear validation with correction suggestions
- **Private Calendars**: Permission guidance for calendar sharing
- **Malformed Events**: Event skipping with detailed logging

## Performance Features

### Efficient Syncing
- **Incremental Updates**: Only processes changed events
- **Date Range Limiting**: Configurable import windows
- **Batch Processing**: Optimized API usage

### Caching
- **Event Caching**: Reduces API calls for unchanged events
- **Metadata Caching**: Calendar information caching
- **Token Caching**: Authentication token persistence

## Troubleshooting

### Common Issues
- **Authentication Failures**: Verify OAuth2 configuration and redirect URI
- **Calendar Not Found**: Confirm calendar ID and sharing permissions
- **Rate Limiting**: Implement appropriate delays between imports
- **Time Zone Issues**: Ensure WordPress timezone matches calendar timezone

### Debug Information
- **API Response Logging**: Detailed API interaction logs
- **Event Mapping Debug**: Field-by-field mapping information
- **Performance Metrics**: Import timing and API usage statistics

## Developer Integration

### Custom Event Processing
```php
// Extend Google Calendar handler
add_filter('datamachine_events_google_calendar_event_mapping', function($event_data, $google_event) {
    // Custom field mapping logic
    $event_data['custom_field'] = $google_event->getCustomProperty('custom');
    return $event_data;
}, 10, 2);
```

### Custom Authentication
```php
// Modify authentication flow
add_filter('datamachine_events_google_calendar_auth_config', function($config) {
    // Custom authentication configuration
    $config['custom_scope'] = 'https://www.googleapis.com/auth/calendar.readonly';
    return $config;
});
```

The Google Calendar integration provides comprehensive event import capabilities with robust authentication, intelligent event mapping, and seamless venue management integration.