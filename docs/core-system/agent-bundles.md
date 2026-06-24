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
├── auth-refs/<auth-ref-slug>.json
├── seed-queues/<queue-slug>.json
├── extensions/
└── <extras-key>/                     # Plugin-owned opaque extras (e.g. wiki/, datasets/)
```

`manifest.json`, `memory/`, `pipelines/`, `flows/`, `prompts/`, `rubrics/`, `tool-policies/`, `auth-refs/`, `seed-queues/`, and `extensions/` are first-class bundle schema. Unknown top-level directories are transported as plugin-owned extras.

### Reserved Trees And Extras

Data Machine owns the directory names listed in `BundleSchema::RESERVED_TREES` plus the manifest filename. Anything else at the bundle root is **opaque to the Bundle layer** and round-trips as an *extra*: a top-level directory whose contents are carried as a file map (`<key>/<relative-path>` => string contents) under `bundle["extras"]`.

Knowledge corpus trees use the same extras contract. A bundle can include `wiki/`, `corpus/`, `datasets/`, or another extension-owned tree, but Data Machine treats those files as bytes to transport. The owning extension interprets the corpus format, frontmatter, schema profile, import policy, and validation rules.

Reserved trees as of `schema_version 1`:

- `manifest.json`, `agent/`, `memory/`
- `pipelines/`, `flows/`
- `prompts/`, `rubrics/`, `tool-policies/`, `auth-refs/`, `seed-queues/`
- `extensions/`

Extras rules:

- Extras keys are slug-like directory names (ASCII alphanumerics, dashes, underscores; no slashes).
- Each path inside an extra must start with `<key>/`, must not contain `..` or `.` segments, and must not be absolute.
- Empty extras directories are dropped on read.
- Hidden entries (names starting with `.`), symlinks that escape the bundle root, and binary files (NUL-byte heuristic) are skipped on read with a logged warning.
- Data Machine does NOT auto-extract extras to disk on install. Consumers persist extras themselves via the post-install hook below.
- Data Machine does NOT parse or validate Markdown frontmatter profiles or any other knowledge corpus format inside extras.

If a bundle wants to advertise how a consumer should interpret an extra, keep that metadata extension-owned and generic. For example, an extension artifact can describe a profile hint without asking Data Machine core to understand the format:

```json
{
  "artifact_type": "knowledge_corpus",
  "artifact_id": "wiki",
  "source_path": "extensions/example-plugin/knowledge-corpus-wiki.json",
  "payload": {
    "extras_key": "wiki",
    "profile": "example-profile"
  }
}
```

In that example, Data Machine transports `wiki/` as opaque extras and normalizes the extension artifact envelope. The extension that owns `artifact_type: knowledge_corpus` decides what `profile: example-profile` means and how to import or validate those files.

### Consumer Pattern

Plugins claim extras keys through two hooks. Data Machine ships the transport; the consumer ships the semantics.

```php
// At export time, contribute extras the consumer wants packaged.
add_filter(
    'datamachine_bundle_export_extras',
    function ( array $extras, int $agent_id, array $agent ): array {
        $files = my_plugin_collect_wiki_files_for_agent( $agent_id );
        if ( ! empty( $files ) ) {
            $extras['wiki'] = $files; // keys must start with 'wiki/'
        }
        return $extras;
    },
    10,
    3
);

