# Pagination System

Efficient server-side pagination system for Calendar block with SQL-based optimization.

## Overview

The Pagination system provides server-side pagination for Calendar block with optimized SQL queries and performance-focused architecture. Handles large event datasets with minimal memory usage and fast response times.

## Features

### SQL-Based Pagination
- **Database-Level Filtering**: Pagination handled at SQL query level
- **Efficient Queries**: Optimized WP_Query with meta_query for date filtering
- **Memory Efficient**: Loads only current page events (~10 per page vs 500+ in memory)
- **Scalable Architecture**: Handles large event datasets without performance degradation

### Progressive Enhancement
- **Server-First**: Full pagination functionality without JavaScript
- **JavaScript Enhanced**: Seamless page transitions without page reloads
- **History API**: Browser back/forward button support
- **URL Preservation**: Shareable pagination states via URL parameters

### Performance Optimization
- **Indexed Queries**: Uses `_datamachine_event_datetime` meta field for fast date filtering
- **Separate Count Queries**: Optimized counting for pagination controls
- **Caching Support**: Template caching for rendered pagination HTML
- **Minimal DOM Updates**: Efficient DOM manipulation for page transitions

## Usage

### Basic Pagination
```php
use DataMachineEvents\Blocks\Calendar\Pagination;

// Initialize pagination
$pagination = new Pagination([
    'paged' => get_query_var('paged', 1),
    'posts_per_page' => 10,
    'total_events' => $total_events_count
]);

// Render pagination HTML
echo $pagination->render();
```

### Calendar Integration
```php
// In Calendar block render.php
$pagination = new Pagination([
    'paged' => $current_page,
    'posts_per_page' => $posts_per_page,
    'total_events' => $wp_query->found_posts,
    'max_num_pages' => $wp_query->max_num_pages,
    'base_url' => get_permalink(),
    'query_args' => $current_query_args
]);

// Include pagination template
Template_Loader::include_template('pagination', [
    'pagination' => $pagination,
    'current_page' => $current_page,
    'total_pages' => $wp_query->max_num_pages
]);
```

## Template System

### Pagination Template
```php
// templates/pagination.php
<?php if ($pagination->has_pages()): ?>
    <nav class="datamachine-pagination" role="navigation" aria-label="Event pagination">
        <div class="datamachine-pagination-links">
            <?php if ($pagination->has_previous()): ?>
                <a href="<?php echo esc_url($pagination->get_previous_url()); ?>" 
                   class="datamachine-pagination-prev" 
                   aria-label="Previous page">
                    ← Previous
                </a>
            <?php endif; ?>
            
            <span class="datamachine-pagination-info">
                Page <?php echo esc_html($pagination->get_current_page()); ?> 
                of <?php echo esc_html($pagination->get_total_pages()); ?>
            </span>
            
            <?php if ($pagination->has_next()): ?>
                <a href="<?php echo esc_url($pagination->get_next_url()); ?>" 
                   class="datamachine-pagination-next" 
                   aria-label="Next page">
                    Next →
                </a>
            <?php endif; ?>
        </div>
    </nav>
<?php endif; ?>
```

### Results Counter Template
```php
// templates/results-counter.php
<div class="datamachine-results-counter">
    <?php
    $start = ($current_page - 1) * $posts_per_page + 1;
    $end = min($start + $posts_per_page - 1, $total_events);
    
    printf(
        esc_html__('Viewing events %d-%d of %d total', 'datamachine-events'),
        $start,
        $end,
        $total_events
    );
    ?>
</div>
```

## REST API Integration

