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

Markdown files stored on the WordPress filesystem. The agent reads these to know who it is and what it knows.

**SOUL.md** is the core file — injected into every AI request the agent makes. It defines identity, voice, rules, and context. Think of it as a persistent system prompt that humans can edit.

**MEMORY.md** is the knowledge file — accumulated facts, decisions, lessons learned. Unlike SOUL.md (which rarely changes), MEMORY.md grows over time as the agent learns.

**Additional files** serve specific purposes — editorial strategies, project briefs, content plans. Each pipeline can select which files it needs, so a social media workflow doesn't carry the weight of a full content strategy.

**Technical details:**
- Protected by `index.php` silence files (standard WordPress pattern)
- CRUD via REST API: `GET/PUT/DELETE /datamachine/v1/files/agent/{filename}`
- Editable through the WordPress admin Agent page
- No serialization — plain markdown, human-readable, git-friendly

### 2. Chat Sessions — Conversation Memory

**Storage:** Custom MySQL table `{prefix}_datamachine_chat_sessions`

Full conversation history persisted in the database. Sessions survive page reloads and browser restarts. Configurable retention (default 90 days) with automatic cleanup via Action Scheduler.

### 3. Pipeline Context — Workflow Memory

**Location:** `wp-content/uploads/datamachine-files/pipeline-{id}/context/`

Per-pipeline documents that provide background for specific workflows. Job execution data stored as JSON creates an audit trail of what was processed — transient working memory cleaned by retention policies.

## Structuring Agent Memory

The difference between useful memory and noise is structure. Here's how to think about each file.

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

### MEMORY.md — What the Agent Knows

MEMORY.md is the agent's **accumulated knowledge** — facts, decisions, lessons, context that builds up over time. Structure it for scanability:

- **Use clear section headers** — the agent needs to find relevant info quickly
- **Be factual, not narrative** — bullet points over paragraphs
- **Date important decisions** — "Switched to weekly publishing (2026-02-15)" is more useful than "We publish weekly"
- **Prune stale info** — remove things that are no longer true

**Recommended structure:**
```markdown
# Agent Memory

## Site Knowledge
- WordPress at /var/www/example.com
- Custom theme: flavor
- Docs plugin: flavor-docs v0.9.11

## Active Projects
- Content calendar migration — in progress
- SEO audit — completed 2026-02-20

## Lessons Learned
- WP-CLI needs --allow-root on this server
- Image uploads fail above 5MB — server limit
- Category "Reviews" has ID 14

## About the Human
- Timezone: Central (Austin, TX)
- Prefers concise updates over detailed reports
```

### When to Create Additional Files

Create a new file when a body of knowledge is:

1. **Large enough to be distracting** — if a section of MEMORY.md is 50+ lines and only relevant to one workflow, split it out
2. **Workflow-specific** — a content strategy doc only matters to content pipelines, not maintenance tasks
3. **Frequently updated independently** — if one person updates the editorial brief while another maintains site knowledge, separate them

**Naming conventions:**
- Lowercase with hyphens: `content-strategy.md`, `seo-guidelines.md`
- Be descriptive: `content-briefing.md` is better than `notes.md`
- SOUL.md and MEMORY.md are uppercase by convention — additional files are lowercase

### File Size Awareness

Agent memory files are injected as system messages. Every token counts against the context window.

- **SOUL.md**: Keep under 500 words. Identity should be concise.
- **MEMORY.md**: Aim for under 2,000 words. Prune aggressively.
- **Additional files**: Keep focused. A 5,000-word strategy doc injected into a simple social media pipeline is wasteful.

If a file grows unwieldy, that's a signal to split it or prune it.

## How Memory Gets Into AI Prompts

Data Machine uses a **directive system** — a priority-ordered chain that injects context into every AI request:

