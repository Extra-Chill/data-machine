# Event Identifier Generator

Shared utility for consistent event identifier normalization across all import handlers.

## Overview

The EventIdentifierGenerator provides standardized event identity generation and normalization for Data Machine Events. Ensures consistent duplicate detection and event matching across all import sources.

## Features

### Identity Normalization
- **Text Normalization**: Lowercase conversion, whitespace trimming, article removal
- **Consistent Hashing**: MD5-based identifier generation from normalized components
- **Cross-Handler Compatibility**: Works with all import handlers (Ticketmaster, DiceFm, IcsCalendar, WebScraper)
- **Duplicate Prevention**: Reliable event identity matching across imports

### Flexible Input Handling
- **Multiple Components**: Combines title, date, and venue for unique identification
- **Graceful Degradation**: Handles missing components with fallback logic
- **Unicode Support**: Proper handling of international characters and venues
- **Performance Optimized**: Efficient string processing for large datasets

## Usage

### Basic Event Identification
```php
use DataMachineEvents\Utilities\EventIdentifierGenerator;

// Generate normalized event identifier
$event_identifier = EventIdentifierGenerator::generate(
    'The Blue Note Jazz Club',
    '2025-12-31',
    'Blue Note'
);
// Returns: "a1b2c3d4e5f6..." (MD5 hash of normalized components)
```

### Import Handler Integration
```php
// In import handler (Ticketmaster, DiceFm, IcsCalendar, etc.)
foreach ($raw_events as $raw_event) {
    $standardized_event = $this->map_to_standard_format($raw_event);
    
    $event_identifier = EventIdentifierGenerator::generate(
        $standardized_event['title'],
        $standardized_event['startDate'],
        $standardized_event['venue']
    );
    
    // Check if already processed
    if (apply_filters('datamachine_is_item_processed', false, $flow_step_id, 'handler', $event_identifier)) {
        continue; // Skip already processed event
    }
    
    // Mark as processed and return
    do_action('datamachine_mark_item_processed', $flow_step_id, 'handler', $event_identifier, $job_id);
    array_unshift($data, $event_entry);
    return $data;
}
```

## Normalization Process

### Text Processing Steps
1. **Lowercase Conversion**: All text converted to lowercase
2. **Whitespace Trimming**: Remove leading/trailing whitespace
3. **Whitespace Collapsing**: Replace multiple spaces with single space
4. **Article Removal**: Remove common articles (a, an, the) from venue names
5. **Special Character Handling**: Preserve meaningful characters while normalizing

### Component Combination
```php
// Normalized components
$title = 'blue note jazz club';
$date = '2025-12-31';
$venue = 'blue note';

// Combined string for hashing
$combined_string = $title . '|' . $date . '|' . $venue;
// "blue note jazz club|2025-12-31|blue note"

// Final identifier
$identifier = md5($combined_string);
```

## Handler Integration

### Ticketmaster Handler
```php
// In Ticketmaster.php
$event_identifier = EventIdentifierGenerator::generate(
    $ticketmaster_event['name'],
    $ticketmaster_event['dates']['start']['localDate'],
    $ticketmaster_event['_embedded']['venues'][0]['name'] ?? ''
);
```

### Dice FM Handler
```php
// In DiceFm.php
$event_identifier = EventIdentifierGenerator::generate(
    $dice_event['title'],
    $dice_event['startDate'],
    $dice_event['venue']['name']
);
```

### ICS Calendar Handler
```php
// In IcsCalendar.php
$event_identifier = EventIdentifierGenerator::generate(
    $ical_event->summary,
    $ical_event->dtstart,
    $ical_event->location ?? ''
);
```

### Universal Web Scraper
```php
// In UniversalWebScraper.php (uses HTML hash instead)
// Web scraper uses HTML hash tracking via ProcessedItems
// EventIdentifierGenerator used for venue normalization only
$venue_identifier = EventIdentifierGenerator::generate('', '', $venue_name);
```

## Data Machine Integration