### Pagination Parameters
```javascript
// JavaScript pagination request
fetch('/wp-json/datamachine/v1/events/calendar?' + new URLSearchParams({
    paged: currentPage + 1,
    event_search: searchValue,
    date_start: startDate,
    date_end: endDate,
    'tax_filter[festival][]': selectedFestivals
}))
.then(response => response.json())
.then(data => {
    // Update calendar content
    document.querySelector('.datamachine-calendar').innerHTML = data.html;
    
    // Update pagination
    document.querySelector('.datamachine-pagination').innerHTML = data.pagination;
    
    // Update results counter
    document.querySelector('.datamachine-results-counter').innerHTML = data.counter;
    
    // Update URL without page reload
    history.pushState(null, '', newUrl);
});
```

### Server-Side Processing
```php
// In Calendar controller
public function calendar(WP_REST_Request $request) {
    $paged = $request->get_param('paged') ?: 1;
    $posts_per_page = 10;
    
    $args = [
        'post_type' => 'datamachine_events',
        'posts_per_page' => $posts_per_page,
        'paged' => $paged,
        'meta_query' => [
            [
                'key' => '_datamachine_event_datetime',
                'value' => $date_range,
                'compare' => 'BETWEEN'
            ]
        ]
    ];
    
    $query = new WP_Query($args);
    
    // Render templates
    $events_html = $this->render_events($query->posts);
    $pagination_html = $this->render_pagination($query, $paged);
    $counter_html = $this->render_counter($query, $paged);
    
    return [
        'success' => true,
        'html' => $events_html,
        'pagination' => $pagination_html,
        'counter' => $counter_html
    ];
}
```

## Performance Features

### SQL Optimization
```php
// Optimized query with proper indexing
$args = [
    'post_type' => 'datamachine_events',
    'posts_per_page' => 10,
    'paged' => $paged,
    'meta_query' => [
        [
            'key' => '_datamachine_event_datetime',
            'value' => [$start_date, $end_date],
            'compare' => 'BETWEEN',
            'type' => 'DATETIME'
        ]
    ],
    'orderby' => [
        '_datamachine_event_datetime' => 'ASC',
        'title' => 'ASC'
    ]
];
```

### Memory Management
```php
// Efficient memory usage with large datasets
function process_large_event_set($total_events) {
    $posts_per_page = 10;
    $total_pages = ceil($total_events / $posts_per_page);
    
    for ($page = 1; $page <= $total_pages; $page++) {
        $args = [
            'post_type' => 'datamachine_events',
            'posts_per_page' => $posts_per_page,
            'paged' => $page,
            'fields' => 'ids' // Only load IDs when possible
        ];
        
        $query = new WP_Query($args);
        process_events($query->posts);
        
        // Clear memory between pages
        wp_cache_flush();
    }
}
```

## JavaScript Integration

### Progressive Enhancement
```javascript
// frontend.js - Progressive pagination enhancement
class CalendarPagination {
    constructor(calendarElement) {
        this.calendar = calendarElement;
        this.currentPage = 1;
        this.init();
    }
    
    init() {
        // Add click handlers to pagination links
        this.calendar.addEventListener('click', (e) => {
            if (e.target.matches('.datamachine-pagination-prev, .datamachine-pagination-next')) {
                e.preventDefault();
                this.loadPage(e.target.href);
            }
        });
        
        // Handle browser back/forward
        window.addEventListener('popstate', (e) => {
            this.loadPage(window.location.href, false);
        });
    }
    
    async loadPage(url, updateHistory = true) {
        // Show loading state
        this.calendar.classList.add('loading');
        
        try {
            const response = await fetch(url);
            const data = await response.json();
            
            // Update content
            this.updateContent(data);
            
            // Update URL
            if (updateHistory) {
                history.pushState(null, '', url);
            }
            
        } catch (error) {
            console.error('Pagination error:', error);
            this.showError('Failed to load page. Please try again.');
        } finally {
            this.calendar.classList.remove('loading');
        }
    }
    
    updateContent(data) {
        // Update events
        const eventsContainer = this.calendar.querySelector('.datamachine-events-grid');
        if (eventsContainer && data.html) {
            eventsContainer.innerHTML = data.html;
        }
        
        // Update pagination
        const paginationContainer = this.calendar.querySelector('.datamachine-pagination');
        if (paginationContainer && data.pagination) {
            paginationContainer.innerHTML = data.pagination;
        }
        
        // Update counter
        const counterContainer = this.calendar.querySelector('.datamachine-results-counter');
        if (counterContainer && data.counter) {
            counterContainer.innerHTML = data.counter;
        }
        
        // Scroll to top of calendar
        this.calendar.scrollIntoView({ behavior: 'smooth' });
    }
}
```

