# Update Taxonomy Term Tool

**Tool ID**: `update_taxonomy_term`
**Since**: v0.8.0

The Update Taxonomy Term tool allows AI agents to modify existing WordPress taxonomy terms. It supports updating core term fields and custom term metadata (meta).

## Capabilities

- **Core Fields**: Update `name`, `slug`, `description`, and `parent` (for hierarchical taxonomies).
- **Custom Meta**: Update arbitrary term metadata (e.g., `venue_address`, `venue_capacity`, `venue_website`).
- **Identifier Resolution**: Resolves terms by ID, name, or slug.
- **Security**: Protects system taxonomies and underscore-prefixed meta keys from modification.

## Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `term` | string | Yes | Term identifier (ID, name, or slug). |
| `taxonomy` | string | Yes | Taxonomy slug (e.g., `venue`, `artist`, `category`). |
| `name` | string | No | New term name. |
| `slug` | string | No | New term slug (URL-friendly). |
| `description` | string | No | New term description. |
| `parent` | string | No | New parent term (ID, name, or slug). |
| `meta` | object | No | Key-value pairs of term meta to update. |

## Usage Examples

### Updating a Venue Address

```json
{
  "tool": "update_taxonomy_term",
  "parameters": {
    "term": "The Fillmore",
    "taxonomy": "venue",
    "meta": {
      "venue_address": "1805 Geary Blvd, San Francisco, CA 94115"
    }
  }
}
```

### Renaming a Category

```json
{
  "tool": "update_taxonomy_term",
  "parameters": {
    "term": "Old Category Name",
    "taxonomy": "category",
    "name": "New Category Name",
    "slug": "new-category-name"
  }
}
```

## Best Practices

1. **Find Before Update**: Always use `search_taxonomy_terms` first to confirm the term exists and retrieve its current details.
2. **Specific Meta Keys**: Only update the specific meta keys required. Do not guess meta key names.
3. **Identifier Selection**: Prefer using the Term ID if available for the most reliable resolution.

## Constraints

- **Protected Meta**: Meta keys starting with an underscore (`_`) are considered internal/private and cannot be updated via this tool.
- **System Taxonomies**: Certain WordPress core taxonomies or system-critical taxonomies may be blocked.
- **Hierarchy**: The `parent` parameter only works for taxonomies that are hierarchical (like categories).

## Related Components

- `TaxonomyHandler` (`/inc/Core/WordPress/TaxonomyHandler.php`)
- `search_taxonomy_terms` tool
- `create_taxonomy_term` tool
