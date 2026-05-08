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
├── seed-queues/<queue-slug>.json
├── extensions/
└── <extras-key>/                     # Plugin-owned opaque extras (e.g. wiki/, datasets/)
```

Only `manifest.json`, `pipelines/`, `flows/`, and `memory/` have import/export adapters today. The remaining directories are reserved schema surface for prompts, rubrics, tool policies, auth references, seed queue artifacts, and plugin-owned extension artifacts.

### Reserved Trees And Extras

Data Machine owns the directory names listed in `BundleSchema::RESERVED_TREES` plus the manifest filename. Anything else at the bundle root is **opaque to the Bundle layer** and round-trips as an *extra*: a top-level directory whose contents are carried as a file map (`<key>/<relative-path>` => string contents) under `bundle["extras"]`.

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

See `Automattic/intelligence#467` for the reference consumer that claims the `wiki/` extras key.

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

Portable flow-step policy fields are explicit in `flows/<flow-slug>.json`:

- `enabled_tools`: flow-scoped AI allow-list consumed by `ToolPolicyResolver`.
- `disabled_tools`: flow-scoped AI deny-list composed with pipeline-level disabled tools.
- `prompt_queue`: AI seed queue entries shaped as `{ "prompt": "...", "added_at": "..." }`.
- `config_patch_queue`: fetch seed queue entries shaped as `{ "patch": { ... }, "added_at": "..." }`.
- `queue_mode`: one of `drain`, `loop`, or `static`; shared by both queue slots on that flow step.

## CLI

Agent package operations live under `wp datamachine agent`:

- `install <path|url>` imports a local bundle path (`.zip`, `.json`, or directory) or a remote URL.
- `installed` reports installed package-backed agents without clobbering `agent list`.
- `status <slug>` reports installed version and tracked artifact state by agent slug or bundle slug.
- `diff <path|url>` builds a read-only upgrade plan.
- `upgrade <path|url>` applies clean updates and stages locally modified artifacts as PendingActions.
- `apply <pending_action_id>` accepts a staged bundle PendingAction.

Every read/preview command supports `--format=json` for automation.

`install`, `import`, `upgrade`, and `diff` accept `--token=<token>` and `--token-env=<varname>` for one-off authenticated downloads — see [Authenticated Bundle Sources](#authenticated-bundle-sources).

## Authenticated Bundle Sources

Public archives install with no configuration. Private GitHub repositories, GitHub Enterprise (GHE) hosts, and any other URL behind `Authorization: Bearer <token>` are supported through a layered opt-in chain:

1. **CLI flag** (`--token=<token>` / `--token-env=<varname>`) — single-call, never persisted, never logged.
2. **Environment variable / PHP constant** — `DATAMACHINE_GITHUB_TOKEN` for github.com / raw.githubusercontent.com; per-host symbol for GHE (configured via filter).
3. **WP option** (github.com only) — `datamachine_bundle_source_github_token`. Lowest precedence convenience slot.
4. **Filter fallback** — `datamachine_bundle_source_token_for_url` for sigillo, AWS Secrets Manager, Vault, etc.
5. **Generic per-request filter** — `datamachine_bundle_source_download_args` for arbitrary header injection (any host, any auth scheme).

Data Machine **never persists tokens itself.** Storage and rotation live in the host plugin or operator's environment. Errors that surface from a failed download include the URL but never the `Authorization` header — see `BundleSourceAuth::redact_args_for_log()` if you log the args downstream.

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

## Follow-Ups

- Wire importer/exporter paths for prompts, rubrics, tool policies, auth refs, and seed queues.
- Use artifact statuses during bundle upgrade planning: auto-update `clean`, stage PendingActions for `modified`, surface `missing` and `orphaned` explicitly.
- Add registered bundle sources once bundles move beyond local paths.
