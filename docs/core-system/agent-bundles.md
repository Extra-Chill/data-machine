# Agent Bundles

Agent bundles are portable, versioned packages for an agent's runtime behavior. They describe what to install without embedding local database IDs or secrets.

## Directory Layout

```text
<bundle>/
├── manifest.json
├── memory/
├── pipelines/<pipeline-slug>.json
├── flows/<flow-slug>.json
├── prompts/<prompt-slug>.md
├── rubrics/<rubric-slug>.md
├── tool-policies/<policy-slug>.json
└── seed-queues/<queue-slug>.json
```

Only `manifest.json`, `pipelines/`, `flows/`, and `memory/` have import/export adapters today. The remaining directories are reserved schema surface for prompts, rubrics, tool policies, auth references, and seed queue artifacts.

## Manifest Schema

`manifest.json` uses `schema_version: 1` and requires:

- `bundle_slug`: stable bundle identity.
- `bundle_version`: explicit bundle version for upgrade planning.
- `source_ref` and `source_revision`: optional source coordinates.
- `exported_at` and `exported_by`: export metadata.
- `agent`: `slug`, `label`, `description`, and `agent_config` defaults.
- `included`: lists of `memory`, `pipelines`, `flows`, `prompts`, `rubrics`, `tool_policies`, `auth_refs`, and `seed_queues`, plus `handler_auth` mode.

`handler_auth` is one of:

- `refs`: include `auth_ref` handles only.
- `full`: reserved for encrypted credential exports.
- `omit`: omit handler auth material.

## Artifact Tracking

Bundle installs track artifacts independently of their runtime table. The foundation record shape is:

- `bundle_slug`
- `bundle_version`
- `artifact_type`
- `artifact_id`
- `source_path`
- `installed_hash`
- `current_hash`
- `status`
- `installed_at`
- `updated_at`

`artifact_type` is one of `agent`, `memory`, `pipeline`, `flow`, `prompt`, `rubric`, `tool_policy`, `auth_ref`, `seed_queue`, or `schedule`.

Statuses:

- `clean`: current hash equals the installed hash.
- `modified`: current hash differs from the installed hash.
- `missing`: an installed artifact no longer exists in runtime state.
- `orphaned`: runtime state exists without an installed bundle record.

Hashing is deterministic: arrays are recursively sorted and JSON-encoded through `BundleSchema::encode_json()` before SHA-256 hashing. This makes formatting and associative key order irrelevant while preserving list order.

## Follow-Ups

- Add persistent DB storage for installed artifact records.
- Wire importer/exporter paths for prompts, rubrics, tool policies, auth refs, and seed queues.
- Use artifact statuses during bundle upgrade planning: auto-update `clean`, stage PendingActions for `modified`, surface `missing` and `orphaned` explicitly.