| Priority | Directive | Scope | What It Injects |
|----------|-----------|-------|-----------------|
| 10 | Plugin Core | All | Base Data Machine identity |
| 15 | Chat Agent | Chat | Chat-specific capabilities |
| **20** | **SOUL.md** | **All** | **Agent identity** |
| **25** | **Memory Files** | **Pipeline** | **Selected agent files** |
| 30 | Pipeline Prompt | Pipeline | Workflow instructions |
| 40 | Tools | All | Available tools and schemas |
| 45 | Pipeline Inventory | Chat | Pipeline discovery context |
| 50 | Site Context | All | WordPress metadata |

**SOUL.md (Priority 20)** is always injected. Every agent type gets identity.

**Memory Files (Priority 25)** are selectively injected per-pipeline:

```
"Daily Music News"    -> [MEMORY.md, content-strategy.md]
"Social Media Posts"  -> [MEMORY.md]
"Album Reviews"       -> [MEMORY.md, content-strategy.md, content-briefing.md]
```

Different workflows access different slices of knowledge. This is deliberate — selective memory injection over RAG means you know exactly what context the agent has, with no embedding cost and no hallucination from irrelevant similarity matches.

## External Agent Integration

Not every agent runs inside Data Machine's pipeline or chat system. An agent might be a CLI tool, a Discord bot, or a standalone script that uses the WordPress site as its memory backend.

### Reading Memory via AGENTS.md

Agents that operate on the server (like Claude Code via Kimaki) can read memory files directly from disk and inject them into their own session context. A common pattern is an `AGENTS.md` file in the site root that includes the contents of SOUL.md and MEMORY.md at session startup:

```
AGENTS.md (at site root)
  └── includes SOUL.md content   (who the agent is)
  └── includes MEMORY.md content (what it knows)
```

The agent wakes up with identity and knowledge already loaded. Updates to the files on disk take effect on the next session — no deployment needed.

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

Agents with shell access can use WP-CLI or read files directly:

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
- **Agents** update via REST API or direct file write during workflows
- **Pipelines** can include memory-update steps that append learned information

The most effective pattern is **agent writes, human reviews** — the agent appends what it learns, and the human periodically curates for accuracy and relevance.

## Memory Lifecycle

### Creation

- **SOUL.md**: Created on plugin activation (from template or migrated from legacy settings)
- **Agent files**: Created via REST API, admin UI, or by the agent itself
- **Chat sessions**: Created on first message in a conversation
- **Job data**: Created during pipeline execution

### Updates

- **Agent files**: Updated via REST API or admin UI. Changes take effect on the next AI request — no restart needed.
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

### Selective injection over RAG

Each pipeline explicitly selects which memory files it needs. No embeddings, no similarity search. This is deterministic (you know exactly what context the agent has), simple to debug, and appropriate for the scale — agent memory is typically kilobytes, not gigabytes.

### WordPress uploads over custom storage

Files live in `wp-content/uploads/datamachine-files/agent/`. WordPress backup tools include them automatically, standard permissions apply, and no custom mount configuration is needed.

## REST API Reference

### Agent Files

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/datamachine/v1/files/agent` | List all agent files |
| `GET` | `/datamachine/v1/files/agent/{filename}` | Get file content |
| `PUT` | `/datamachine/v1/files/agent/{filename}` | Create or update (raw body = content) |
| `DELETE` | `/datamachine/v1/files/agent/{filename}` | Delete file |

### Flow Files

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/datamachine/v1/files?flow_step_id={id}` | List flow files |
| `POST` | `/datamachine/v1/files` | Upload file (multipart form) |
| `DELETE` | `/datamachine/v1/files/{filename}?flow_step_id={id}` | Delete flow file |

All endpoints require `manage_options` capability.

## Extending the Memory System

### Custom Directives

Register a new directive to inject custom memory sources:

```php
add_filter('datamachine_directives', function($directives) {
    $directives[] = [
        'class'       => 'MyPlugin\Directives\CustomMemory',
        'priority'    => 22,
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
