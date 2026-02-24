# WordPress as Persistent Memory for AI Agents

AI agents are stateless. Every conversation, every workflow, every scheduled task starts from zero. The agent has no memory of who it is, what it's done, or what it should care about — unless you give it one.

Data Machine uses **WordPress itself as the memory layer.** Not a vector database. Not a separate memory service. The agent's memory lives in the same WordPress installation it manages — files on disk, conversations in the database, context assembled at request time.

## Why WordPress Works

WordPress already solves persistent storage:

- **Files on disk** — `wp-content/uploads` for markdown documents the agent reads
- **MySQL** — custom tables for chat sessions and job history
- **REST API** — programmatic CRUD for memory files
- **Admin UI** — human-editable through the WordPress dashboard
- **Action Scheduler** — cron-like cleanup and scheduled workflows
- **Hooks system** — extensible injection points for custom memory sources

No separate infrastructure. The memory lives where the content lives.

## Memory Architecture

Three layers, each serving a different purpose:

### 1. Agent Files — Identity and Knowledge

**Location:** `wp-content/uploads/datamachine-files/agent/`

Markdown files stored on the WordPress filesystem. The agent reads these to know who it is, who it works with, and what it knows.

Data Machine ships with three core memory files, each with a distinct purpose:

**SOUL.md** — Agent identity. Who the agent is, how it communicates, what rules it follows. Injected into every AI request. Rarely changes.

**USER.md** — Information about the human. Timezone, preferences, communication style, background. Created on activation with a starter template. Injected into every AI request.

**MEMORY.md** — Accumulated knowledge. Facts, decisions, lessons learned, project state. Grows and changes over time as the agent learns. Injected into every AI request.

All three are loaded by the **MemoryFileRegistry** and injected as a group via the **CoreMemoryFilesDirective** at Priority 20. They are protected from deletion — clear contents instead of deleting.

**Additional files** serve workflow-specific purposes — editorial strategies, project briefs, content plans. Each pipeline can select which additional files it needs, so a social media workflow doesn't carry the weight of a full content strategy. These are injected at Priority 40 (per-pipeline).

**Technical details:**
- Protected by `index.php` silence files (standard WordPress pattern)
- CRUD via REST API: `GET/PUT/DELETE /datamachine/v1/files/agent/{filename}`
- Editable through the WordPress admin Agent page
- No serialization — plain markdown, human-readable, git-friendly
- All three core files created on activation with starter templates

### 2. Chat Sessions — Conversation Memory

**Storage:** Custom MySQL table `{prefix}_datamachine_chat_sessions`

Full conversation history persisted in the database. Sessions survive page reloads and browser restarts. Configurable retention (default 90 days) with automatic cleanup via Action Scheduler.

### 3. Pipeline Context — Workflow Memory

**Location:** `wp-content/uploads/datamachine-files/pipeline-{id}/context/`

Per-pipeline documents that provide background for specific workflows. Job execution data stored as JSON creates an audit trail of what was processed — transient working memory cleaned by retention policies.

## Core Memory Files

The difference between useful memory and noise is structure. Each core file has a specific job.

### SOUL.md — Who the Agent Is

SOUL.md is **identity, not knowledge.** It should contain things that are true about the agent regardless of what it's working on:

- **Identity** — name, role, what site it manages
- **Voice and tone** — how it communicates
- **Rules** — behavioral constraints (what it must/must not do)
- **Context** — background about the domain and audience

SOUL.md should be **stable.** If you're editing it frequently, the content probably belongs in MEMORY.md instead.

**Good SOUL.md content:**
```markdown
## Identity
I am the voice of example.com — a music and culture publication.

## Rules
- Follow AP style for articles
- Never publish without a featured image
- Ask for clarification when topic scope is ambiguous
```

**Bad SOUL.md content:**
```markdown
## Current Tasks
- Finish the interview draft by Friday
- Pinterest pins are underperforming — try new formats
```

That's memory, not identity. Put it in MEMORY.md.

### USER.md — Who the Human Is

USER.md holds **information about the human the agent works with.** This is separate from agent identity and agent knowledge because it serves a different purpose — it helps the agent adapt to its user.

