# AI Directive System

Data Machine assembles AI request context through directive classes registered on the `datamachine_directives` filter. The current implementation is documented in [core-system/ai-directives.md](core-system/ai-directives.md).

Key current facts:

- `AgentModeDirective` injects built-in mode guidance for `chat`, `pipeline`, and `system` at priority 22.
- The former `PipelineCoreDirective`, `ChatAgentDirective`, `SystemAgentDirective`, and `SiteContextDirective` classes were removed during the AgentMode refactor.
- `CoreMemoryFilesDirective` injects registered memory files from the shared, agent, and user layers.
- Pipeline, flow, daily-memory, and chat-inventory directives add request-specific context after the core mode and memory layers.
- Tools are injected by `RequestBuilder` through `PromptBuilder::setTools()`, not by a directive class.

Use [core-system/ai-directives.md](core-system/ai-directives.md) as the authoritative reference for priorities, mode assignments, filters, and examples.
