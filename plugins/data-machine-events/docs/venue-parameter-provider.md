# VenueParameterProvider

Dynamic venue parameter generation for AI tool definitions in the Data Machine Events plugin.

## Overview

The `VenueParameterProvider` class manages venue-related parameters for AI tools, providing a clean separation between venue data handling and event data processing. It works alongside `Venue_Taxonomy` (storage) and `VenueService` (operations) to provide venue parameter definitions when AI should determine venue information.

## Venue Parameters

The provider defines the following venue parameters that can be handled by AI tools:

- `venue` - Venue name
- `venueAddress` - Street address
- `venueCity` - City location
- `venueState` - State/province
- `venueZip` - Postal/zip code
- `venueCountry` - Country
- `venuePhone` - Phone number
- `venueWebsite` - Website URL
- `venueCoordinates` - GPS coordinates (latitude,longitude format)
- `venueCapacity` - Maximum venue capacity

## Usage Examples

### Get Tool Parameters

```php
use DataMachineEvents\Core\VenueParameterProvider;

// Get all venue parameters for AI tools (when no static venue configured)
$venue_params = VenueParameterProvider::getToolParameters($handler_config);

// Returns empty array if static venue is configured
// Returns full parameter array if AI should determine venue
```

### Check Static Venue Configuration

```php
// Check if handler has static venue configured
$has_static = VenueParameterProvider::hasStaticVenue($handler_config);

// Returns true if:
// - Universal Web Scraper has venue configured
// - Venue is a numeric term ID
```

### Extract Venue Data from Parameters

```php
// Extract venue data from AI tool parameters
$venue_data = VenueParameterProvider::extractFromParameters($parameters);

// Returns array keyed by Venue_Taxonomy meta field names:
// [
//     'name' => 'The Blue Note',
//     'address' => '123 Main St',
//     'city' => 'New York',
//     'state' => 'NY',
//     'zip' => '10001',
//     // ...
// ]
```

### Extract from Event Data

```php
// Extract venue metadata from event data array
$venue_metadata = VenueParameterProvider::extractFromEventData($event_data);

// Strip venue fields from event data (modifies array in place)
VenueParameterProvider::stripFromEventData($event_data);
```

## Parameter Mapping

The provider maintains a mapping between tool parameter names and Venue_Taxonomy meta field keys:

```php
private const PARAMETER_TO_META_MAP = [
    'venue' => 'name',
    'venueAddress' => 'address',
    'venueCity' => 'city',
    'venueState' => 'state',
    'venueZip' => 'zip',
    'venueCountry' => 'country',
    'venuePhone' => 'phone',
    'venueWebsite' => 'website',
    'venueCoordinates' => 'coordinates',
    'venueCapacity' => 'capacity'
];
```

## Integration with EventSchemaProvider

Works seamlessly with `EventSchemaProvider` for parameter routing:

```php
// Smart parameter routing between engine data and AI tools
$routing = EventSchemaProvider::engineOrTool($parameters, $handler_config, $engine_data);

// Venue parameters are handled separately from event parameters
$venue_params = VenueParameterProvider::getToolParameters($handler_config);
```

## Architecture Benefits

- **Clean Separation**: Venue parameters handled independently from event parameters
- **Flexible Configuration**: Supports both static venue configuration and AI-determined venues
- **Consistent Mapping**: Single source of truth for venue parameter definitions
- **Easy Integration**: Works with existing Venue_Taxonomy and VenueService classes
- **Maintainable**: Centralized venue parameter logic</content>
<parameter name="filePath">docs/venue-parameter-provider.md