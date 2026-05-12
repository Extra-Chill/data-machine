# Merge Taxonomy Terms

`merge_taxonomy_terms` consolidates duplicate taxonomy terms.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Destructive |
| Registered in | `ToolServiceProvider.php` via `MergeTaxonomyTerms` |
| Access | Editor |

## Inputs

- `source_term`: term ID, name, or slug to merge from and delete.
- `target_term`: term ID, name, or slug to keep.
- `taxonomy`: taxonomy slug.
- `merge_meta`: whether to fill empty target meta from source meta.

The tool reassigns posts from source to target before deleting the source term.
