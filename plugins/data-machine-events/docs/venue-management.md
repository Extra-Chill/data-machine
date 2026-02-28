# Venue Management

Comprehensive venue taxonomy with 9 meta fields and admin interface for event locations.

## Overview

Data Machine Events provides a complete venue management system through WordPress custom taxonomy with extensive metadata fields. Venues can be created manually or automatically during event imports.

## Features

### Venue Taxonomy
- **WordPress Native**: Uses standard WordPress taxonomy system
- **Hierarchical Support**: Organize venues by location or type
- **Admin Interface**: Complete CRUD operations in WordPress admin
- **REST API Integration**: Venue data available via endpoints

### Meta Fields
Venues include 10 comprehensive metadata fields:
- **address**: Street address for venue location
- **city**: Venue city or locality
- **state**: State or province
- **zip**: Postal code or ZIP code
- **country**: Country name or code
- **phone**: Venue phone number
- **website**: Venue website URL
- **capacity**: Maximum venue capacity
- **coordinates**: GPS coordinates (latitude, longitude)
- **venueTimezone**: IANA timezone identifier (e.g., "America/New_York") (@since v0.8.14)

### Timezone Detection

Venues support automatic timezone detection through multiple mechanisms:

- **Source Data**: Import handlers (Ticketmaster, Eventbrite, DiceFm, ICS calendars, web scrapers) extract and store timezone data when available from the source.
- **GeoNames Fallback**: When a venue is created or updated with valid coordinates but no timezone, the `GeoNamesService` automatically fetches the correct IANA timezone identifier. Requires `geonames_username` configured in Event Settings.
- **AI Chat Tools**: Use `venue_health_check` to identify venues missing timezone data, `update_venue` to fix them, and `get_venue_events` to retrieve upcoming events for a specific venue. The update triggers automatic timezone derivation via GeoNames when coordinates are available.
- **Calendar Integration**: `Calendar_Query` and `DateTimeParser` respect venue-specific timezones for accurate date grouping and display.

### Admin Interface
- **Venue Management**: Full admin interface for venue operations
- **Bulk Operations**: Create, edit, and delete venues
- **Search Functionality**: Find venues by name or location
- **Meta Field Forms**: User-friendly forms for all metadata

### Integration Features

### Geocoding Integration
- **Automatic Coordinate Lookup**: Uses OpenStreetMap Nominatim API for venue coordinates
- **Address-Based Geocoding**: Triggers when venue address fields are populated
- **Coordinate Storage**: Stores latitude/longitude in venue meta for map display
- **Proper User Agent**: Uses appropriate user agent for API requests

### Event Assignment
- **Automatic Assignment**: Venues automatically linked to events
- **Duplicate Detection**: Prevents duplicate venue creation
- **Find-or-Create**: Intelligent venue matching during imports
- **Venue Service**: Centralized venue operations API

### Map Integration
- **5 Free Tile Layers**: No API keys required
- **Leaflet.js Integration**: Interactive venue maps
- **Custom Markers**: Consistent venue location indicators
- **Responsive Design**: Mobile-friendly map display

## Usage

### Manual Venue Creation
1. **Navigate**: Events → Venues in WordPress admin
2. **Add New Venue**: Fill in venue name and description
3. **Complete Meta Fields**: Add address, contact, and capacity information
4. **Save**: Venue created and available for event assignment

### Automatic Venue Creation
- **Import Handlers**: Ticketmaster, Dice FM, Google Calendar create venues
- **AI-Powered**: Universal Web Scraper extracts venue information
- **Data Normalization**: Consistent venue data across sources
- **Duplicate Prevention**: EventIdentifierGenerator prevents duplicate venues

### Venue Assignment to Events
- **Event Details Block**: Select venue from dropdown or create new
- **Import Processing**: Venues automatically assigned during event import
- **Manual Selection**: Choose existing venues for manual events

## Map Display Types

### Available Tile Layers
1. **OpenStreetMap Standard**: Default open-source mapping
2. **CartoDB Positron**: Clean, minimal design
3. **CartoDB Voyager**: Detailed street-level mapping
4. **CartoDB Dark Matter**: Dark theme mapping
5. **Humanitarian OpenStreetMap**: Emergency response mapping

### Configuration
- **Settings Page**: Events → Settings → Map Display Type
- **Global Setting**: Site-wide map tile selection
- **Per-Event Basis**: Maps respect user display preferences

## REST API Endpoints

### Venue Operations
- **GET /venues/{id}**: Retrieve venue data and metadata
- **POST /venues**: Create new venue with metadata
- **PUT /venues/{id}**: Update existing venue
- **DELETE /venues/{id}**: Delete venue

### Duplicate Checking
- **GET /venues/check-duplicate**: Check for existing venues
- **Parameters**: venue name and address for matching
- **Response**: Boolean duplicate status and existing venue ID

## Developer Integration

### Venue Data Access
```php
// Get complete venue data
$venue_data = Venue_Taxonomy::get_venue_data($term_id);

// Get formatted address
$address = Venue_Taxonomy::get_formatted_address($term_id);

// Find or create venue (Venue_Taxonomy method)
$venue_result = Venue_Taxonomy::find_or_create_venue($venue_name, $venue_metadata);

// Normalize venue data (VenueService method)
$normalized_data = VenueService::normalize_venue_data($raw_data);

// Get or create venue with normalized data (VenueService method)
$venue_id = VenueService::get_or_create_venue($normalized_data);
```

### Custom Meta Fields
```php
// Add custom venue meta field
add_action('data_machine_events_venue_meta_fields', function($fields) {
    $fields['custom_field'] = [
        'label' => 'Custom Field',
        'type' => 'text',
        'description' => 'Custom venue metadata'
    ];
    return $fields;
});
```

### Map Customization
```php
// Custom map tile layer
add_filter('data_machine_events_map_tile_layers', function($layers) {
    $layers['custom_layer'] = [
        'name' => 'Custom Tiles',
        'url' => 'https://example.com/tiles/{z}/{x}/{y}.png',
        'attribution' => '© Custom Maps'
    ];
    return $layers;
});
```

## Performance Features

### Efficient Queries
- **Indexed Fields**: Optimized database queries
- **Caching**: Venue data caching for performance
- **Bulk Operations**: Efficient venue management
- **REST Optimization**: Fast API response times

### SEO Benefits
- **Structured Data**: Venue information in event schema
- **Local SEO**: Enhanced local search visibility
- **Address Markup**: Proper address formatting
- **Contact Information**: Complete venue contact details

The venue management system provides comprehensive location data management with automatic creation during imports and flexible manual administration.