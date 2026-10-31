# Create Taxonomy Term

`create_taxonomy_term` creates a taxonomy term if it does not already exist.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Content mutation |
| Registered in | `ToolServiceProvider.php` via `CreateTaxonomyTerm` |
| Access | Editor |

## Inputs

- `taxonomy`: taxonomy slug.
- `name`: term name.
- `parent`: optional parent term for hierarchical taxonomies.
- `description`: optional term description.

Use during flow setup when required terms are missing.