- **Timezone and location** — so the agent knows when to schedule things
- **Communication preferences** — concise vs detailed, formal vs casual
- **Background** — relevant context about the human's expertise or role
- **Working patterns** — night owl, prefers async, etc.

Created on activation with a starter template, same as SOUL.md and MEMORY.md.

```markdown
# User

## About
<!-- Name, timezone, location, background -->

## Preferences
<!-- Communication style, update format, decision-making approach -->

## Working Patterns
<!-- Schedule, availability, things the agent should know about how you work -->
```

### MEMORY.md — What the Agent Knows

MEMORY.md is the agent's **accumulated knowledge** — facts, decisions, lessons, context that builds up over time. Structure it for scanability:

- **Use clear section headers** — the agent needs to find relevant info quickly
- **Be factual, not narrative** — bullet points over paragraphs
- **Date important decisions** — "Switched to weekly publishing (2026-02-15)" is more useful than "We publish weekly"
- **Prune stale info** — remove things that are no longer true

**Recommended structure:**
```markdown
# Agent Memory

## State
- Content calendar migration — in progress
- SEO audit — completed 2026-02-20

## Site Knowledge
- WordPress at /var/www/example.com
- Custom theme: flavor
- Docs plugin: flavor-docs v0.9.11

## Lessons Learned
- WP-CLI needs --allow-root on this server
- Image uploads fail above 5MB — server limit
- Category "Reviews" has ID 14
```

MEMORY.md supports **section-based operations** via the AgentMemory service and WordPress Abilities API:

```bash
# Read a specific section
wp_execute_ability('datamachine/get-agent-memory', ['section' => 'Lessons Learned'])

# Append to a section
wp_execute_ability('datamachine/update-agent-memory', [
    'section' => 'Lessons Learned',
    'content' => '- New fact the agent learned',
    'mode'    => 'append',
])

# List all sections
wp_execute_ability('datamachine/list-agent-memory-sections')
```

This allows agents to surgically update specific sections of memory without rewriting the entire file.

### When to Create Additional Files

Create a new file when a body of knowledge is:

1. **Large enough to be distracting** — if a section of MEMORY.md is 50+ lines and only relevant to one workflow, split it out
2. **Workflow-specific** — a content strategy doc only matters to content pipelines, not maintenance tasks
3. **Frequently updated independently** — if one person updates the editorial brief while another maintains site knowledge, separate them

**Naming conventions:**
- Lowercase with hyphens: `content-strategy.md`, `seo-guidelines.md`
- Be descriptive: `content-briefing.md` is better than `notes.md`
- Core files (SOUL.md, USER.md, MEMORY.md) are uppercase by convention — additional files are lowercase

### File Size Awareness

Agent memory files are injected as system messages. Every token counts against the context window.

- **SOUL.md**: Keep under 500 words. Identity should be concise.
- **USER.md**: Keep under 300 words. Key facts about the human.
- **MEMORY.md**: Aim for under 2,000 words. Prune aggressively.
- **Additional files**: Keep focused. A 5,000-word strategy doc injected into a simple social media pipeline is wasteful.

If a file grows unwieldy, that's a signal to split it or prune it.

## How Memory Gets Into AI Prompts

Data Machine uses a **directive system** — a priority-ordered chain that injects context into every AI request. Priorities are spaced by 10 to allow future additions without rebasing.

| Priority | Directive | Scope | What It Injects |
|----------|-----------|-------|-----------------|
| 10 | Plugin Core | All | Base Data Machine identity |
| **20** | **Core Memory Files** | **All** | **SOUL.md, USER.md, MEMORY.md (via registry)** |
| 40 | Pipeline Memory Files | Pipeline | Per-pipeline selected additional files |
| 50 | Pipeline System Prompt | Pipeline | Workflow instructions |
| 60 | Pipeline Context Files | Pipeline | Uploaded reference materials |
| 70 | Tool Definitions | All | Available tools and schemas |
| 80 | Site Context | All | WordPress metadata |

### Core Memory Files (Priority 20)

The **CoreMemoryFilesDirective** reads all files registered in the **MemoryFileRegistry** and injects them as system messages. The registry is a pure container — no hardcoded files. Everything registers through the same public API:

