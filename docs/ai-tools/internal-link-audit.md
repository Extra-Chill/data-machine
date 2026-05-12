# Internal Link Audit

`internal_link_audit` audits WordPress links and cached link graph data.

| Field | Value |
| --- | --- |
| Modes | chat, pipeline |
| Mutation risk | Low mutation |
| Registered in | `ToolServiceProvider.php` via `InternalLinkAudit` |
| Backing abilities | `datamachine/audit-internal-links`, `datamachine/get-orphaned-posts`, `datamachine/get-backlinks`, `datamachine/check-broken-links` |

## Actions

- `audit`: scan content and cache the link graph.
- `orphans`: list posts with no inbound links.
- `backlinks`: list posts linking to a target post ID.
- `broken`: check internal, external, or all links for broken URLs.

Run `audit` before the other actions when current link data matters.
