# Agent Memory Backends

Data Machine treats agent memory as logical memory records that usually render as markdown files. The storage backend is replaceable, but the current registry, injection, editing rules, abilities, and scaffolding stay in Data Machine.

This lets self-hosted installs keep the current disk-backed workflow while managed hosts can project the same logical memory into a WordPress-native store such as `wp_guideline` when that substrate exists.

## Logical Identity

Every memory file is addressed by an `AgentMemoryScope` four-tuple:

```text
(layer, user_id, agent_id, filename)
```

| Field | Purpose |
|---|---|
| `layer` | Memory layer: `shared`, `agent`, `user`, or `network`. |
| `user_id` | Effective WordPress user ID. `0` means no user-specific layer. |
| `agent_id` | Agent identity. `0` means callers may resolve from the user context. |
| `filename` | File name or relative path within the layer, such as `MEMORY.md`, `contexts/editor.md`, or `daily/2026/04/17.md`. |

Backends translate that tuple to their own physical key: a filesystem path, a database row, a post, or another host-owned identifier. Callers should use `AgentMemory` and should not branch on the concrete backend.

This tuple is the candidate Agents API memory identity. Data Machine-specific concerns such as seed scaffolding, file editability, prompt-injection policy, and operator abilities are intentionally outside the persistence-store contract.

## Runtime Flow

```text
MemoryFileRegistry
  registered files, layers, modes, convention paths
        |
        v
MemoryPolicyResolver
  per-agent allow/deny filtering
        |
        v
CoreMemoryFilesDirective
  reads each file through AgentMemory
        |
        v
AgentMemoryStoreFactory
  datamachine_memory_store filter
        |
        +--> DiskAgentMemoryStore (default)
        |
        +--> alternate AgentMemoryStoreInterface implementation
```

`MemoryFileRegistry` is the source of truth for which core memory files exist, where they live logically, and which modes receive them. Registration metadata includes the layer, priority, editability, `modes`, whether a file is composable, and optional `convention_path` disk projection metadata.

`CoreMemoryFilesDirective` injects registered memory into AI requests at directive priority 20. It resolves the current agent mode from the payload, asks `MemoryPolicyResolver` for the allowed registered files, reads each file through `AgentMemory`, applies `datamachine_memory_file_content`, and emits `system_text` outputs.

Pipeline- and flow-scoped memory files use the same policy resolver before reading explicit filename lists, so per-agent memory policy applies consistently across core, pipeline, and flow memory surfaces.

## Default Disk Store

`DiskAgentMemoryStore` is the built-in default. It preserves the existing filesystem behavior: memory is stored as plain markdown under Data Machine's uploads area, with convention-path files such as `AGENTS.md` resolved through `MemoryFileRegistry::resolve_filepath()`.

Disk-backed memory remains current behavior where local writable disk is available. It is useful because files are human-readable, grep-able, git-versionable, and visible to co-located coding agents.

The disk store intentionally does not implement compare-and-swap writes; it accepts the `$if_match` parameter but ignores it. Alternate stores that support concurrency must honor `$if_match` and return a conflict result on hash mismatch.

## Backend Selection

Data Machine resolves memory persistence through one current filter:

```php
apply_filters(
    'datamachine_memory_store',
    null,
    AgentMemoryScope $scope
);
```

Return an `AgentMemoryStoreInterface` implementation to replace the disk default for the given scope. Return `null` to keep `DiskAgentMemoryStore`.

Future Agents API extraction should introduce its neutral resolver/filter name in the extracted package with a migration plan. Data Machine does not add a second alias today; `datamachine_memory_store` remains the active public behavior until ownership actually moves.

Backend selection should be capability-driven:

1. Use disk when a local runtime with writable filesystem is available.
2. Use a WordPress-native backend, such as guideline-backed storage, when that substrate exists or is deliberately polyfilled.
3. If no backend is available, fail clearly instead of silently pretending memory was written.

Data Machine does not hard-depend on a guideline backend. The `wp_guideline` post type is not guaranteed in WordPress core today; it may be provided by Gutenberg, a future core merge, or a plugin/polyfill. Consumers that choose this backend must own the availability check and any polyfill they need.

## DMC Boundary

Data Machine Code is the local runtime bridge. In environments where DMC is active and reports writable filesystem support, disk-backed memory is the right default because the coding-agent runtime can read and write the same files Data Machine injects.

DMC should be treated as a projection provider, not the memory model:

| Concern | Owner |
|---|---|
| Logical memory identity and access | Data Machine (`AgentMemoryScope`, `AgentMemory`) |
| Registered memory files and mode-aware injection | Data Machine (`MemoryFileRegistry`, directives) |
| Disk file projection for local coding agents | DMC + `DiskAgentMemoryStore` environment capability |
| Managed-host alternate backend | Consumer plugin via `datamachine_memory_store` |

`MEMORY.md` is not deprecated. On disk-capable installs it remains the agent's persistent knowledge file. On hosts without disk, the same logical `MEMORY.md` may be represented by another backend while still appearing to Data Machine as `(agent, user_id, agent_id, MEMORY.md)`.

## Daily Memory

Daily memory uses the same store seam. `DailyMemory` addresses files as relative paths in the agent layer:

```text
daily/YYYY/MM/DD.md
```

For example, the daily file for April 17, 2026 is the logical filename `daily/2026/04/17.md` with `layer = agent`. A single backend swap therefore covers `MEMORY.md`, daily memory, context files, and other agent-layer paths uniformly.

Path helper methods such as `DailyMemory::get_base_path()` and `get_file_path()` are disk conveniences only. Non-disk stores may persist daily memory in posts, rows, or another physical shape.

## Relationship To AI Framework

The memory-store seam is not a replacement for AI Framework. Data Machine still owns its portable agent memory model and prompt assembly, while consumers can route storage to the backend that fits the host. AI Framework integration can coexist with this model; it does not require Data Machine to abandon `AgentMemory`, `MEMORY.md`, or the registry-driven directive stack.

## Extension Rules

- Read and write memory through `AgentMemory`, not `AgentMemoryStoreFactory` directly.
- Implement `AgentMemoryStoreInterface` when replacing persistence.
- Preserve the four-tuple identity model exactly, even if the physical backend has different keys.
- Keep section parsing, scaffolding, editability gating, ability permissions, prompt-injection policy, and registry semantics above the store layer.
- Gate guideline-backed stores on real substrate availability; do not assume `wp_guideline` exists.
- Prefer disk when DMC/local writable filesystem support exists, because that is the projection external coding agents can inspect.

## See Also

- [WordPress as Persistent Memory for AI Agents](../core-system/wordpress-as-agent-memory.md)
- [Memory Policy](../core-system/memory-policy.md)
- [Daily Memory System](../core-system/daily-memory-system.md)
- [Core Filters: AgentMemoryStoreInterface](../development/hooks/core-filters.md#agentmemorystoreinterface-inccorefilesrepositoryagentmemorystoreinterfacephp)