// At import time, claim and persist extras the consumer owns.
add_action(
    'datamachine_bundle_install_succeeded',
    function ( int $agent_id, string $slug, array $bundle_metadata, array $extras, array $context ): void {
        if ( empty( $extras['wiki'] ) ) {
            return;
        }
        my_plugin_import_wiki_files( $agent_id, $extras['wiki'], $context['is_upgrade'] );
    },
    10,
    5
);
```

The hook fires after the install/upgrade transaction commits — never on dry-run, never on failure. Listener exceptions are caught, logged, and suppressed; they do not roll back the install.

Consumer plugins coordinate corpus and Markdown/frontmatter profile semantics outside Data Machine core.

## Manifest Schema

`manifest.json` uses `schema_version: 1` and requires:

- `bundle_slug`: stable bundle identity.
- `bundle_version`: explicit bundle version for upgrade planning.
- `source_ref` and `source_revision`: optional source coordinates.
- `exported_at` and `exported_by`: export metadata.
- `agent`: `slug`, `label`, `description`, and `agent_config` defaults.
- `included`: lists of `memory`, `pipelines`, `flows`, `prompts`, `rubrics`, `tool_policies`, `auth_refs`, and `seed_queues`, plus `handler_auth` mode.
- `capabilities`: optional package-level capability strings required by the bundle.

`handler_auth` is one of:

- `refs`: include `auth_ref` handles only.
- `full`: reserved for encrypted credential exports.
- `omit`: omit handler auth material.

`run_artifacts` is an optional manifest-level default egress policy. Individual flow files can also declare `run_artifacts`; flow-level policy wins for that flow. See [Run Artifact Egress](#run-artifact-egress).

## Artifact Tracking

Bundle installs track artifacts independently of their runtime table. Installed artifact state is canonical in the site-scoped `datamachine_bundle_artifacts` table, keyed by `(agent_id, bundle_slug, artifact_type, artifact_id)`. The agent's `datamachine_bundle.artifacts` config may still contain a compatibility mirror for older installs and readers, but planning and lifecycle code should read through the installed artifact state adapter so legacy config rows can be backfilled into the table.

The foundation record shape is:

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

Bundle section artifacts are the bridge between seed memory and safe runtime learning:

- `MemorySectionArtifact::from_bundle_section()` records bundle-owned sections with install-time hashes.
- `can_auto_update_from_bundle()` is true only while a bundle-owned section is still clean.
- `should_stage_bundle_update()` is true when the section was locally modified after install.
- Runtime self-memory writes that target a bundle-owned section stage a PendingAction instead of replacing the section.

## Pipeline And Flow Updates

Bundle-installed pipelines and flows use stable `portable_slug` values as their runtime identity:

- Pipelines resolve by `(agent_id, portable_slug)`.
- Flows resolve by `(pipeline_id, portable_slug)`.
- Re-importing the same bundle updates the existing clean rows instead of creating duplicates.
- Local edits are detected by comparing the current artifact hash with the install-time hash recorded in `datamachine_bundle_artifacts`.
- Legacy installs that only have `datamachine_bundle.artifacts` config rows are read as a compatibility fallback and backfilled into the table on first lifecycle read.
- Modified artifacts are reported as conflicts and are not overwritten by the import path.

Upgrade planning groups artifacts into deterministic buckets:

- `auto_apply`: new artifacts with no local value, or artifacts whose current hash still matches the installed hash.
- `needs_approval`: untracked local artifacts or locally modified artifacts.
- `warnings`: missing local artifacts and installed artifacts absent from the target bundle.
- `no_op`: current artifact already matches the target.

`install` creates new package-backed agents. `upgrade` applies clean updates and stages review-required changes through `AgentBundleUpgradePendingAction` with kind `bundle_upgrade`. `diff` runs the same planner without mutation. Applying a bundle PendingAction only applies explicitly approved artifact keys; unapproved entries are skipped, and consumers write storage through the registered artifact handlers or the `datamachine_bundle_upgrade_apply_artifact` filter.

Schedule and queue policy is intentionally conservative:

- New flows are installed paused/manual. The source schedule is retained as `_original_interval` for operators to opt in later.
- Existing flow schedules are preserved during upgrades.
- Queue slots (`prompt_queue`, `config_patch_queue`, `queue_mode`) seed new flows but are preserved on upgrades so runtime backlogs are not discarded.

Portable flow-step policy fields are explicit in `flows/<flow-slug>.json`:

- `enabled_tools`: flow-scoped AI allow-list consumed by `ToolPolicyResolver`.
- `disabled_tools`: flow-scoped AI deny-list composed with pipeline-level disabled tools.
- `prompt_queue`: AI seed queue entries shaped as `{ "prompt": "...", "added_at": "..." }`.
- `config_patch_queue`: fetch seed queue entries shaped as `{ "patch": { ... }, "added_at": "..." }`.
- `queue_mode`: one of `drain`, `loop`, or `static`; shared by both queue slots on that flow step.

## Run Artifact Egress

`run_artifacts` declares which runtime artifacts a bundle consumer may surface after a job. Data Machine validates and stores the policy; downstream runtimes such as Data Machine Code decide how to materialize egress targets like PR bodies or bundle files.

Supported sources:

- `completion_assertions`
- `daily_memory`
- `transcript_summary`

Supported egress targets:

- `artifact` — retain in the job artifact payload.
- `bundle-file` — allow a consumer to write the artifact into a bundle-relative file.
- `pr-body` — allow a consumer to summarize the artifact in a PR body or review surface.

Example:

```json
{
  "run_artifacts": {
    "daily_memory": {
      "egress": ["artifact", "bundle-file", "pr-body"],
      "bundle_relative_path": "memory/agent/daily/{yyyy}/{mm}/{dd}.md"
    }
  }
}
```

Normalization drops unknown sources, unknown egress targets, duplicate targets, and unsafe bundle-relative paths. Data Machine does not execute GitHub-specific behavior from this policy; it only preserves a deterministic egress contract for consumers.

During AI conversation loops, successful tool calls can add in-flight run artifacts before job persistence. `JobArtifacts` maps `agent_memory` and `agent_daily_memory` writes to canonical bundle-relative paths so DMC or other package consumers can export the evidence without scraping transcripts.

## Extras Vs Extension Artifacts

Bundles have two plugin-extension surfaces with different contracts:

| Surface | Location | Shape | Data Machine behavior | Use when |
|---|---|---|---|---|
| Extras | Any unreserved top-level directory, exposed under `bundle["extras"]` | Opaque file map keyed by top-level directory | Transport only; consumer persists via `datamachine_bundle_install_succeeded` | A plugin owns a directory tree such as `wiki/` or `datasets/` |
| Extension artifacts | `extensions/**/*.json`, exposed as `extension_artifacts` | Typed artifact envelopes with `artifact_type`, `artifact_id`, `source_path`, payload/hash fields | Normalized, included in package projection, upgrade planning, and artifact handlers | A plugin wants artifact diffing, upgrade conflict detection, and PendingAction apply semantics |

Use extras for bulk opaque content where Data Machine should not inspect individual files. Use extension artifacts when each item needs stable identity, hashes, status, and upgrade behavior.

## Validation And Inspection

`inspect` and `validate` are read-only lifecycle surfaces. They resolve and parse a bundle, project it to an Agents API `WP_Agent_Package`, and run `WP_Agent_Package_Capability_Checker` against Data Machine's host capability list.

The compatibility report shape is stable for automation:

```json
{
  "compatible": true,
  "status": "compatible",
  "required_capabilities": [],
  "host_capabilities": ["datamachine", "datamachine/agent-bundle"],
  "unsupported_capabilities": [],
  "unknown_artifact_types": [],
  "unsupported_artifacts": []
}
```

Plugins can extend the host capability list with the `datamachine_agent_bundle_host_capabilities` filter. Unsupported package-level requirements appear in `unsupported_capabilities`; unknown artifact types and unsupported artifact-level `requires` entries appear in `unsupported_artifacts`.

## CLI

Agent package operations live under `wp datamachine agent`:

- `install <path|url>` imports a local bundle path (`.zip`, `.json`, or directory) or a remote URL.
- `import <path|url>` imports a portable agent export and creates a new agent.
- `export <agent>` writes an agent identity, memory, pipelines, and flows to a portable bundle.
- `installed` reports installed package-backed agents without clobbering `agent list`.
- `status <slug>` reports installed version and tracked artifact state by agent slug or bundle slug.
- `inspect <path|url>` loads a bundle without writing and returns package metadata plus compatibility details.
- `validate <path|url>` loads a bundle without writing and returns whether Data Machine can support its declared package and artifact requirements.
- `diff <path|url>` builds a read-only upgrade plan.
- `upgrade <path|url>` applies clean updates and stages locally modified artifacts as PendingActions.
- `rebase <path|url>` previews 3-way merges for locally modified bundle artifacts.
- `apply <pending_action_id>` accepts a staged bundle PendingAction.
- `run-bundle <path|url>` starts a selected bundle flow as a headless ephemeral workflow.

Every read/preview command supports `--format=json` for automation.

`run-bundle` returns `datamachine/agent-bundle-run/v1`. By default it is a job-start envelope with `job_id`, `execution_type`, and bundle metadata. Pass `--wait` to drain the created job in the current request and include terminal `job_status`, `wait_result`, and final `engine_data`. `--step-budget` and `--time-budget-ms` bound synchronous drains for one-shot CI or headless runtimes.

`install`, `import`, `upgrade`, and `diff` accept `--token=<token>` and `--token-env=<varname>` for one-off authenticated downloads — see [Authenticated Bundle Sources](#authenticated-bundle-sources).

## Authenticated Bundle Sources

Public archives install with no configuration. Private GitHub repositories, GitHub Enterprise (GHE) hosts, and any other URL behind `Authorization: Bearer <token>` are supported through a layered opt-in chain:

1. **CLI flag** (`--token=<token>` / `--token-env=<varname>`) — single-call, never persisted, never logged.
2. **Environment variable / PHP constant** — `DATAMACHINE_GITHUB_TOKEN` for github.com / raw.githubusercontent.com; per-host symbol for GHE (configured via filter).
3. **WP option** (github.com only) — `datamachine_bundle_source_github_token`. Lowest precedence convenience slot.
4. **Filter fallback** — `datamachine_bundle_source_token_for_url` for sigillo, AWS Secrets Manager, Vault, etc.
5. **Generic per-request filter** — `datamachine_bundle_source_download_args` for arbitrary header injection (any host, any auth scheme).

Data Machine **never persists tokens itself.** Storage and rotation live in the host plugin or operator's environment. Errors that surface from a failed download include the URL but never the `Authorization` header — see `BundleSourceAuth::redact_args_for_log()` if you log the args downstream.

CLI token flags are resolved into the bundle-source context for that one import/diff/upgrade request only. `--token-env=<varname>` reads the token from the named environment variable and avoids placing the token in shell history. The internal download args carry private `datamachine_bundle_source` metadata until auth injection finishes; that metadata is stripped before the HTTP request is made.

### Example 1 — github.com private archive via env var

```bash
export DATAMACHINE_GITHUB_TOKEN=ghp_xxx
wp datamachine agent install https://github.com/private-org/private-repo/archive/refs/heads/main.zip --yes
```

A 401/403/404 response surfaces as `WP_Error( 'datamachine_bundle_source_auth_required', ... )` with a hint about the configured token slots.

**URL routing.** When a token is available for `api.github.com`, web-host archive URLs (`github.com/<o>/<r>/archive/refs/heads/<branch>.zip`, `archive/<sha>.zip`, and `tree/<branch>`) are rewritten to `api.github.com/repos/<o>/<r>/zipball/<ref>` before the request flies. The web-host archive endpoint rejects Personal Access Tokens with HTTP 404; the API endpoint accepts both fine-grained and classic PATs and returns a 302 to a signed S3 URL. Public installs (no token configured) keep using the web-host URL so they don't pay the API rate-limit cost. The `Authorization` header is dropped on cross-host redirects so it never leaks to S3 (which would 400 on the unrecognized auth scheme).

### Example 2 — GHE archive via filter-configured host + constant

```php
// mu-plugin or theme bootstrap.
add_filter( 'datamachine_bundle_source_ghe_hosts', function ( array $hosts ): array {
    $hosts['github.a8c.com'] = 'DATAMACHINE_A8C_GHE_TOKEN';
    return $hosts;
} );