```php
// Default registrations in bootstrap.php
MemoryFileRegistry::register( 'SOUL.md', 10 );
MemoryFileRegistry::register( 'USER.md', 20 );
MemoryFileRegistry::register( 'MEMORY.md', 30 );
```

The priority number within the registry determines **load order** (SOUL.md first, then USER.md, then MEMORY.md). Missing files are silently skipped. Empty files are silently skipped.

Plugins and themes can register their own memory files through the same API:

```php
// A theme adding its own context file
MemoryFileRegistry::register( 'brand-guidelines.md', 40 );

// Or deregister a default
MemoryFileRegistry::deregister( 'USER.md' );
```

### Pipeline Memory Files (Priority 40)

Each pipeline can select additional agent files beyond the core set. Configure via the "Agent Memory Files" section in the pipeline settings UI. Core files (SOUL.md, USER.md, MEMORY.md) are excluded from the picker since they're always injected at Priority 20.

```
"Daily Music News"    -> [content-strategy.md]
"Social Media Posts"  -> []
"Album Reviews"       -> [content-strategy.md, content-briefing.md]
```

Different workflows access different slices of knowledge. This is deliberate — selective memory injection over RAG means you know exactly what context the agent has, with no embedding cost and no hallucination from irrelevant similarity matches.

## External Agent Integration

Not every agent runs inside Data Machine's pipeline or chat system. An agent might be a CLI tool, a Discord bot, or a standalone script that uses the WordPress site as its memory backend.

### Reading Memory via AGENTS.md

Agents that operate on the server (like Claude Code via Kimaki) can read memory files directly from disk and inject them into their own session context. A common pattern is an `AGENTS.md` file in the site root that includes the contents of SOUL.md, USER.md, and MEMORY.md at session startup:

```
AGENTS.md (at site root)
  ├── includes SOUL.md content   (who the agent is)
  ├── includes USER.md content   (who the human is)
  └── includes MEMORY.md content (what the agent knows)
```

The agent wakes up with identity, user context, and knowledge already loaded. Updates to the files on disk take effect on the next session — no deployment needed.

### Reading Memory via REST API

Remote agents can read and write memory files over HTTP:

```bash
# Read MEMORY.md
curl -s https://example.com/wp-json/datamachine/v1/files/agent/MEMORY.md \
  -H "Authorization: Bearer $TOKEN"

# Update MEMORY.md
curl -X PUT https://example.com/wp-json/datamachine/v1/files/agent/MEMORY.md \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: text/plain" \
  --data-binary @MEMORY.md
```

This makes WordPress the single source of truth for agent memory, regardless of where the agent runs.

### Reading Memory via WP-CLI

Agents with shell access can read files directly:

```bash
# Read directly from disk
cat wp-content/uploads/datamachine-files/agent/MEMORY.md

# List all agent files
ls wp-content/uploads/datamachine-files/agent/
```

### The Key Principle

However the agent consumes memory — directives, AGENTS.md injection, REST API, direct file read — the **files on disk are the source of truth.** All paths lead to the same markdown documents in `wp-content/uploads/datamachine-files/agent/`.

## Memory Maintenance

Memory degrades if you never maintain it. Agent files need periodic attention.

### Review Cadence

- **SOUL.md**: Review quarterly. Identity shouldn't change often, but verify rules and context are still accurate.
- **USER.md**: Review when circumstances change. New timezone, new preferences, new role.
- **MEMORY.md**: Review monthly. Remove stale info, consolidate duplicate entries, update facts that have changed.
- **Workflow files**: Review when the workflow changes. A content strategy from six months ago may be actively misleading.

### Signs Memory Needs Attention

- The agent keeps making the same mistake → missing or incorrect info in memory
- MEMORY.md exceeds 2,000 words → time to prune or split
- The agent references outdated facts → stale entries need removal
- A pipeline behaves inconsistently → check which memory files are attached

### Who Maintains Memory

Both humans and agents can update memory files:

- **Humans** edit via the WordPress admin Agent page or any text editor with server access
- **Agents** update via REST API, Abilities API, or direct file write during workflows
- **Pipelines** can include memory-update steps that append learned information

The most effective pattern is **agent writes, human reviews** — the agent appends what it learns, and the human periodically curates for accuracy and relevance.

