# Promoter_Taxonomy

Promoter taxonomy registration and management for events with metadata support. Maps to Schema.org "organizer" property for structured data output.

## Overview

The `Promoter_Taxonomy` class provides a hierarchical taxonomy for event promoters/organizers. Unlike the venue taxonomy, promoters use simple name-based matching without geocoding complexity. The taxonomy supports metadata fields for website URLs and organization/person type classification.

## Location

`inc/Core/Promoter_Taxonomy.php`

## Key Features

### Taxonomy Registration

- Registers `promoter` taxonomy for `data_machine_events` post type
- Non-hierarchical (tag-like) taxonomy
- REST API enabled
- Admin interface with custom metadata fields

### Metadata Fields

- `url` (`_promoter_url`): Promoter website URL
- `type` (`_promoter_type`): Schema.org type (Organization/Person)

### Type Options

- `Organization`: For companies, venues, or formal organizations
- `Person`: For individual promoters or organizers

## Key Methods

### `register(): void`

Main registration method that sets up the taxonomy and admin hooks.

### `find_or_create_promoter(string $promoter_name, array $promoter_data): array`

Finds existing promoter by name or creates a new one. Returns array with `term_id` and `was_created` boolean.

**Parameters:**
- `$promoter_name`: Promoter name (required)
- `$promoter_data`: Optional metadata array with `url`, `type`, `description`

**Returns:** `['term_id' => int|null, 'was_created' => bool]`

### `get_promoter_data(int $term_id): array`

Retrieves complete promoter data including all metadata fields.

### `update_promoter_meta(int $term_id, array $promoter_data): bool`

Updates promoter term metadata fields.

### `get_all_promoters(): array`

Returns array of all promoter data for administrative purposes.

### `get_promoter_options(): array`

Returns term_id => name array for select dropdowns.

## Admin Interface

### Add Promoter Form

Provides fields for:
- Website URL input
- Type selection (Organization/Person)

### Edit Promoter Form

Allows editing of:
- Website URL
- Type selection
- Term description

## Integration Points

- **EventUpsert**: Used during event creation/update for promoter assignment
- **Schema.org**: Maps to `organizer` property in structured data
- **REST API**: Available through taxonomy endpoints
- **Event Details Block**: Displays promoter information

## Usage Examples

### Creating a Promoter

```php
$result = Promoter_Taxonomy::find_or_create_promoter('Local Music Venue', [
    'url' => 'https://localmusicvenue.com',
    'type' => 'Organization',
    'description' => 'Independent music venue promoting local artists'
]);

if ($result['term_id']) {
    // Promoter created or found successfully
    $term_id = $result['term_id'];
}
```

### Getting Promoter Data

```php
$promoter_data = Promoter_Taxonomy::get_promoter_data($term_id);
// Returns: ['name', 'term_id', 'slug', 'description', 'url', 'type']
```

### Getting Options for Dropdown

```php
$options = Promoter_Taxonomy::get_promoter_options();
// Returns: [123 => 'Venue Name', 124 => 'Promoter Name', ...]
```

## Schema.org Mapping

The promoter taxonomy maps to Schema.org Event properties:

```json
{
  "@type": "Event",
  "organizer": {
    "@type": "Organization",
    "name": "Promoter Name",
    "url": "https://promoter-website.com"
  }
}
```

## Taxonomy Configuration

```php
[
    'hierarchical' => false,
    'labels' => [...], // Internationalized labels
    'show_ui' => true,
    'show_in_menu' => true,
    'show_admin_column' => true,
    'query_var' => true,
    'rewrite' => ['slug' => 'promoter'],
    'show_in_rest' => true
]
```