define( 'DATAMACHINE_A8C_GHE_TOKEN', 'ghp_yyy' );
```

```bash
wp datamachine agent install https://github.a8c.com/team/brain/archive/refs/heads/main.zip --yes
```

### Example 3 — Custom secret store via filter callback

For sigillo / AWS Secrets Manager / Vault — anything that doesn't fit the env-var/constant model — wire a callback on `datamachine_bundle_source_download_args` and skip the resolution chain entirely:

```php
add_filter(
    'datamachine_bundle_source_download_args',
    function ( array $args, string $source, string $fetch_url ): array {
        $host = wp_parse_url( $fetch_url, PHP_URL_HOST ) ?: '';
        if ( str_ends_with( $host, '.example.com' ) ) {
            $token = my_sigillo_client()->get( "bundles/{$host}" );
            if ( $token ) {
                $args['headers']['Authorization'] = 'Bearer ' . $token;
            }
        }
        return $args;
    },
    10,
    3
);
```

Or hook the lower-cost `datamachine_bundle_source_token_for_url` fallback when you only want to fill the gap for hosts that have no built-in slot.

### Source Revision Capture

For GitHub archive responses, `BundleSource::fetch_to_tempfile()` reads the response `ETag` header and parses the git SHA out of the `W/"<sha>:<format>"` (or bare `"<sha>"`) shape GitHub serves. When present and parseable the SHA is exposed via `BundleSource::last_resolved_revision()` and stamped onto the bundle as `source_revision`. Best-effort only — installs do not fail when the ETag is absent or unparseable.

## See Also

- [Memory Policy](./memory-policy.md) — policy gates for memory injection and self-memory writes.
- [Daily Memory System](./daily-memory-system.md) — daily-memory storage, compaction, and artifacts.
- [Pending Actions](./pending-actions.md) — approval envelopes used for bundle upgrades and protected memory writes.
- [Agent Memory Backends](../architecture/agent-memory-backends.md) — logical memory identity and backend projection.
