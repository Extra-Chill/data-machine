# System Agent and Runtime Model

This document defines the canonical agent architecture for Data Machine.

## Core Model

- **System Agent**: Data Machine's orchestration engine inside WordPress.
  - Schedules and routes tasks
  - Applies directives and policies
  - Selects tools and context
- **Agent Runtime**: The execution runtime that carries out requests.
  - External runtimes can execute now (for example, OpenCode sessions)
  - `data-machine-agent` is the planned first-party runtime
- **Contexts**: Execution scopes for the same System Agent.
  - `pipeline`
  - `chat`
  - `system`

## Why this distinction matters

`agent_type` exists in APIs and storage today. In practice, it identifies **context**,
not separate agent identities. This distinction keeps behavior aligned across entry points
without breaking compatibility.

## Compatibility rule

For backward compatibility:

- Keep existing fields and keys (`agent_type`, `agent_models`, etc.)
- Use **context language** in docs and UI copy where appropriate
- Treat `agent_type` values as context identifiers

## Mental model

```text
                  +--------------------------+
                  |       System Agent       |
                  | orchestration + policy   |
                  +------------+-------------+
                               |
                +--------------+--------------+
                |              |              |
          +-----v-----+  +-----v-----+  +-----v-----+
          | pipeline  |  |   chat    |  |  system   |
          | context   |  | context   |  | context   |
          +-----+-----+  +-----+-----+  +-----+-----+
                \              |              /
                 \             |             /
                  +------------v------------+
                  |      Agent Runtime      |
                  | executes model/tool loop|
                  +-------------------------+
```

## Implementation notes

- `SystemAgent` remains the task orchestration primitive
- Runtime details should not leak into orchestration interfaces
- Context-specific behavior should be additive and filter-driven