## Customization

### Pagination Styling
```css
/* Custom pagination styles */
.datamachine-pagination {
    margin: 2rem 0;
    text-align: center;
}

.datamachine-pagination-links {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1rem;
}

.datamachine-pagination-prev,
.datamachine-pagination-next {
    padding: 0.5rem 1rem;
    background: var(--datamachine-primary-color, #0073aa);
    color: white;
    text-decoration: none;
    border-radius: 4px;
    transition: background-color 0.2s ease;
}

.datamachine-pagination-prev:hover,
.datamachine-pagination-next:hover {
    background: var(--datamachine-primary-hover, #005a87);
}

.datamachine-pagination-info {
    color: var(--datamachine-text-color, #333);
    font-weight: 500;
}
```

### Custom Pagination Logic
```php
// Customize pagination behavior
add_filter('datamachine_events_pagination_args', function($args, $attributes) {
    // Custom posts per page based on display mode
    if ($attributes['displayMode'] === 'circuit-grid') {
        $args['posts_per_page'] = 12; // 3x4 grid
    } else {
        $args['posts_per_page'] = 10; // List view
    }
    
    return $args;
}, 10, 2);
```

## Accessibility

### ARIA Support
```php
// Accessible pagination markup
<nav class="datamachine-pagination" 
     role="navigation" 
     aria-label="Event pagination">
    <div class="datamachine-pagination-links">
        <?php if ($pagination->has_previous()): ?>
            <a href="<?php echo esc_url($pagination->get_previous_url()); ?>" 
               class="datamachine-pagination-prev" 
               aria-label="Go to previous page"
               rel="prev">
                ← Previous
            </a>
        <?php endif; ?>
        
        <span class="datamachine-pagination-info" 
              aria-live="polite" 
              aria-atomic="true">
            Page <?php echo esc_html($pagination->get_current_page()); ?> 
            of <?php echo esc_html($pagination->get_total_pages()); ?>
        </span>
        
        <?php if ($pagination->has_next()): ?>
            <a href="<?php echo esc_url($pagination->get_next_url()); ?>" 
               class="datamachine-pagination-next" 
               aria-label="Go to next page"
               rel="next">
                Next →
            </a>
        <?php endif; ?>
    </div>
</nav>
```

### Keyboard Navigation
```javascript
// Keyboard navigation support
document.addEventListener('keydown', (e) => {
    if (e.target.matches('.datamachine-pagination-prev, .datamachine-pagination-next')) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            e.target.click();
        }
    }
});
```

## Troubleshooting

### Common Issues
- **Pagination Not Working**: Check `paged` query variable and URL structure
- **Performance Issues**: Verify `_datamachine_event_datetime` meta field is indexed
- **JavaScript Errors**: Ensure REST API endpoints are accessible and returning proper JSON
- **URL Conflicts**: Check for conflicts with other pagination systems

### Debug Information
```php
// Debug pagination data
function debug_pagination_data($query) {
    error_log('Current page: ' . get_query_var('paged', 1));
    error_log('Posts per page: ' . $query->get('posts_per_page'));
    error_log('Found posts: ' . $query->found_posts);
    error_log('Max pages: ' . $query->max_num_pages);
    error_log('SQL query: ' . $query->request);
}
add_action('pre_get_posts', 'debug_pagination_data');
```

The Pagination system provides efficient, scalable, and accessible pagination for Calendar block with server-side optimization and progressive enhancement support.