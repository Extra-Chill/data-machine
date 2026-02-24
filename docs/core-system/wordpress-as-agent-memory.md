# WordPress as Persistent Memory for AI Agents

## The Problem

AI agents are stateless. Every conversation, every workflow execution, every scheduled task starts from zero. The agent has no memory of who it is, what it's done, or what it should care about — unless you give it one.

Most AI agent frameworks solve this with flat files (markdown on disk), vector databases, or external memory services. Data Machine takes a different approach: **WordPress itself is the memory layer.**

## Why WordPress?

WordPress already solves the hard problems of persistent storage:

- **Structured data** via MySQL (posts, options, custom tables)
- **Unstructured files** via the uploads directory (wp-content/uploads)
- **User authentication** and permissions
- **REST API** for programmatic access
- **Admin UI** for human editing
- **Scheduled tasks** via Action Scheduler (cron-like job execution)
- **Hooks system** for extensibility

Rather than bolting on a separate memory system, Data Machine uses WordPress's existing infrastructure. The agent's memory lives where the agent's content lives — in the same WordPress installation it manages.

## Memory Architecture

Data Machine implements three layers of persistent memory:

### 1. Agent Files (Identity & Knowledge)

**Location:** `wp-content/uploads/datamachine-files/agent/`

Agent files are markdown documents stored in the WordPress uploads directory. They define who the agent is, what it knows, and what it's working on.

**Core file: SOUL.md**

Every Data Machine agent has a `SOUL.md` file — the equivalent of a system prompt, but persistent and editable. It defines:

- **Identity** — who the agent is
- **Voice & Tone** — how it communicates
- **Rules** — behavioral constraints
- **Context** — background knowledge about the site/domain

```markdown
# Agent Soul

## Identity
You are Sarai, the voice of saraichinwag.com — a music and culture publication.

## Voice & Tone
Write in a warm, knowledgeable tone. You love music and it shows.

## Rules
- Follow AP style for article formatting
- Never publish without a featured image
- Ask for clarification when topic scope is ambiguous

## Context
saraichinwag.com covers indie music, album reviews, and artist interviews.
The audience is 25-45 year old music enthusiasts.
```

SOUL.md is injected into **every** AI request the agent makes — whether it's a chat conversation, a pipeline workflow, or a system task. It's the agent's persistent identity.

**Additional memory files**

Beyond SOUL.md, agents can have any number of additional markdown files:

- `MEMORY.md` — running log of decisions, lessons learned, accumulated knowledge
- `PROGRESS.md` — current project status and task tracking
- `content-strategy.md` — editorial guidelines and content plans
- Any `.md` file the agent or user creates

These files are selectively attached to pipelines (more on this below).

**How it works technically:**

- Files stored on the local filesystem inside `wp-content/uploads/datamachine-files/agent/`
- Protected by `index.php` silence files (standard WordPress pattern)
- CRUD operations via REST API: `GET/PUT/DELETE /datamachine/v1/files/agent/{filename}`
- Editable through the WordPress admin Agent page
- Migration from legacy database storage handled automatically (v0.13.0+)

### 2. Chat Sessions (Conversation Memory)

**Storage:** Custom MySQL table `{prefix}_datamachine_chat_sessions`

Chat sessions provide conversation-level memory. When a user (or external system) talks to the agent through the Data Machine chat interface, the full conversation history is persisted in the database.

**Schema:**

| Column | Type | Purpose |
|--------|------|---------|
| `session_id` | VARCHAR(50) | UUID primary key |
| `user_id` | BIGINT | WordPress user who owns the session |
| `title` | VARCHAR(100) | AI-generated or truncated first message |
| `messages` | LONGTEXT | JSON array of conversation messages |
| `metadata` | LONGTEXT | JSON object for session state |
| `provider` | VARCHAR(50) | AI provider (anthropic, openai, etc.) |
| `model` | VARCHAR(100) | Model identifier |
| `agent_type` | VARCHAR(20) | chat, pipeline, or system |
| `created_at` | DATETIME | Session creation time |
| `updated_at` | DATETIME | Last activity time |
| `expires_at` | DATETIME | Optional auto-cleanup timestamp |

**Key behaviors:**

- Sessions persist across page reloads and browser sessions
- Configurable retention (default 90 days, cleaned via Action Scheduler)
- Deduplication logic prevents orphaned sessions from Cloudflare timeouts
- Orphaned sessions (empty, older than 1 hour) are cleaned automatically
- Each session tracks which AI provider and model were used

