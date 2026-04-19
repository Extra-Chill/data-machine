# Handler Registration Trait

Standardized handler registration trait introduced in v0.2.2 that eliminates ~70% of boilerplate code across all handlers.

## Overview

HandlerRegistrationTrait (`/inc/Core/Steps/HandlerRegistrationTrait.php`) provides a single `registerHandler()` method that automatically registers handlers with all required WordPress filters.

## Architecture

- **Location**: `/inc/Core/Steps/HandlerRegistrationTrait.php`
- **Purpose**: Centralize handler registration logic
- **Benefits**:
  - Reduces code duplication by ~70%
  - Ensures consistent registration patterns
  - Centralizes filter registration logic
  - Auto-handles conditional registration

## Method Signature

```php
protected static function registerHandler(
    string $handler_slug,
    string $handler_type,
    string $handler_class,
    string $label,
    string $description,
    bool $requires_auth = false,
    ?string $auth_class = null,
    ?string $settings_class = null,
    ?callable $tools_callback = null,
    ?string $authProviderKey = null
): void
```

### Parameters

- **$handler_slug**: Unique identifier for the handler (e.g., 'twitter', 'rss')
- **$handler_type**: Handler type ('fetch', 'publish', 'update')
- **$handler_class**: Fully qualified class name for the handler
- **$label**: Display name for admin interface (translatable)
- **$description**: Handler description for admin interface (translatable)
- **$requires_auth**: Whether handler requires OAuth authentication
- **$auth_class**: Fully qualified auth class name (required if requires_auth=true)
- **$settings_class**: Fully qualified settings class name
- **$tools_callback**: Callback for AI tool registration
- **$authProviderKey**: Optional auth provider key override for shared authentication. When set, the handler metadata includes `auth_provider_key`, and the auth provider is registered under this key.

## Filters Registered

The trait automatically registers handlers with the following WordPress filters:

### 1. datamachine_handlers

Handler metadata registration (always registered).

```php
add_filter('datamachine_handlers', function($handlers) {
    $handlers[$handler_slug] = [
        'type' => $handler_type,
        'class' => $handler_class,
        'label' => $label,
        'description' => $description,
        'requires_auth' => $requires_auth
    ];
    return $handlers;
});
```

### 2. datamachine_auth_providers

Authentication provider registration (conditional on `requires_auth=true`).

Auth providers are keyed by **provider key**, not necessarily the handler slug.

- Default: `provider_key === handler_slug`
- Shared auth: pass `$authProviderKey` to `registerHandler()` and use that as the provider key

```php
// Only registered if requires_auth is true
add_filter('datamachine_auth_providers', function($providers) use ($provider_key) {
    $providers[$provider_key] = new $auth_class();
    return $providers;
});
```

### 3. datamachine_handler_settings

Settings class registration (always registered if settings_class provided).

```php
add_filter('datamachine_handler_settings', function($settings, $handler_slug_param) {
    if ($handler_slug_param === $handler_slug) {
        return $settings_class;
    }
    return $settings;
}, 10, 2);
```

### 4. chubes_ai_tools

AI tool registration via callback (conditional on tools_callback provided).

```php
// Only registered if tools_callback is provided
add_filter('chubes_ai_tools', $tools_callback, 10, 3);
```

## Usage Example

### Basic Usage (Publish Handler)

```php
use DataMachine\Core\Steps\HandlerRegistrationTrait;

class TwitterFilters {
    use HandlerRegistrationTrait;

    public static function register(): void {
        self::registerHandler(
            'twitter',
            'publish',
            Twitter::class,
            __('Twitter', 'datamachine'),
            __('Post content to Twitter with media support', 'datamachine'),
            true,  // Requires OAuth
            TwitterAuth::class,
            TwitterSettings::class,
            function($tools, $handler_slug, $handler_config) {
                if ($handler_slug === 'twitter') {
                    $tools['twitter_publish'] = datamachine_get_twitter_tool($handler_config);
                }
                return $tools;
            }
        );
    }
}

// Auto-execute at file load
function datamachine_register_twitter_filters() {
    TwitterFilters::register();
}
datamachine_register_twitter_filters();
```

### Fetch Handler Example

```php
use DataMachine\Core\Steps\HandlerRegistrationTrait;

class RSSFilters {
    use HandlerRegistrationTrait;

    public static function register(): void {
        self::registerHandler(
            'rss',
            'fetch',
            RSS::class,
            __('RSS Feed', 'datamachine'),
            __('Fetch content from RSS/Atom feeds', 'datamachine'),
            false,  // No auth required
            null,
            RSSSettings::class,
            null  // No AI tools for fetch handlers
        );
    }
}

function datamachine_register_rss_filters() {
    RSSFilters::register();
}
datamachine_register_rss_filters();
```

### Upsert Handler Example

