# AI Tools Overview

AI tools should be exposed as WordPress Ability projections whenever a single registered ability already owns the execution contract. `inc/Engine/AI/Tools/ToolServiceProvider.php` still instantiates tool classes so they can register configuration handlers and ability projections, but the model-facing declaration should use `datamachine_register_ability_tool()` instead of a class/method handler when possible.

The `datamachine_tools` class/method registry serves composite orchestration tools and adjacent handler tools that do not map cleanly to one public ability. Tools declare where they can run (`pipeline`, `system`, or pipeline-policy mode). Tools without an explicit mode list are not available in any mode and the ability or access level required to execute them.

## Registered Tool Inventory

Mutation risk means what the tool can change when called successfully:

- **Read-only**: reads data or runs diagnostics without changing site content/config.
- **Low mutation**: writes agent memory, logs, queues, generated media, or external notifications.
- **Config mutation**: changes pipeline, flow, handler, schedule, auth, or default configuration.
- **Content mutation**: creates, updates, assigns, merges, or deletes WordPress content/taxonomy/files.
- **Destructive**: deletes durable workflow objects, files, logs, jobs, or terms.

### Pipeline Tools

| Tool | Modes | Mutation risk | Purpose | Docs |
| --- | --- | --- | --- | --- |
| `image_generation` | pipeline | Low mutation | Create image-generation jobs and sideload generated media. | [Image Generation](image-generation.md) |
| `internal_link_audit` | pipeline | Low mutation | Audit internal links, backlinks, orphans, and broken URLs. | [Internal Link Audit](internal-link-audit.md) |
| `local_search` | pipeline | Read-only | Search local WordPress posts/pages. | [Local Search](local-search.md) |
| `web_fetch` | pipeline | Read-only | Fetch readable content from a URL. | [Web Fetch](web-fetch.md) |
| `wordpress_post_reader` | pipeline | Read-only | Read a WordPress post by permalink URL. | [WordPress Post Reader](wordpress-post-reader.md) |

### Pipeline-Policy Tools

Pipeline-policy tools are available while resolving pipeline tool policy, not as regular adjacent handler tools.

| Tool | Modes | Mutation risk | Purpose | Docs |
| --- | --- | --- | --- | --- |
| `agent_daily_memory` | pipeline policy (opt-in) | Low mutation | Read, write, list, and search daily memory journal files. | [Agent Daily Memory](agent-daily-memory.md) |
| `agent_memory` | pipeline policy (opt-in) | Low mutation | Read and update agent markdown files by section. | [Agent Memory](agent-memory.md) |

### System Tools

| Tool | Modes | Mutation risk | Purpose | Docs |
| --- | --- | --- | --- | --- |
| `consult_agent` | system (opt-in) | Read-only | Ask another registered agent a question through the Agents API `agents/chat` ability and return its answer. Implemented in `inc/Engine/AI/Tools/Global/ConsultAgent.php`; must be enabled explicitly through the task's `enabled_tools`. | — |

Topic duplicate checks are no longer a model tool. Use the `datamachine/check-duplicate` ability or `wp datamachine flows queue validate` (backed by `DataMachine\Core\Similarity\QueueTopicValidator`).

## Handler-Specific Tools

Handler tools are registered by handlers into the unified `datamachine_tools` registry as deferred `_handler_callable` entries. They become available at runtime when an adjacent step uses the matching handler or step type.

Examples include:

- `wordpress_publish`: create WordPress posts; accepts `content_format` (`markdown`, `html`, or `blocks`).
- `wordpress_update`: update existing WordPress content.
- `twitter_publish`, `bluesky_publish`, `facebook_publish`, `threads_publish`: publish social posts.
- `google_sheets_publish`: append rows to Google Sheets.

## Extension-Owned Tools

GitHub and workspace tools are not registered by Data Machine core. They are provided by the `data-machine-code` extension when that extension is installed.

Common extension tools include:

- `create_github_issue`, `list_github_issues`, `get_github_issue`, `manage_github_issue`
- `list_github_pulls`, `list_github_repos`
- `workspace_path`, `workspace_list`, `workspace_show`, `workspace_ls`, `workspace_read`

## Removed Interactive Tools

Data Machine no longer has an interactive agent mode. The admin/workflow tools that used to live under `inc/Api/Chat/Tools/` and the `queue_validator` tool were removed. Interactive agent runtimes (for example the Agents API `agents/chat` ability) can project the underlying `datamachine/*` abilities as tools directly.

## Tool Architecture

Ability-native projections are the preferred model-tool path:

- Register the underlying WordPress ability with `wp_register_ability()`.
- Expose it to model runs with `datamachine_register_ability_tool()` or the `datamachine_ability_tool_projections` filter.
- Put model-facing overrides such as `description`, `modes`, `parameters`, `requires_config`, and action-policy metadata on the projection declaration.

Class/method tool declarations are still explicit exceptions:

- `agent_memory` and `agent_daily_memory` compose several file/memory abilities behind one action router and resolve agent/user scope before dispatch.
- `internal_link_audit` composes several internal-link abilities behind one action router and strips internal response keys.
- `web_fetch` has no registered WordPress ability yet; it remains a class/method tool until a first-class fetch ability exists.
- Adjacent handler tools remain runtime-generated class/method declarations because their schemas depend on neighboring pipeline handler configuration.

Legacy tool classes extend `BaseTool` and register themselves with `registerTool()`. Registration declares:

- tool slug
- definition callback
- mode list
- required ability or access level

`ToolPolicyResolver` resolves the active tool set for a context. `ToolExecutor` handles execution. Handler-specific tools are resolved from `_handler_callable` registry entries against adjacent step runtime configuration.

## Content Authoring Formats

For normal prose, AI tools should write markdown and omit `content_format` unless a workflow explicitly asks for HTML or serialized blocks. Raw ability/API callers can still pass `content_format`; omitted raw `datamachine/upsert-post` calls keep the legacy block-markup default.

## Directory Reference

| Directory | Contents |
| --- | --- |
| `inc/Engine/AI/Tools/Global/` | Global tools such as memory, web fetch, queue validation, and `consult_agent`. |
| `inc/Engine/AI/Tools/` | Tool registration, policy resolution, execution, and shared base classes. |
| `inc/Abilities/Analytics/` | Analytics ability implementations used by analytics tools. |
