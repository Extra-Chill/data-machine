# Search Taxonomy Terms

`search_taxonomy_terms` searches existing taxonomy terms.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Read-only |
| Registered in | `ToolServiceProvider.php` via `SearchTaxonomyTerms` |
| Access | Editor |

## Inputs

- `taxonomy`: taxonomy slug.
- `search`: optional partial name filter.
- `limit`: maximum terms to return, default `20`, max `100`.

Use before creating, updating, assigning, or merging terms.
