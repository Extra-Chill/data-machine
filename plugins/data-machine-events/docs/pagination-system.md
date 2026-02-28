# Pagination System

Day-based pagination system for Calendar block ensuring complete day groups are never split across pages.

## Overview

The Pagination system provides day-based pagination for the Calendar block. Instead of paginating by event count (which can split a single day's events across pages), pagination is based on complete calendar days. Each page displays 5 complete days worth of events, regardless of how many events occur on those days.

## Features

### Day-Based Pagination
- **Complete Day Groups**: Events are never split across pages - each day appears in full
- **5 Days Per Page**: Configurable via `DAYS_PER_PAGE` constant in `Calendar_Query.php`
- **Dynamic Event Counts**: Pages may contain varying numbers of events (5 events on a slow week, 250 on a busy week)
- **User-Friendly Browsing**: Users see natural calendar day boundaries

### SQL-Based Optimization
- **Date Boundary Queries**: Efficient queries using date ranges for each page
- **Indexed Meta Fields**: Uses `_datamachine_event_datetime` meta field for fast filtering
- **Minimal Memory Usage**: Only loads events for the current page's date range

### Progressive Enhancement
- **Server-First**: Full pagination functionality without JavaScript
- **JavaScript Enhanced**: Seamless page transitions without page reloads
- **History API**: Browser back/forward button support
- **URL Preservation**: Shareable pagination states via URL parameters

## Architecture

### Core Components

**`Calendar_Query.php`** - Single source of truth for pagination logic:
- `DAYS_PER_PAGE` constant (default: 5)
- `get_unique_event_dates()` - Returns ordered array of unique event dates
- `get_date_boundaries_for_page()` - Calculates start/end dates for a given page

**`Pagination.php`** - Renders pagination controls with extensibility filters

### Algorithm

```
Given: page=2, DAYS_PER_PAGE=5

1. Query all unique event start dates (respecting filters)
2. total_days = count(unique_dates)  // e.g., 23 days
3. max_pages = ceil(total_days / 5)  // e.g., 5 pages
4. For page 2: start_index = (2-1) * 5 = 5
5. end_index = min(5 + 5 - 1, 23 - 1) = 9
6. start_date = unique_dates[5]  // 6th day
7. end_date = unique_dates[9]    // 10th day
8. Query all events WHERE start_date BETWEEN start_date AND end_date
9. Return all events (no posts_per_page limit)
```

## Usage

### Calendar Query Integration
```php
use DataMachineEvents\Blocks\Calendar\Calendar_Query;
use const DataMachineEvents\Blocks\Calendar\DAYS_PER_PAGE;

// Build base query parameters
$base_params = [
    'show_past' => false,
    'search_query' => '',
    'tax_filters' => [],
];

// Get unique event dates
$unique_dates = Calendar_Query::get_unique_event_dates($base_params);

// Get date boundaries for current page
$date_boundaries = Calendar_Query::get_date_boundaries_for_page($unique_dates, $current_page);

// Calculate max pages
$max_pages = $date_boundaries['max_pages'];

// Build final query with date boundaries
$query_params = $base_params;
$query_params['date_start'] = $date_boundaries['start_date'];
$query_params['date_end'] = $date_boundaries['end_date'];

$query_args = Calendar_Query::build_query_args($query_params);
$events_query = new WP_Query($query_args);
```

### Pagination Rendering
```php
use DataMachineEvents\Blocks\Calendar\Pagination;

// Render pagination controls
echo Pagination::render_pagination($current_page, $max_pages, $show_past);
```

## REST API Integration

### Pagination Parameters
```javascript
// JavaScript pagination request
fetch('/wp-json/datamachine/v1/events/calendar?' + new URLSearchParams({
    paged: 2,
    event_search: searchValue,
    date_start: startDate,
    date_end: endDate,
    'tax_filter[venue][]': selectedVenues
}))
.then(response => response.json())
.then(data => {
    // Update calendar content
    document.querySelector('.data-machine-events-content').innerHTML = data.html;
    
    // Update pagination
    document.querySelector('.data-machine-events-pagination').outerHTML = data.pagination.html;
    
    // Update results counter
    document.querySelector('.data-machine-events-results-counter').outerHTML = data.counter;
});
```

### Server Response
```json
{
    "success": true,
    "html": "Rendered events HTML for 5 days",
    "pagination": {
        "html": "Pagination controls HTML",
        "current_page": 2,
        "max_pages": 5,
        "total_events": 23
    },
    "counter": "Results counter HTML",
    "navigation": {
        "html": "Past/upcoming navigation HTML",
        "past_count": 150,
        "future_count": 87
    }
}
```

## Customization

### Pagination Filters
```php
// Customize pagination arguments
add_filter('data_machine_events_pagination_args', function($args, $current_page, $max_pages, $show_past) {
    // Custom prev/next text
    $args['prev_text'] = '← Previous 5 Days';
    $args['next_text'] = 'Next 5 Days →';
    
    return $args;
}, 10, 4);

// Customize pagination wrapper classes
add_filter('data_machine_events_pagination_wrapper_classes', function($classes, $current_page, $max_pages, $show_past) {
    $classes[] = 'custom-pagination-class';
    return $classes;
}, 10, 4);
```

### Query Customization
```php
// Modify calendar query arguments
add_filter('data_machine_events_calendar_query_args', function($args, $params) {
    // Add custom meta query conditions
    return $args;
}, 10, 2);
```

## Edge Cases

| Scenario | Behavior |
|----------|----------|
| Fewer than 5 days of events | Shows all events, pagination hidden (`max_pages = 1`) |
| No events | Shows "no events" template, pagination hidden (`max_pages = 0`) |
| Exactly 5 days | Shows all events, pagination hidden (`max_pages = 1`) |
| Invalid page number | Clamped to valid range (1 to max_pages) |
| User-provided date filter | Pagination operates within user's date range |
| Past events mode | Same logic, ordered DESC by date |

## Accessibility

### ARIA Support
```html
<nav class="data-machine-events-pagination" aria-label="Events pagination">
    <ul class="page-numbers">
        <li><a class="prev page-numbers" href="?paged=1">« Previous</a></li>
        <li><span aria-current="page" class="page-numbers current">2</span></li>
        <li><a class="next page-numbers" href="?paged=3">Next »</a></li>
    </ul>
</nav>
```

### Keyboard Navigation
- Standard link navigation with Tab key
- Enter/Space to activate pagination links
- Browser back/forward for history navigation

## Performance

### Why Day-Based Pagination?
- **User Experience**: Natural calendar browsing without split days
- **Predictable Navigation**: "Next 5 days" is more intuitive than "next 10 events"
- **Scalable**: Works consistently whether a day has 2 events or 50 events

### Query Optimization
- Single query for unique dates (IDs only, minimal memory)
- Single query for page events using date boundaries
- No post-processing to group events (already within date range)

## Troubleshooting

### Common Issues
- **Pagination Not Appearing**: Verify more than 5 unique event days exist
- **Wrong Page Count**: Check `DAYS_PER_PAGE` constant value
- **Events Missing**: Verify `_datamachine_event_datetime` meta field is populated

### Debug Information
```php
// Debug pagination data
$unique_dates = Calendar_Query::get_unique_event_dates($base_params);
error_log('Total unique days: ' . count($unique_dates));
error_log('First date: ' . ($unique_dates[0] ?? 'none'));
error_log('Last date: ' . ($unique_dates[count($unique_dates)-1] ?? 'none'));

$boundaries = Calendar_Query::get_date_boundaries_for_page($unique_dates, $current_page);
error_log('Page ' . $current_page . ' boundaries: ' . $boundaries['start_date'] . ' to ' . $boundaries['end_date']);
error_log('Max pages: ' . $boundaries['max_pages']);
```

The Pagination system ensures complete day groups are always displayed together, providing a natural calendar browsing experience for high-volume event calendars.
