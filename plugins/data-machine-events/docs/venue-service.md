# VenueService

Centralized service for handling venue logic: normalization, finding existing venues, and creating new venue terms. Used by Import Handlers (for normalization) and Publish Handlers (for term creation).

## Overview

The `VenueService` class provides a centralized interface for venue management operations. It handles data normalization, duplicate detection, and term creation, ensuring consistent venue data across the import and publishing workflows.

## Location

`inc/Core/VenueService.php`

## Key Features

### Data Normalization

Standardizes venue data from various import sources into a consistent format.

### Duplicate Prevention

Checks for existing venues by name before creating new terms.

### Metadata Management

Handles all venue metadata fields including address, coordinates, and contact information.

## Key Methods

### `normalize_venue_data(array $raw_data): array`

Normalizes raw venue data from import sources into a standardized format.

**Parameters:**
- `$raw_data`: Raw venue data array with keys like `name`, `address`, `city`, etc.

**Returns:** Normalized venue data array

**Fields normalized:**
- `name`: Sanitized text field
- All metadata fields from `Venue_Taxonomy::$meta_fields`

### `get_or_create_venue(array $venue_data): int|WP_Error`

Finds existing venue by name or creates a new venue term.

**Parameters:**
- `$venue_data`: Normalized venue data array

**Returns:** Term ID on success, WP_Error on failure

**Process:**
1. Validates venue name is not empty
2. Checks for existing venue by exact name match
3. Creates new venue term if not found
4. Saves venue metadata
5. Returns term ID

### `save_venue_meta(int $term_id, array $data): void`

Saves venue metadata to term meta fields.

**Parameters:**
- `$term_id`: Venue term ID
- `$data`: Venue data array

## Integration Points

- **Import Handlers**: Used during data import to normalize venue information
- **EventUpsert**: Called during event publishing to ensure venue terms exist
- **Venue_Taxonomy**: Works with venue taxonomy for term management
- **VenueParameterProvider**: Integrates with parameter extraction logic

## Usage Examples

### Normalizing Import Data

```php
$raw_venue = [
    'name' => 'The Venue Name',
    'address' => '123 Main St',
    'city' => 'Anytown',
    'website' => 'https://venue.com'
];

$normalized = VenueService::normalize_venue_data($raw_venue);
// Result: Sanitized and standardized venue data
```

### Creating Venue During Import

```php
$venue_data = [
    'name' => 'Music Hall',
    'address' => '456 Oak Ave',
    'city' => 'Springfield',
    'phone' => '(555) 123-4567'
];

$term_id = VenueService::get_or_create_venue($venue_data);

if (!is_wp_error($term_id)) {
    // Venue created or found successfully
    // $term_id contains the venue term ID
}
```

## Data Flow

1. **Import Phase**: Raw venue data from external sources
2. **Normalization**: `normalize_venue_data()` standardizes the format
3. **Deduplication**: `get_or_create_venue()` checks for existing venues
4. **Term Creation**: New venue terms created with metadata
5. **Publishing**: Venue terms linked to events during upsert

## Error Handling

- Returns `WP_Error` for missing venue names
- Returns `WP_Error` for term creation failures
- Logs errors for failed venue operations

## Metadata Fields Handled

The service manages all venue metadata fields defined in `Venue_Taxonomy::$meta_fields`:

- `address`: Street address
- `city`: City name
- `state`: State/province
- `zip`: Postal code
- `country`: Country name
- `phone`: Phone number
- `website`: Website URL
- `coordinates`: Geographic coordinates
- `capacity`: Venue capacity