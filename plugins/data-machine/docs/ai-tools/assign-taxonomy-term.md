# Assign Taxonomy Term

`assign_taxonomy_term` assigns a taxonomy term to one or more posts.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Content mutation |
| Registered in | `ToolServiceProvider.php` via `AssignTaxonomyTerm` |
| Access | Editor |

## Inputs

- `term`: term ID, name, or slug.
- `taxonomy`: taxonomy slug.
- `post_ids`: post IDs to update.
- `append`: `true` to add to existing terms, `false` to replace.

Use `search_taxonomy_terms` first when the exact term is uncertain.
