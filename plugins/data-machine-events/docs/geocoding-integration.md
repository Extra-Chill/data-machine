# Geocoding Integration

Automatic venue coordinate lookup using OpenStreetMap Nominatim API in the Data Machine Events plugin.

## Overview

The geocoding integration automatically converts venue addresses into GPS coordinates for map display and location-based features. This happens seamlessly in the background when venue information is saved or updated.

## How It Works

### Automatic Triggering

Geocoding is automatically triggered when:
- A new venue is created with address information
- An existing venue's address fields are updated
- Venue metadata is saved through the admin interface or import handlers

### Coordinate Storage

Coordinates are stored as latitude/longitude pairs in the venue taxonomy meta field `_venue_coordinates`:
```
40.7128,-74.0060
```

### API Integration

**Service:** OpenStreetMap Nominatim
**Endpoint:** `https://nominatim.openstreetmap.org/search`
**User Agent:** `ExtraChill-Events/1.0 (https://extrachill.com)`

**Request Parameters:**
- `format`: `json`
- `limit`: `1` (single best result)
- `q`: Combined address query (address + city + state + zip + country)

## Implementation Details

### Geocoding Process

```php
// From Venue_Taxonomy::maybe_geocode_venue()
public static function maybe_geocode_venue($term_id) {
    // Skip if coordinates already exist
    $existing_coords = get_term_meta($term_id, '_venue_coordinates', true);
    if (!empty($existing_coords)) {
        return false;
    }

    $venue_data = self::get_venue_data($term_id);
    $coordinates = self::geocode_address($venue_data);

    if ($coordinates) {
        update_term_meta($term_id, '_venue_coordinates', $coordinates);
        return true;
    }

    return false;
}
```

### Address Query Building

The system builds comprehensive address queries by combining available fields:

```php
$query_parts = [];
if (!empty($venue_data['address'])) $query_parts[] = $venue_data['address'];
if (!empty($venue_data['city'])) $query_parts[] = $venue_data['city'];
if (!empty($venue_data['state'])) $query_parts[] = $venue_data['state'];
if (!empty($venue_data['zip'])) $query_parts[] = $venue_data['zip'];
if (!empty($venue_data['country'])) $query_parts[] = $venue_data['country'];

$query = implode(', ', $query_parts);
```

### Error Handling

- Network failures are logged but don't prevent venue saving
- Invalid responses are gracefully handled
- Missing coordinates don't break venue functionality
- API rate limits are respected through proper user agent identification

## Integration Points

### Admin Interface

When editing venues in the WordPress admin:
1. Address fields are updated
2. `save_venue_metadata` hook triggers geocoding
3. Coordinates are stored automatically

### Import Handlers

During event imports:
1. Venue data is extracted from source
2. `Venue_Taxonomy::find_or_create_venue()` handles venue creation
3. Geocoding happens during venue term creation/update

### REST API

Venue coordinates are available through the REST API:
```
GET /wp-json/datamachine/v1/events/venues/{id}
```

Response includes coordinate data for map display.

## Map Display Integration

Coordinates power the venue map display in event details:

- **Leaflet.js Integration:** Coordinates enable marker placement
- **Multiple Tile Layers:** 5 free OpenStreetMap-based layers available
- **Custom Markers:** 📍 emoji markers for visual consistency
- **Responsive Maps:** Mobile-friendly map displays

## Performance Considerations

### Caching Strategy

- Coordinates are cached in venue meta to avoid repeated API calls
- Only geocodes when address components change
- No geocoding for venues without address information

### Rate Limiting

- Respects Nominatim's usage policies
- Proper user agent identification
- Reasonable timeout (10 seconds) for API requests

### Error Recovery

- Failed geocoding attempts don't prevent venue creation
- Manual coordinate entry possible if needed
- Logging for debugging API issues

## Usage in Templates

Coordinates are accessible in event templates:

```php
$venue_data = Venue_Taxonomy::get_venue_data($venue_term_id);
$coordinates = $venue_data['coordinates']; // "40.7128,-74.0060"

if ($coordinates) {
    list($lat, $lng) = explode(',', $coordinates);
    // Use for map integration
}
```

## Benefits

- **Automatic Location Data:** No manual coordinate entry required
- **Map-Ready Venues:** Immediate map display capability
- **SEO Enhancement:** Structured location data for search engines
- **Import Flexibility:** Works with any address format from import sources
- **Fallback Support:** Graceful degradation when geocoding fails

## Technical Notes

- **No API Keys Required:** Uses free OpenStreetMap Nominatim service
- **GDPR Compliant:** No user data sent to external services
- **WordPress Native:** Integrates with WordPress taxonomy system
- **Extensible:** Easy to modify geocoding logic or add alternative services</content>
<parameter name="filePath">docs/geocoding-integration.md