### Processed Items System
```php
// Check if event already processed
$is_processed = apply_filters('datamachine_is_item_processed', 
    false, 
    $flow_step_id, 
    'handler_name', 
    $event_identifier
);

if ($is_processed) {
    continue; // Skip duplicate event
}

// Mark event as processed
do_action('datamachine_mark_item_processed', 
    $flow_step_id, 
    'handler_name', 
    $event_identifier, 
    $job_id
);
```

### EventUpsert Integration
```php
// In EventUpsert.php - find existing events
$existing_post_id = $this->findExistingEvent($title, $venue, $startDate);

// Uses same normalization for consistent matching
$normalized_title = strtolower(trim($title));
$normalized_venue = strtolower(trim($venue));
$normalized_date = $startDate; // Date already normalized

// Search for existing event with same identity
$args = [
    'post_type' => 'datamachine_events',
    'meta_query' => [
        [
            'key' => '_datamachine_event_identifier',
            'value' => $event_identifier
        ]
    ]
];
```

## Examples

### Venue Name Variations
```php
// All these venue names normalize to the same identifier:
EventIdentifierGenerator::generate('Event', '2025-12-31', 'The Blue Note');
EventIdentifierGenerator::generate('Event', '2025-12-31', 'Blue Note');
EventIdentifierGenerator::generate('Event', '2025-12-31', '  Blue Note  ');
EventIdentifierGenerator::generate('Event', '2025-12-31', 'BLUE NOTE');

// All return the same MD5 hash
```

### Title Variations
```php
// Title variations with same meaning:
EventIdentifierGenerator::generate('Jazz Night at Blue Note', '2025-12-31', 'Blue Note');
EventIdentifierGenerator::generate('Jazz Night At Blue Note', '2025-12-31', 'Blue Note');
EventIdentifierGenerator::generate('  jazz night at blue note  ', '2025-12-31', 'Blue Note');
```

### Date Consistency
```php
// Date format normalization:
EventIdentifierGenerator::generate('Event', '2025-12-31', 'Venue');
EventIdentifierGenerator::generate('Event', '2025-12-31T19:00:00', 'Venue');
// Both use the date portion for consistency
```

## Performance Considerations

### Efficient Processing
- **MD5 Hashing**: Fast hash generation for large datasets
- **String Operations**: Optimized text processing functions
- **Memory Efficient**: Minimal memory footprint during processing
- **Batch Compatible**: Works efficiently with batch processing

### Caching Strategy
```php
// Optional caching for repeated normalization
static $normalization_cache = [];

$cache_key = md5($title . '|' . $startDate . '|' . $venue);
if (!isset($normalization_cache[$cache_key])) {
    $normalization_cache[$cache_key] = EventIdentifierGenerator::generate(
        $title, 
        $startDate, 
        $venue
    );
}

return $normalization_cache[$cache_key];
```

## Error Handling

### Graceful Degradation
```php
// Handle missing components
$event_identifier = EventIdentifierGenerator::generate(
    $title ?? 'Unknown Event',
    $startDate ?? date('Y-m-d'),
    $venue ?? 'Unknown Venue'
);
```

### Validation
```php
// Validate input before processing
if (empty($title) && empty($venue)) {
    throw new InvalidArgumentException('Event must have title or venue');
}

if (empty($startDate)) {
    throw new InvalidArgumentException('Event must have start date');
}
```

## Best Practices

### Consistent Usage
- **All Handlers**: Use EventIdentifierGenerator in all import handlers
- **Same Parameters**: Always pass title, startDate, venue in same order
- **Error Handling**: Handle missing or invalid input gracefully
- **Performance**: Cache results when processing large datasets

### Integration Testing
```php
// Test identity consistency across handlers
$test_cases = [
    ['The Charleston Music Hall', '2025-12-31', 'Charleston Music Hall'],
    ['Charleston Music Hall', '2025-12-31', 'The Charleston Music Hall'],
    ['  charleston music hall  ', '2025-12-31', 'CHARLESTON MUSIC HALL']
];

$identifiers = array_map(function($case) {
    return EventIdentifierGenerator::generate(...$case);
}, $test_cases);

// All identifiers should be identical
$this->assertEquals(count(array_unique($identifiers)), 1);
```

The EventIdentifierGenerator ensures reliable event identity management across the entire Data Machine Events ecosystem, preventing duplicates and enabling efficient event processing.