```php
use DataMachine\Core\Steps\HandlerRegistrationTrait;

class WordPressUpdateFilters {
    use HandlerRegistrationTrait;

    public static function register(): void {
        self::registerHandler(
            'wordpress_update',
            'update',
            WordPressUpdate::class,
            __('WordPress Update', 'datamachine'),
            __('Update existing WordPress content', 'datamachine'),
            false,
            null,
            WordPressUpdateSettings::class,
            function($tools, $handler_slug, $handler_config) {
                if ($handler_slug === 'wordpress_update') {
                    $tools['wordpress_update'] = datamachine_get_wordpress_update_tool($handler_config);
                }
                return $tools;
            }
        );
    }
}

function datamachine_register_wordpress_update_filters() {
    WordPressUpdateFilters::register();
}
datamachine_register_wordpress_update_filters();
```

## Migration Guide

Custom handlers should adopt this pattern by:

1. **Add trait to *Filters class**:
   ```php
   use DataMachine\Core\Steps\HandlerRegistrationTrait;

   class MyHandlerFilters {
       use HandlerRegistrationTrait;
   }
   ```

2. **Replace manual filter calls** with single `registerHandler()` call:
   ```php
   // Before (manual registration)
   add_filter('datamachine_handlers', function($handlers) { /* ... */ });
   add_filter('datamachine_auth_providers', function($providers) { /* ... */ });
   add_filter('datamachine_handler_settings', function($settings) { /* ... */ });
   add_filter('chubes_ai_tools', function($tools) { /* ... */ });

   // After (trait registration)
   self::registerHandler(
       'my_handler',
       'publish',
       MyHandler::class,
       __('My Handler', 'textdomain'),
       __('Handler description', 'textdomain'),
       true,
       MyHandlerAuth::class,
       MyHandlerSettings::class,
       $tools_callback
   );
   ```

3. **Move tool registration to callback parameter**:
   ```php
   function($tools, $handler_slug, $handler_config) {
       if ($handler_slug === 'my_handler') {
           $tools['my_tool'] = [
               'class' => MyHandler::class,
               'method' => 'handle_tool_call',
               'handler' => 'my_handler',
               'description' => 'Tool description',
               'parameters' => [/* ... */]
           ];
       }
       return $tools;
   }
   ```

4. **Remove redundant filter registration code** from *Filters.php file.

## File Organization

Handler registration files follow this structure:

```
/inc/Core/Steps/
├── Fetch/Handlers/
│   ├── RSS/
│   │   ├── RSS.php (handler class)
│   │   ├── RSSSettings.php (settings class)
│   │   └── RSSFilters.php (registration using trait)
│   └── ...
├── Publish/Handlers/
│   ├── Twitter/
│   │   ├── Twitter.php (handler class)
│   │   ├── TwitterAuth.php (auth class)
│   │   ├── TwitterSettings.php (settings class)
│   │   └── TwitterFilters.php (registration using trait)
│   └── ...
└── HandlerRegistrationTrait.php (shared trait)
```

## Benefits

### Code Reduction

Before trait (typical handler registration):
```php
// ~40 lines of repetitive filter registration code
add_filter('datamachine_handlers', function($handlers) {
    $handlers['my_handler'] = [
        'type' => 'publish',
        'class' => MyHandler::class,
        'label' => __('My Handler', 'textdomain'),
        'description' => __('Description', 'textdomain'),
        'requires_auth' => true
    ];
    return $handlers;
});

add_filter('datamachine_auth_providers', function($providers) {
    $providers['my_handler'] = MyHandlerAuth::class;
    return $providers;
});

add_filter('datamachine_handler_settings', function($settings, $handler_slug) {
    if ($handler_slug === 'my_handler') {
        return MyHandlerSettings::class;
    }
    return $settings;
}, 10, 2);

add_filter('chubes_ai_tools', function($tools, $handler_slug, $handler_config) {
    if ($handler_slug === 'my_handler') {
        $tools['my_tool'] = [/* ... */];
    }
    return $tools;
}, 10, 3);
```

After trait (same functionality):
```php
// ~15 lines total
self::registerHandler(
    'my_handler',
    'publish',
    MyHandler::class,
    __('My Handler', 'textdomain'),
    __('Description', 'textdomain'),
    true,
    MyHandlerAuth::class,
    MyHandlerSettings::class,
    function($tools, $handler_slug, $handler_config) {
        if ($handler_slug === 'my_handler') {
            $tools['my_tool'] = [/* ... */];
        }
        return $tools;
    }
);
```

### Consistency

- Ensures all handlers follow identical registration patterns
- Prevents missing or incorrectly configured filters
- Centralizes validation logic for handler metadata

### Maintainability

- Single location for registration logic updates
- Easy to add new registration requirements
- Simplified debugging of handler registration issues

### Type Safety

- Method signature provides clear parameter requirements
- IDE autocomplete support for all parameters
- PHP type hints prevent registration errors

## See Also

- Core Filters - Complete filter reference
- Fetch Handler - Fetch handler base class
- Publish Handler - Publish handler base class
- Settings Handler - Settings base classes

## Related Documentation

- [Handler Defaults System](handler-defaults.md) - Configuration merging logic
- [Services Layer](services-layer.md) - Architectural overview