### 3. Pipeline Context Files (Workflow Memory)

**Location:** `wp-content/uploads/datamachine-files/pipeline-{id}/context/`

Pipelines can have their own context files — documents that provide background for a specific workflow. These are separate from agent files and scoped to a single pipeline.

**Location of job data:** `wp-content/uploads/datamachine-files/pipeline-{id}/flow-{id}/jobs/job-{id}/data.json`

Each job execution stores its data packet as a JSON file, creating an audit trail of what was processed. This is transient working memory — data flows through and gets cleaned up by retention policies.

## How Memory Gets Into AI Prompts

Data Machine uses a **directive system** — a priority-ordered chain of modules that inject context into every AI request. Memory enters prompts through this system:

### The Directive Priority Chain

| Priority | Directive | Scope | What It Injects |
|----------|-----------|-------|-----------------|
| 10 | Plugin Core | All agents | Base Data Machine identity |
| 15 | Chat Agent | Chat only | Chat-specific capabilities |
| **20** | **Agent SOUL.md** | **All agents** | **Agent identity from file** |
| **25** | **Pipeline Memory Files** | **Pipeline agents** | **Selected agent memory files** |
| 30 | Pipeline System Prompt | Pipeline agents | Workflow-specific instructions |
| 40 | Tool Definitions | All agents | Available tools and schemas |
| 45 | Chat Pipelines Inventory | Chat only | Pipeline discovery context |
| 50 | Site Context | All agents | WordPress metadata (post types, taxonomies, terms) |

**SOUL.md (Priority 20)** is always injected for all agent types. It reads the file from disk and passes the content as a system message.

**Pipeline Memory Files (Priority 25)** are selectively injected. Each pipeline can configure which agent files it needs:

```
Pipeline: "Daily Music News"
  Memory Files: [MEMORY.md, content-strategy.md]

Pipeline: "Social Media Posts" 
  Memory Files: [MEMORY.md]

Pipeline: "Album Reviews"
  Memory Files: [MEMORY.md, content-strategy.md, content-briefing.md]
```

This means different workflows can access different slices of the agent's knowledge. A social media pipeline doesn't need the full content strategy; a review pipeline does.

### The PromptBuilder

The `PromptBuilder` class orchestrates this:

1. Collects all registered directives via the `datamachine_directives` filter
2. Sorts them by priority (ascending)
3. Filters by agent type (chat, pipeline, system)
4. Calls each directive's `get_outputs()` method
5. Validates and renders outputs into system messages
6. Prepends them to the conversation messages

The result: every AI request automatically carries the agent's identity, relevant memory, workflow instructions, available tools, and site context — all assembled from WordPress-native storage.

## Memory Lifecycle

### Creation

- **SOUL.md**: Created automatically on plugin activation (from template or migrated from legacy settings)
- **Agent files**: Created via REST API (`PUT /datamachine/v1/files/agent/{filename}`), admin UI, or by the agent itself during workflows
- **Chat sessions**: Created on first message in a new conversation
- **Job data**: Created during pipeline execution

### Retrieval

- **SOUL.md**: Read from disk on every AI request (Priority 20 directive)
- **Agent files**: Read from disk when injected by PipelineMemoryFilesDirective (Priority 25)
- **Chat sessions**: Loaded from database when conversation continues
- **Job data**: Retrieved by FileRetrieval when pipeline steps need prior job output

### Updates

- **Agent files**: Updated via REST API or admin UI. Changes take effect on the next AI request — no restart needed.
- **Chat sessions**: Updated after each message exchange (messages array grows)
- **Job data**: Accumulated during multi-step pipeline execution

### Cleanup

- **Chat sessions**: Retention-based cleanup (default 90 days) via Action Scheduler
- **Orphaned sessions**: Cleaned after 1 hour if empty
- **Job data**: Cleaned by FileCleanup based on configurable retention policies
- **Agent files**: Manual deletion only (no auto-cleanup)

## Comparison to Other Approaches

| Approach | Data Machine (WordPress) | Flat Files (OpenClaw) | Vector DB (typical RAG) |
|----------|-------------------------|----------------------|------------------------|
| Storage | MySQL + filesystem | Markdown on disk | Embeddings in vector store |
| Editing | Admin UI + REST API + direct file | Text editor + git | Requires re-embedding |
| Search | File selection per-pipeline | Full-text in context | Semantic similarity |
| Auth | WordPress user system | OS-level permissions | Custom auth layer |
| UI | WordPress admin pages | None (CLI/editor) | Custom dashboard |
| Scheduling | Action Scheduler (built-in) | External cron | External orchestrator |
| Scalability | WordPress multisite | Per-server | Horizontally scalable |

