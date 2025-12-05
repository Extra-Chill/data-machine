# Event_Post_Type

Custom post type registration and management for Data Machine Events with selective taxonomy menu control and custom admin columns for event date display and sorting.

## Overview

The `Event_Post_Type` class handles the complete registration and management of the `datamachine_events` custom post type. It provides enhanced admin interface features including custom columns for event dates, selective taxonomy menu control, and proper menu highlighting.

## Location

`inc/Core/Event_Post_Type.php`

## Key Features

### Custom Post Type Registration

- Registers `datamachine_events` post type with full WordPress features
- Supports all standard post features (title, editor, excerpt, thumbnail, etc.)
- REST API enabled with custom controller
- Publicly queryable with custom rewrite slug `/events`

### Admin Interface Enhancements

- **Custom Date Column**: Displays event date and time in admin post list
- **Sortable Date Column**: Allows sorting events by date in admin
- **Selective Taxonomy Menus**: Controls which taxonomies appear in admin menus
- **Menu Highlighting**: Proper menu highlighting for allowed taxonomies

### Constants

- `POST_TYPE`: `'datamachine_events'` - The post type slug
- `EVENT_DATE_META_KEY`: `'_datamachine_event_datetime'` - Meta key for event datetime

## Key Methods

### `register(): void`

Main registration method that sets up the post type and all admin hooks.

### `add_event_date_column($columns): array`

Adds the "Event Date" column to the admin posts list.

### `render_event_date_column($column, $post_id): void`

Renders the event date in the custom column, showing formatted date and time.

### `sortable_event_date_column($columns): array`

Makes the event date column sortable in the admin interface.

### `sort_by_event_date($query): void`

Handles the actual sorting by event date when the column header is clicked.

### `control_taxonomy_menus(): void`

Manages which taxonomy menus are displayed in the admin, based on the `datamachine_events_post_type_menu_items` filter.

## Admin Menu Control

The class provides selective control over which taxonomies appear in the admin menu for the events post type. By default, only `venue` and `promoter` taxonomies are shown, with `settings` as an additional menu item.

This is controlled via the `datamachine_events_post_type_menu_items` filter:

```php
add_filter('datamachine_events_post_type_menu_items', function($items) {
    return [
        'venue' => true,
        'promoter' => true,
        'settings' => true
    ];
});
```

## Integration Points

- **Meta Storage**: Works with `meta-storage.php` for automatic datetime synchronization
- **Taxonomies**: Integrates with `Venue_Taxonomy` and `Promoter_Taxonomy`
- **Blocks**: Supports Event Details and Calendar blocks
- **REST API**: Provides REST endpoints for the post type

## Admin Columns

The custom event date column shows:
- Formatted date (M j, Y format)
- Formatted time (g:i a format)
- "No date set" message when no datetime is available
- "Invalid date" message for malformed dates

## Post Type Configuration

```php
array(
    'public' => true,
    'publicly_queryable' => true,
    'show_ui' => true,
    'show_in_menu' => true,
    'query_var' => true,
    'rewrite' => array('slug' => 'events'),
    'capability_type' => 'post',
    'has_archive' => true,
    'hierarchical' => false,
    'supports' => array(
        'title', 'editor', 'excerpt', 'thumbnail',
        'custom-fields', 'revisions', 'author',
        'page-attributes', 'editor-styles',
        'wp-block-styles', 'align-wide'
    ),
    'show_in_rest' => true,
    'menu_icon' => 'dashicons-calendar-alt'
)
```