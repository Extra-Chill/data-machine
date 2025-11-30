# REST API

Comprehensive REST API for event and venue data with progressive enhancement support.

## Overview

Data Machine Events provides a complete REST API system under the unified `datamachine/v1` namespace. Features modular controller architecture with SQL-based filtering and progressive enhancement for optimal performance.

## Architecture

### Unified Namespace
- **Base URL**: `/wp-json/datamachine/v1/`
- **Route Registration**: Centralized in `inc/Api/Routes.php`
- **Modular Controllers**: Separate controllers for each resource type
- **Progressive Enhancement**: Works with and without JavaScript

### Controller Structure
- **Calendar Controller**: Event filtering and display
- **Venues Controller**: Venue CRUD operations
- **Events Controller**: General event operations

## Endpoints

### Calendar Endpoint
**URL**: `GET /wp-json/datamachine/v1/events/calendar`

Public endpoint for event filtering and display with progressive enhancement.

#### Query Parameters
- `event_search`: Search events by title, venue, or taxonomy terms
- `date_start`: Filter events from start date (YYYY-MM-DD format)
- `date_end`: Filter events to end date (YYYY-MM-DD format)
- `tax_filter[taxonomy][]`: Filter by taxonomy term IDs (multiple values supported)
- `paged`: Current page number for pagination
- `past`: Show past events when set to "1"

#### Response Format
```json
{
  "success": true,
  "html": "Rendered events HTML",
  "pagination": "Pagination controls HTML",
  "navigation": "Calendar navigation HTML",
  "counter": "Results counter HTML"
}
```

### Venues Endpoint
**URL**: `GET /wp-json/datamachine/v1/events/venues/{id}`

Admin endpoint for venue data retrieval and management.

#### Path Parameters
- `id`: Venue taxonomy term ID

#### Response Format
```json
{
  "success": true,
  "venue": {
    "id": 123,
    "name": "Venue Name",
    "description": "Venue description",
    "meta": {
      "address": "123 Main St",
      "city": "Charleston",
      "state": "SC",
      "zip": "29401",
      "country": "US",
      "phone": "(555) 123-4567",
      "website": "https://venue.com",
      "capacity": 500,
      "coordinates": "32.7836,-79.9372"
    }
  }
}
```

### Duplicate Check Endpoint
**URL**: `GET /wp-json/datamachine/v1/events/venues/check-duplicate`

Admin endpoint for checking duplicate venues before creation.

#### Query Parameters
- `name`: Venue name to check
- `address`: Venue address to check

#### Response Format
```json
{
  "success": true,
  "is_duplicate": false,
  "existing_venue_id": null,
  "message": "No duplicate venue found"
}
```

### Filters Endpoint
**URL**: `GET /wp-json/datamachine/v1/events/filters`

Public endpoint for dynamic taxonomy filter options with active filter support.

#### Query Parameters
- `active_filters[taxonomy][]`: Currently active taxonomy term IDs
- `date_start`: Start date for date-aware filtering (YYYY-MM-DD)
- `date_end`: End date for date-aware filtering (YYYY-MM-DD)
- `past`: Show past events when "1"

#### Response Format
```json
{
  "success": true,
  "filters": {
    "taxonomy_slug": {
      "name": "Taxonomy Name",
      "terms": [
        {
          "id": 123,
          "name": "Term Name",
          "count": 5,
          "parent": 0
        }
      ]
    }
  }
}
```

### Geocoding Endpoint
**URL**: `GET /wp-json/datamachine/v1/events/geocode/search`

Admin endpoint for address geocoding using OpenStreetMap Nominatim API.

#### Query Parameters
- `q`: Address query string to geocode

#### Response Format
```json
{
  "success": true,
  "results": [
    {
      "place_id": 123456,
      "display_name": "123 Main St, City, State 12345, USA",
      "lat": "32.7836",
      "lon": "-79.9372",
      "address": {
        "house_number": "123",
        "road": "Main St",
        "city": "City",
        "state": "State",
        "postcode": "12345",
        "country": "USA"
      }
    }
  ]
}
```

### Events Endpoint
**URL**: `GET /wp-json/datamachine/v1/events/events`

General event operations endpoint.

#### Response Format
```json
{
  "success": true,
  "events": [
    {
      "id": 456,
      "title": "Event Title",
      "content": "Event content",
      "meta": {
        "event_datetime": "2025-12-31 19:00:00"
      }
    }
  ]
}
```

## Features

### Progressive Enhancement
- **Server-First**: Full functionality without JavaScript
- **JavaScript Enhanced**: Seamless filtering without page reloads
- **History API**: Shareable filter states via URL parameters
- **Debounced Search**: 500ms delay for performance optimization

### Performance Optimization
- **SQL-Based Filtering**: Database-level filtering before rendering
- **Efficient Pagination**: ~10 events per page vs. loading all events
- **Meta Queries**: Optimized date filtering using `_datamachine_event_datetime` meta field
- **Scalable Architecture**: Handles large event datasets (500+ events)

### Security
- **Capability Checks**: Admin endpoints require proper permissions
- **Input Sanitization**: All parameters properly sanitized
- **Nonce Verification**: WordPress nonce protection on sensitive operations

### Error Handling
- **Standardized Responses**: Consistent JSON response format
- **HTTP Status Codes**: Proper REST status code usage
- **Error Messages**: User-friendly error descriptions
- **Graceful Degradation**: Server-side rendering when JavaScript fails

## Usage Examples

### Calendar Filtering
```javascript
// JavaScript-enhanced filtering
fetch('/wp-json/datamachine/v1/events/calendar?' + new URLSearchParams({
    event_search: 'jazz',
    date_start: '2025-01-01',
    date_end: '2025-12-31',
    'tax_filter[festival][]': '5',
    paged: '2'
}))
.then(response => response.json())
.then(data => {
    // Update calendar HTML
    document.querySelector('.datamachine-calendar').innerHTML = data.html;
});
```

### Venue Management
```javascript
// Check for duplicate venue
fetch('/wp-json/datamachine/v1/events/venues/check-duplicate?' + new URLSearchParams({
    name: 'Charleston Music Hall',
    address: '37 John St, Charleston, SC'
}))
.then(response => response.json())
.then(data => {
    if (data.is_duplicate) {
        // Show existing venue option
        console.log('Existing venue ID:', data.existing_venue_id);
    }
});
```

### Server-Side Rendering
```php
// Direct PHP usage (no JavaScript required)
$request = new WP_REST_Request('GET', '/datamachine/v1/events/calendar');
$controller = new Calendar();
$result = $controller->calendar($request);
echo $result['html']; // Rendered calendar HTML
```

## Integration

### WordPress REST API
- **Native Integration**: Built on WordPress REST API framework
- **Standard Endpoints**: Follows WordPress REST conventions
- **Authentication**: Uses WordPress authentication system

### Block Integration
- **Calendar Block**: Uses REST API for progressive enhancement
- **Event Details Block**: Event data available via REST endpoints
- **Admin Interface**: Venue management via REST calls

### Theme Integration
- **Template Compatibility**: Works with any WordPress theme
- **SEO Friendly**: Server-rendered content for search engines
- **Accessible**: WCAG compliant markup and navigation

The REST API provides comprehensive data access with optimal performance and full WordPress integration.