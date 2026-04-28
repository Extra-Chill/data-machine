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

- `agent_id`
- `bundle_slug`
- `bundle_version`
- `artifact_type`
- `artifact_id`
- `source_path`
- `installed_hash`
- `current_hash`
- `local_status`
- `installed_at`
- `updated_at`

`artifact_type` is one of `agent`, `memory`, `pipeline`, `flow`, `prompt`, `rubric`, `tool_policy`, `auth_ref`, `seed_queue`, or `schedule`.

Statuses:

- `clean`: current hash equals the installed hash.
- `modified`: current hash differs from the installed hash.
- `missing`: an installed artifact no longer exists in runtime state.
- `orphaned`: runtime state exists without an installed bundle record.

Hashing is deterministic: arrays are recursively sorted and JSON-encoded through `BundleSchema::encode_json()` before SHA-256 hashing. This makes formatting and associative key order irrelevant while preserving list order.

### Memory Section Artifacts

Memory artifacts can be tracked at section granularity. Section records extend the artifact identity with operational memory ownership fields:

- `agent_id`
- `section_id`
- `section_heading`
- `section_type`
- `owner`: `bundle`, `user`, `runtime`, or `compaction`
- `bundle_slug` and `bundle_version` for bundle-owned seed sections
- `installed_hash`, `current_hash`, and `local_status`

Self-memory writes are policy-constrained: the default target is the current acting agent, allowed section types are operational (`operating_note`, `source_quirk`, `run_lesson`, `task_note`), durable facts are rejected, and bundle-owned sections are staged through PendingActions instead of overwritten directly.

## Pipeline And Flow Updates

Bundle-installed pipelines and flows use stable `portable_slug` values as their runtime identity:

- Pipelines resolve by `(agent_id, portable_slug)`.
- Flows resolve by `(pipeline_id, portable_slug)`.
- Re-importing the same bundle updates the existing clean rows instead of creating duplicates.
- Local edits are detected by comparing the current artifact hash with the install-time hash recorded in the owning agent's `datamachine_bundle.artifacts` config.
- Installed artifact tracking is persisted in the site-scoped `datamachine_bundle_artifacts` table, keyed by `(agent_id, bundle_slug, artifact_type, artifact_id)`, so bundle state is independent of runtime tables.
- Modified artifacts are reported as conflicts and are not overwritten by the import path.

Schedule and queue policy is intentionally conservative:

- New flows are installed paused/manual. The source schedule is retained as `_original_interval` for operators to opt in later.
- Existing flow schedules are preserved during upgrades.
- Queue slots (`prompt_queue`, `config_patch_queue`, `queue_mode`) seed new flows but are preserved on upgrades so runtime backlogs are not discarded.

## CLI

Bundle operations live under `wp datamachine agent-bundle`:

- `install <path>` imports a local bundle path (`.zip`, `.json`, or directory).
- `list` reports installed bundle-backed agents.
- `status <slug>` reports installed version and tracked artifact state by agent slug or bundle slug.
- `diff <path>` builds a read-only upgrade plan.
- `upgrade <path>` applies clean updates and stages locally modified artifacts as PendingActions.
- `apply <pending_action_id>` accepts a staged bundle PendingAction.

Every read/preview command supports `--format=json` for automation.

## Follow-Ups

- Wire importer/exporter paths for prompts, rubrics, tool policies, auth refs, and seed queues.
- Use artifact statuses during bundle upgrade planning: auto-update `clean`, stage PendingActions for `modified`, surface `missing` and `orphaned` explicitly.
- Add registered bundle sources once bundles move beyond local paths.