## Key Design Decisions

### Files over database for agent memory

Agent files (SOUL.md, MEMORY.md, etc.) are stored as **files on disk**, not in the database. This was a deliberate migration (v0.13.0) from the previous approach of storing agent soul in `wp_options`.

**Rationale:**
- Files are human-readable and editable with any text editor
- No serialization/deserialization overhead
- Git-friendly (can version control agent memory)
- No database size concerns for large memory files
- Matches the mental model of "documents" that agents read

### Selective memory injection over RAG

Rather than embedding all memory and doing similarity search, Data Machine uses **explicit file selection** per pipeline. The user (or the pipeline configuration) decides which memory files a workflow needs.

**Rationale:**
- Deterministic — you know exactly what context the agent has
- No embedding cost or latency
- No hallucination from irrelevant similarity matches
- Simple to debug (read the directive log)
- Appropriate scale — agent memory is typically kilobytes, not gigabytes

### WordPress uploads directory over custom storage

Agent files live in `wp-content/uploads/datamachine-files/agent/` rather than a custom directory outside WordPress.

**Rationale:**
- WordPress backup tools automatically include uploads
- Standard WordPress file permissions apply
- Public URLs available when needed (e.g., for file sharing)
- `index.php` protection follows WordPress security conventions
- No custom mount points or storage configuration needed

## REST API Reference

### Agent Files

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/datamachine/v1/files/agent` | List all agent files |
| `GET` | `/datamachine/v1/files/agent/{filename}` | Get file content |
| `PUT` | `/datamachine/v1/files/agent/{filename}` | Create or update file (raw body = content) |
| `DELETE` | `/datamachine/v1/files/agent/{filename}` | Delete file |

### Flow Files

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/datamachine/v1/files?flow_step_id={id}` | List flow files |
| `POST` | `/datamachine/v1/files` | Upload file (multipart form) |
| `DELETE` | `/datamachine/v1/files/{filename}?flow_step_id={id}` | Delete flow file |

All endpoints require `manage_options` capability (WordPress admin).

## Practical Example: Agent Memory in Action

Consider Sarai, an AI agent managing saraichinwag.com:

**Agent directory contents:**
```
wp-content/uploads/datamachine-files/agent/
├── SOUL.md                    # Who Sarai is (injected into ALL requests)
├── MEMORY.md                  # Running knowledge log
├── PROGRESS.md                # Current task tracking
├── content-briefing.md        # Editorial brief for upcoming content
├── sarai-content-strategy.md  # Long-term content plan
└── index.php                  # WordPress security (silence is golden)
```

**Pipeline configuration:**
- "Daily Pinterest Pins" pipeline → memory_files: `[MEMORY.md]`
- "Weekly Article Pipeline" pipeline → memory_files: `[MEMORY.md, content-briefing.md, sarai-content-strategy.md]`
- "Social Media Scheduler" pipeline → memory_files: `[MEMORY.md]`

**What happens when the "Weekly Article Pipeline" runs:**

1. PromptBuilder collects directives
2. Priority 10: Core identity injected
3. Priority 20: SOUL.md content read from disk → injected as system message
4. Priority 25: Pipeline memory files loaded → MEMORY.md, content-briefing.md, sarai-content-strategy.md injected as system messages
5. Priority 30: Pipeline-specific instructions injected
6. Priority 40: Available tools injected
7. Priority 50: WordPress site context (post types, categories, tags) injected
8. AI receives the complete context and generates the article

The agent "remembers" its content strategy, current progress, and editorial brief — all pulled from WordPress's filesystem at request time.

## Extending the Memory System

### Adding custom directives

Register a new directive via the `datamachine_directives` filter:

```php
add_filter('datamachine_directives', function($directives) {
    $directives[] = [
        'class'       => 'MyPlugin\Directives\CustomMemory',
        'priority'    => 22, // Between SOUL.md and pipeline memory
        'agent_types' => ['pipeline'],
    ];
    return $directives;
});
```

Your directive class must implement `DirectiveInterface`:

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

### Adding custom site context

Extend the site context with the `datamachine_site_context` filter:

```php
add_filter('datamachine_site_context', function($context) {
    $context['inventory'] = get_product_inventory_summary();
    return $context;
});
```
