# Taxonomy Handler

**File Location**: `inc/Core/WordPress/TaxonomyHandler.php`

**Since**: 0.2.1

Handles taxonomy processing for WordPress publish operations with three selection modes and dynamic term creation.

## Overview

The TaxonomyHandler processes taxonomy assignments for WordPress posts during publishing. It supports three selection modes per taxonomy: skip, AI-decided, and pre-selected, with automatic term creation for non-existing terms.

## Architecture

**Location**: `/inc/Core/WordPress/TaxonomyHandler.php`
**Purpose**: Taxonomy assignment and term management
**Features**: Dynamic term creation, multiple selection modes

## Selection Modes

### Skip Mode
- **Value**: `'skip'`
- **Behavior**: Taxonomy excluded from processing
- **Use Case**: Taxonomies not relevant for the content

### AI-Decided Mode
- **Value**: `'ai_decides'`
- **Behavior**: AI determines taxonomy terms based on content
- **Parameter**: Uses taxonomy-specific parameter names (category, tags, taxonomy_name)
- **Use Case**: Let AI categorize content intelligently

### Pre-Selected Mode
- **Value**: Term name, slug, or numeric ID (as string)
- **Behavior**: Specific term pre-selected in configuration
- **Validation**: Term must exist in taxonomy (resolved by name, slug, or ID)
- **Use Case**: Fixed taxonomy assignments
- **Enhanced** (@since v0.3.0): `processPreSelectedTaxonomy()` accepts term name/slug instead of requiring numeric ID, enabling direct use of term names from site context

## Key Methods

### processTaxonomies()

Process all configured taxonomies for a post.

```php
public function processTaxonomies(int $post_id, array $parameters, array $handler_config): array
```

**Parameters**:
- `$post_id`: WordPress post ID
- `$parameters`: AI tool parameters with taxonomy values
- `$handler_config`: Handler configuration with taxonomy selections

**Returns**: Array of processing results for each taxonomy

### getTermName()

Get term name from term ID and taxonomy (static method).

```php
public static function getTermName(int $term_id, string $taxonomy): ?string
```

**Parameters**:
- `$term_id`: WordPress term ID
- `$taxonomy`: Taxonomy name

**Returns**: Term name if exists, null otherwise

**Usage**: Used for pre-selected taxonomy validation during settings sanitization and term assignment.

### assignTaxonomy()

Assign taxonomy terms with dynamic creation.

```php
public function assignTaxonomy(int $post_id, string $taxonomy_name, $taxonomy_value): array
```

**Parameters**:
- `$post_id`: WordPress post ID
- `$taxonomy_name`: Taxonomy name (category, post_tag, etc.)
- `$taxonomy_value`: Term name(s) - string or array

**Features**:
- Creates non-existing terms automatically
- Handles single terms or arrays
- Validates taxonomy existence
- Returns structured success/error results

## Parameter Name Mapping

Maps WordPress taxonomy names to AI parameter names:

```php
private function getParameterName(string $taxonomy_name): string {
    if ($taxonomy_name === 'category') {
        return 'category';
    } elseif ($taxonomy_name === 'post_tag') {
        return 'tags';
    } else {
        return $taxonomy_name; // Custom taxonomies use their name
    }
}
```

## Dynamic Term Creation

### Process Flow

1. **Validate Taxonomy**: Ensure taxonomy exists
2. **Process Terms**: Handle single term or array
3. **Find or Create**: Check if term exists, create if not
4. **Assign Terms**: Use `wp_set_object_terms()` for assignment

### Term Creation

```php
$term_result = wp_insert_term($term_name, $taxonomy_name);
if (is_wp_error($term_result)) {
    // Log error and continue
    return false;
}
return $term_result['term_id'];
```

## Configuration Integration

### Field Generation

Taxonomy fields are generated dynamically for all public taxonomies:

```php
$field_key = "taxonomy_{$taxonomy->name}_selection";
$options = [
    'skip' => __('Skip', 'datamachine'),
    'ai_decides' => __('AI Decides', 'datamachine'),
    'separator' => '──────────',
    // Individual terms...
];
```

### System Taxonomy Exclusion

Excludes system taxonomies via static constant and method:

```php
// System taxonomies defined as class constant
private const SYSTEM_TAXONOMIES = ['post_format', 'nav_menu', 'link_category'];

// Check if taxonomy should be skipped
if (TaxonomyHandler::shouldSkipTaxonomy($taxonomy_name)) {
    // Skip processing
}

// Get system taxonomies list
$excluded = TaxonomyHandler::getSystemTaxonomies();
```

## Error Handling

### Taxonomy Validation

- Checks taxonomy existence before processing
- Returns error for non-existent taxonomies

### Term Assignment Errors

- Captures `wp_set_object_terms()` errors
- Logs detailed error information
- Continues processing other taxonomies

### Term Creation Failures

- Logs term creation errors
- Continues with existing terms
- Doesn't fail entire taxonomy processing

## Usage in WordPress Publish Handler

```php
$taxonomy_handler = new TaxonomyHandler();
$results = $taxonomy_handler->processTaxonomies($post_id, $parameters, $handler_config);

foreach ($results as $taxonomy_name => $result) {
    if ($result['success']) {
        // Taxonomy successfully assigned
        $term_count = $result['term_count'];
        $terms = $result['terms'];
    }
}
```

## Logging

Comprehensive logging for taxonomy operations:

```php
do_action('datamachine_log', 'debug', 'WordPress Tool: Applied AI-decided taxonomy', [
    'taxonomy_name' => $taxonomy->name,
    'parameter_name' => $param_name,
    'parameter_value' => $parameters[$param_name],
    'result' => $taxonomy_result
]);
```

## Benefits

- **Flexible Selection**: Three modes for different use cases
- **Dynamic Creation**: Automatic term creation eliminates manual setup
- **AI Integration**: Seamless AI-decided taxonomy assignment
- **Error Resilience**: Continues processing despite individual failures
- **Extensible**: Easy to add new taxonomies and selection modes

## See Also

- WordPress Publish Handler - Main handler integration
- WordPressSettingsHandler - Settings field generation
- WordPress Handlers - Direct instantiation and initialization