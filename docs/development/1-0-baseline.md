# Data Machine 1.0 Baseline

Data Machine 1.0 supports one current schema and one current runtime contract. Fresh installs and deploy-in-place updates run the idempotent schema bootstrap in `inc/setup/schema.php`; activation and that bootstrap both converge through `ActivationServiceProvider::ensure_all_tables()`.

## Upgrade Boundary

- The 1.0 package does not transform pre-1.0 flow, handler, queue, bundle, result, or import/export shapes.
- Multisite chat-session convergence is canonical setup behavior. Every schema setup pass idempotently copies legacy per-site rows into the network table and records completion only after SQL success and anti-join parity.
- Pre-1.0 agent-owned flows that relied on the implicit default mailbox are not migrated seamlessly. Administrators must reset that mailbox configuration and explicitly reauthorize the named mailbox grant before the flow can run under 1.0. Runtime rejection of an absent or unauthorized mailbox is the canonical safety behavior.
- An installation that did not cross that final pre-1.0 release must reset unsupported Data Machine configuration and runtime data, then recreate or import it using the canonical 1.0 contracts.
- Current tables, columns, indexes, capabilities, defaults, memory scaffolding, and flow-schedule reconciliation remain idempotent bootstrap requirements. They are installation behavior, not compatibility migrations.

## Canonical Contracts

- Ability failures are `WP_Error`; successful callbacks return their documented payload.
- REST, WP-CLI, and AI tools may shape native results at their presentation boundary, but internal code does not accept legacy failure arrays.
- Pipeline CSV uses `format_version,row_type,pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings`. Every row declares format version `1.0` and one of `pipeline_step`, `flow`, or `flow_step`. A durable `flow` metadata row carries canonical schedule desired state independently of step settings; `flow_step.settings` carries canonical portable flow-step fields. The lossy unversioned pre-1.0 header and redundant scalar handler column are not accepted.
- Pending actions use the canonical `agents/resolve-pending-action` ability.

Compatibility is retained only for a named current external or persisted contract. New compatibility paths require that consumer or data requirement to be documented alongside the code.

## Retained Upgrade Edges

The following grep-visible paths are intentionally retained and are not pre-1.0 API compatibility promises:

- **Current schema/bootstrap:** repository `create_table()`, `migrate_columns()`, and `ensure_*_schema()` methods converge an existing current table on the canonical columns and indexes. Multisite chat convergence preserves per-site rows until verified in the network table.
- **Concrete persisted production data:** compound job statuses, generation-less recurring actions, descriptor-less in-flight claims, pending-action numeric owner columns, installed bundle artifact config mirrors (including the concrete singular `handler_config` runtime overlay consumed by `AgentBundleRuntimeDrift` and `AgentBundleArtifactPayloads`), plaintext OAuth secrets awaiting opportunistic encryption, and existing memory directories remain readable so current queued work and operator data survive the production upgrade.
- **Shipped external edges:** stable `datamachine/v1` REST error codes, the `datamachine_tools` and pending-action handler extension filters, OAuth implicit-flow support, and both possible PSR-16 namespaces are presentation, extension, or dependency boundaries rather than alternate internal models.
- **Current pipeline delegation edge:** `ToolPolicyResolver` accepts top-level `runtime_tool_declarations` because the current pipeline policy consumer supplies run-scoped client declarations there. The namespaced client-context envelope remains supported for interactive runtime adapters.
- **External/dependency edges:** Action Scheduler owns its schema compatibility hooks; WordPress owns the Abilities REST runner; Agents API owns the canonical pending-action ability and approval vocabulary. Data Machine keeps only the required adapters and verified dependency seams.
- **Canonical rejection paths:** references to unsupported handler fields, task fields, bundle scope literals, and malformed result packets reject or inspect bad input; they do not translate it into the canonical contract.

These retained edges have a named data owner or external consumer. Other removed migration transforms, aliases, and dual import/result shapes had neither after production data and managed workspace consumers were inventoried.
