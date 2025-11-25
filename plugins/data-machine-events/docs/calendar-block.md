# Calendar Block

Advanced event display with multiple view modes, filtering, and template system.

## Overview

The Calendar block provides flexible event display with progressive enhancement, supporting both server-side rendering and JavaScript-enhanced filtering. Features multiple display styles and comprehensive taxonomy integration.

## Features

### Display Modes
- **Circuit Grid**: Visual grid layout with day badges and borders
- **Carousel List**: Sequential list with time gap separators
- **Standard List**: Traditional chronological event listing

### Filtering System
- **Search**: Real-time event search by title, venue, or taxonomy terms
- **Date Range**: Flexible date filtering with start/end date pickers
- **Taxonomy Filters**: Multi-taxonomy filtering with modal interface
- **Past Events**: Toggle between upcoming and past events

### Template System
- **7 Specialized Templates**: Modular, cacheable template rendering
- **Variable Extraction**: Automatic data sanitization and extraction
- **Output Buffering**: Efficient template processing

### Progressive Enhancement
- **Server-First**: Works without JavaScript for SEO and accessibility
- **JavaScript Enhanced**: Seamless filtering without page reloads
- **History API**: Shareable filter states via URL

## Display Modes

### Circuit Grid
Visual calendar-style grid with enhanced features:
- **Day Badges**: Color-coded date indicators
- **Border System**: SVG-based grid lines and connectors
- **Responsive Design**: Mobile, tablet, and desktop layouts
- **Color Manager**: Centralized color theming system

### Carousel List
Sequential event display optimized for browsing:
- **Time Gaps**: Visual separators between date groups
- **Smooth Scrolling**: Continuous event browsing
- **Compact Layout**: Space-efficient event presentation

### Standard List
Traditional chronological display:
- **Simple Layout**: Clean, readable event listing
- **Performance**: Fast rendering for large event sets
- **Accessibility**: Screen reader and keyboard navigation

## Filtering Features

### Search Functionality
- **Real-time Search**: 500ms debounced input
- **Multi-field Search**: Title, venue, and taxonomy terms
- **URL Preservation**: Search terms maintained in shareable URLs

### Date Filtering
- **Date Range Picker**: Flatpickr integration
- **Quick Presets**: Today, This Week, This Month
- **Timezone Aware**: Uses WordPress timezone settings

### Taxonomy Filtering
- **Modal Interface**: Advanced filter selection
- **Hierarchical Support**: Category and sub-category filtering
- **Multi-select**: Multiple taxonomy terms per filter
- **Active State**: Visual filter count indicators

## Template Architecture

### Available Templates
- **event-item.php**: Individual event display
- **date-group.php**: Day-grouped event container
- **pagination.php**: Event navigation controls
- **navigation.php**: Calendar navigation controls
- **no-events.php**: Empty state display
- **filter-bar.php**: Filtering interface
- **time-gap-separator.php**: Date gap visualization
- **modal/taxonomy-filter.php**: Advanced taxonomy filter modal

### Template Features
- **Variable Sanitization**: Automatic data cleaning and escaping
- **Output Buffering**: Efficient template rendering
- **Caching Support**: Performance optimization
- **Modular Design**: Easy template customization
- **More Info Button**: Direct link to event details with customizable styling

### Taxonomy Integration
- **Taxonomy_Badges**: Dynamic badge rendering with automatic color generation and taxonomy term links
- **Taxonomy_Helper**: Structured taxonomy data processing with hierarchy building and post count calculations

## REST API Integration

### Calendar Endpoint
- **URL**: `/wp-json/datamachine/v1/events/calendar`
- **Method**: GET request with query parameters
- **Response**: Rendered HTML with pagination data

### Query Parameters
- `event_search`: Search events by title, venue, or terms
- `date_start`: Filter events from start date (YYYY-MM-DD)
- `date_end`: Filter events to end date (YYYY-MM-DD)
- `tax_filter[taxonomy][]`: Filter by taxonomy term IDs
- `paged`: Pagination page number
- `past`: Show past events when "1"

### Response Data
```json
{
  "success": true,
  "html": "Rendered events HTML",
  "pagination": "Pagination controls HTML",
  "navigation": "Calendar navigation HTML", 
  "counter": "Results counter HTML"
}
```

## Performance Features

### SQL-Based Filtering
- **Database-Level**: Filter events at SQL query level
- **Meta Queries**: Efficient date-based filtering
- **Taxonomy Queries**: Optimized taxonomy filtering
- **Pagination**: Server-side pagination (~10 events per page)

### Progressive Enhancement
- **No JavaScript**: Full functionality without JavaScript
- **Enhanced Experience**: JavaScript adds seamless filtering
- **SEO Friendly**: Server-rendered content for search engines
- **Accessibility**: WCAG compliant markup and navigation

## Customization

### Block Attributes
- `showPastEvents`: Display past events toggle
- `showFilters`: Enable/disable filtering interface
- `showSearch`: Show/hide search functionality
- `showDateFilter`: Enable/disable date filtering
- `defaultDateRange`: Default date range (current, today, week, month)
- `enablePagination`: Enable/disable pagination

### Styling Integration
- **Design Tokens**: CSS custom properties from root.css
- **Color Manager**: Dynamic color theming
- **Responsive Grid**: Mobile-first design approach
- **Badge System**: Automatic color generation

## Developer Integration

### Template Customization
```php
// Custom event item template
$content = Template_Loader::get_template('event-item', [
    'event' => $event_data,
    'custom_var' => $custom_value
]);
```

### Filter Integration
```php
// Custom taxonomy filtering
add_filter('datamachine_events_calendar_query_args', function($args, $attributes) {
    // Modify query arguments
    return $args;
}, 10, 2);
```

### JavaScript Hooks
```javascript
// Custom calendar initialization
document.addEventListener('datamachine-calendar-initialized', function(e) {
    // Custom initialization logic
});
```

The Calendar block provides comprehensive event display with flexible customization options while maintaining performance and accessibility standards.