## Memory Lifecycle

### Creation

- **SOUL.md, USER.md, MEMORY.md**: Created on plugin activation with starter templates. Existing files are never overwritten.
- **Additional files**: Created via REST API, admin UI, or by the agent itself
- **Chat sessions**: Created on first message in a conversation
- **Job data**: Created during pipeline execution

### Updates

- **Agent files**: Updated via REST API, Abilities API, or admin UI. Changes take effect on the next AI request — no restart needed.
- **Chat sessions**: Grow with each message exchange
- **Job data**: Accumulated during multi-step execution

### Cleanup

- **Chat sessions**: Retention-based (default 90 days) via Action Scheduler
- **Orphaned sessions**: Auto-cleaned after 1 hour if empty
- **Job data**: Cleaned by FileCleanup based on retention policies
- **Agent files**: Manual only — no auto-cleanup

## Design Decisions

### Files over database

Agent memory is stored as **files on disk**, not in `wp_options` or custom tables. Files are human-readable, git-friendly, have no serialization overhead, and match the mental model of "documents the agent reads."

### Registry over hardcoded directives

Core memory files register through the same `MemoryFileRegistry` API that plugins and themes use. Nothing is special-cased — SOUL.md, USER.md, and MEMORY.md are just the default registrations. This makes the system extensible without modifying core code.

### Selective injection over RAG

Each pipeline explicitly selects which additional memory files it needs. No embeddings, no similarity search. This is deterministic (you know exactly what context the agent has), simple to debug, and appropriate for the scale — agent memory is typically kilobytes, not gigabytes.

### WordPress uploads over custom storage

Files live in `wp-content/uploads/datamachine-files/agent/`. WordPress backup tools include them automatically, standard permissions apply, and no custom mount configuration is needed.

## REST API Reference

### Agent Files

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/datamachine/v1/files/agent` | List all agent files |
| `GET` | `/datamachine/v1/files/agent/{filename}` | Get file content |
| `PUT` | `/datamachine/v1/files/agent/{filename}` | Create or update (raw body = content) |
| `DELETE` | `/datamachine/v1/files/agent/{filename}` | Delete file (blocked for SOUL.md, MEMORY.md) |

### Flow Files

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/datamachine/v1/files?flow_step_id={id}` | List flow files |
| `POST` | `/datamachine/v1/files` | Upload file (multipart form) |
| `DELETE` | `/datamachine/v1/files/{filename}?flow_step_id={id}` | Delete flow file |

All endpoints require `manage_options` capability.

### Agent Memory Abilities (WordPress 6.9+)

| Ability | Description |
|---------|-------------|
| `datamachine/get-agent-memory` | Read full file or a specific section |
| `datamachine/update-agent-memory` | Set or append to a section |
| `datamachine/list-agent-memory-sections` | List all `##` section headers |

## Extending the Memory System

### Register Custom Memory Files

Add files to the core injection (Priority 20) via the registry:

```php
use DataMachine\Engine\AI\MemoryFileRegistry;

// Register a file to be injected into all AI calls
MemoryFileRegistry::register( 'brand-guidelines.md', 40 );
```

The file must exist in `wp-content/uploads/datamachine-files/agent/`. Missing files are silently skipped.

### Custom Directives

Register a directive to inject memory from non-file sources:

```php
add_filter('datamachine_directives', function($directives) {
    $directives[] = [
        'class'       => 'MyPlugin\Directives\CustomMemory',
        'priority'    => 25, // Between core memory files (20) and pipeline memory (40)
        'agent_types' => ['pipeline'],
    ];
    return $directives;
});
```

The directive class implements `DirectiveInterface` and returns system messages:

```php
class CustomMemory implements \DataMachine\Engine\AI\Directives\DirectiveInterface {
    public static function get_outputs(
        string $provider_name,
        array $tools,
        ?string $step_id = null,
        array $payload = []
    ): array {
        return [
            [
                'type'    => 'system_text',
                'content' => 'Custom memory content here',
            ],
        ];
    }
}
```

### Custom Site Context

Extend what the agent knows about the site:

```php
add_filter('datamachine_site_context', function($context) {
    $context['inventory'] = get_product_inventory_summary();
    return $context;
});
```
