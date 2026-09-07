# Internal Links Abilities

**Implementation**: `inc/Abilities/InternalLinkingAbilities.php`

The `datamachine/v1/links` REST routes were retired in #3456. Internal link auditing and diagnostics are exposed through the REST-visible Data Machine abilities, executed through WordPress core's ability runner:

```
POST /wp-json/wp-abilities/v1/abilities/datamachine/<slug>/run
Content-Type: application/json

{ "input": { ... } }
```

## Authentication

Each ability's permission callback requires Data Machine manage permission (`PermissionHelper::can_manage()`, meaning `manage_flows`, `manage_settings`, or `manage_agents`; administrators pass through the `manage_options` fallback). The core ability runner also requires `show_in_rest` and a valid REST nonce for cookie-authenticated callers.

## Response Envelope

The ability runner returns the ability's output directly (no `{success, data}` wrapper). Errors return standard REST error objects (`code`, `message`, `data.status`).

## Internal Links Abilities

| Ability slug | Purpose |
| --- | --- |
| `datamachine/audit-internal-links` | Scan post content, build/cache the link graph, and return aggregates (`post_type`, `category`, `post_ids`, `force`, `types`). |
| `datamachine/get-orphaned-posts` | Return posts with zero inbound internal links (`post_type`, `limit`, `types`). Runs the audit automatically when no cache exists. |
| `datamachine/get-backlinks` | Return posts linking to a target post (`post_id`, `types`, `limit`). |
| `datamachine/check-broken-links` | Check URLs from the cached graph with HTTP HEAD/GET fallback (`scope` `internal`/`external`/`all`, `limit`, `timeout`, `types`). |
| `datamachine/diagnose-internal-links` | Report site-wide internal link coverage from stored metadata (no input). |
| `datamachine/link-opportunities` | Suggest internal link opportunities between posts. |
| `datamachine/internal-linking` | Queue agent insertion of semantic internal links (`post_ids`, `category`, `links_per_post`, `dry_run`, `force`). Not REST-visible. |

## Edge Types

`types` filters the link graph edges, for example `["html_anchor"]` or `["wikilink"]`. Omit for all types.

## Example

Run a fresh internal-link audit through the ability runner:

```bash
curl -X POST https://example.com/wp-json/wp-abilities/v1/abilities/datamachine/audit-internal-links/run \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"input":{"post_type":"post","force":true,"types":["html_anchor"]}}'
```
