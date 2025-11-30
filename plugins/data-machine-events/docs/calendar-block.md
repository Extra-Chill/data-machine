# Calendar Block

Advanced event display with multiple view modes, filtering, and template system.

## Overview

The Calendar block provides flexible event display with progressive enhancement, supporting both server-side rendering and JavaScript-enhanced filtering. Features comprehensive taxonomy integration and a modular ES module architecture for frontend interactivity.

## Features

### Display Mode
- **Carousel List**: Horizontal scrolling layout with day grouping and time gap separators

### Filtering System
- **Search**: Real-time event search by title, venue, or taxonomy terms
- **Date Range**: Flexible date filtering with start/end date pickers and date context awareness
- **Taxonomy Filters**: Multi-taxonomy filtering with modal interface and dynamic filter loading
- **Past Events**: Toggle between upcoming and past events
- **State Persistence**: Filter selections saved and restored using localStorage
- **Date Context Filtering**: Taxonomy filters respect selected date ranges for accurate term counts

### Template System
- **7 Specialized Templates**: Modular, cacheable template rendering
- **Variable Extraction**: Automatic data sanitization and extraction
- **Output Buffering**: Efficient template processing

### Progressive Enhancement
- **Server-First**: Works without JavaScript for SEO and accessibility
- **JavaScript Enhanced**: Seamless filtering without page reloads
- **History API**: Shareable filter states via URL

## Display Mode

Carousel List provides sequential event display optimized for browsing:
- **Time Gaps**: Visual separators between date groups using time-gap-separator template
- **Horizontal Scroll**: CSS-only continuous event browsing with native touch/trackpad support
- **Compact Layout**: Space-efficient event presentation with day grouping
- **Performance**: CSS-only rendering requires no JavaScript for display
- **Accessibility**: Screen reader and keyboard navigation support

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
- **navigation.php**: Calendar navigation controls
- **no-events.php**: Empty state display
- **filter-bar.php**: Filtering interface
- **results-counter.php**: Results counter display
- **time-gap-separator.php**: Date gap visualization
- **modal/taxonomy-filter.php**: Advanced taxonomy filter modal

### Pagination System
Pagination is rendered by the `Pagination` class (`Pagination.php`) with extensibility filters:
- **datamachine_events_pagination_wrapper_classes**: Modify CSS classes on pagination wrapper
- **datamachine_events_pagination_args**: Customize `paginate_links()` arguments

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
- **Responsive Carousel**: Mobile-first horizontal scroll design
- **Day Colors**: Automatic color generation for date badges
- **Badge System**: Dynamic badge rendering with taxonomy colors

## JavaScript Module Architecture

The frontend JavaScript is organized into 6 focused ES modules for maintainability:

### Module Structure
- **frontend.js** (93 lines): Module orchestration and calendar initialization
- **modules/api-client.js**: REST API communication and calendar DOM updates
- **modules/carousel.js**: Carousel overflow detection, dot indicators, and chevron navigation
- **modules/date-picker.js**: Flatpickr date range picker integration
- **modules/filter-modal.js**: Taxonomy filter modal UI and accessibility
- **modules/navigation.js**: Past/upcoming navigation and pagination link handling
- **modules/state.js**: URL state management and query parameter building

### Initialization Flow
```javascript
// frontend.js orchestrates module initialization
document.querySelectorAll('.datamachine-events-calendar').forEach(initCalendarInstance);

function initCalendarInstance(calendar) {
    initCarousel(calendar);
    initDatePicker(calendar, handleFilterChange);
    initFilterModal(calendar, handleFilterChange, handleFilterChange);
    initNavigation(calendar, handleNavigation);
    initSearchInput(calendar);
}
```

### CSS Theme Integration
- **flatpickr-theme.css**: Date picker theming with design system integration
- CSS custom properties from `root.css` for consistent styling
- Dark mode support via CSS custom properties

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