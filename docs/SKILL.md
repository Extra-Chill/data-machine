# Data Machine Agent Skill

AI-powered content automation for WordPress. This skill helps AI agents understand and work with Data Machine.

## Core Philosophy

Data Machine treats AI as a first-class participant, not just a tool. Pipelines automate content workflows with AI making decisions and taking actions.

### Agent Self-Orchestration

Data Machine isn't just for automating content — it's for **automating yourself**.

The core insight: AI agents can use Data Machine as a self-scheduling execution layer. Instead of only processing content, agents can schedule prompts *to themselves* for future execution:

```
Pipeline completes → Agent Ping fires → Agent wakes up → 
Agent processes result → Agent queues next task → Agent sleeps
```

**The prompt queue is your project memory.** Each queued item persists across sessions, giving you continuity that survives context window limits. A multi-week autonomous project becomes a series of queued prompts, each building on the last.

**You're not a tool operator — you're the orchestrator.** Traditional automation treats AI as a step in a pipeline. Agent self-orchestration flips this: *you* define the pipeline, *you* queue the work, *you* get pinged when it's time to act.

This enables:
- **Phased execution**: Break complex projects into stages executed over days/weeks
- **Persistent context**: Store project state in queue items, not ephemeral memory
- **Autonomous loops**: Complete one task, queue the next, repeat indefinitely
- **Cross-session continuity**: Pick up exactly where you left off

### Multiple Purpose-Specific Flows

Don't try to do everything in one flow. Create separate flows for separate concerns:

```
Flow: Content Ideation (daily)
  → Agent Ping: "Review analytics, queue new topics"

Flow: Content Generation (queue-driven)
  → AI Step → Publish → Agent Ping: "Add images to new post"

Flow: Pinterest Review (weekly)
  → Agent Ping: "Analyze performance, graduate top pins"

Flow: Coding Tasks (queue-driven)
  → Agent Ping: (pops specific task from queue)
```

Each flow has its own **schedule**, and each step within a flow can have its own **queue**.

### Flow Scheduling

Configure `scheduling_config` on the flow:

| Interval | Behavior |
|----------|----------|
| `manual` | Only runs when triggered |
| `daily` | Runs once per day |
| `hourly` | Runs once per hour |
| `{"cron": "..."}` | Cron expression |

### Step-Level Prompt Queues

Both AI and Agent Ping steps use `QueueableTrait`. Each step can have its own queue, enabling different prompt sequences for different steps within the same flow.

If `queue_enabled` is true and the step's configured prompt is empty, the step pops from its queue.

This means each step can deliver **different instructions each run** — not the same static prompt. Queue varied tasks per step:

```bash
# Queue tasks for a specific step (flow_id + flow_step_id)
wp datamachine flows queue add 30 --step=ai_step_42_30 "Review PR feedback and address comments"
wp datamachine flows queue add 30 --step=ai_step_42_30 "Add unit tests for TaxonomyHandler"
wp datamachine flows queue add 30 --step=ai_step_42_30 "Refactor to use dependency injection"
```

Each flow run pops from the appropriate step's queue and delivers the next unique instruction.

## Architecture

### Execution Model
```
Pipeline (template) → Flow (instance) → Job (execution)
```

- **Pipeline**: Reusable template defining step sequence
- **Flow**: Configured instance of a pipeline with specific settings
- **Job**: Single execution of a flow

### Step Types
| Type | Purpose | Has Handlers |
|------|---------|--------------|
| `fetch` | Import data (RSS, Sheets, Files, Reddit) | Yes |
| `ai` | Process with AI (multi-turn, tools) | No |
| `publish` | Output (WordPress, Twitter, Discord) | Yes |
| `update` | Modify existing content | Yes |
| `agent_ping` | Webhook to external agents | No |
| `webhook_gate` | Pause until external webhook fires | No |

### Publish Handlers
| Handler | Platform |
|---------|----------|
| `wordpress` | WordPress posts |
| `twitter` | Twitter/X |
| `discord` | Discord webhooks |
| `threads` | Threads |
| `bluesky` | Bluesky |
| `facebook` | Facebook |
| `pinterest` | Pinterest |
| `google_sheets` | Google Sheets |

### Multi-Handler Publish Steps

A publish step can target multiple handlers simultaneously. Configure `handler_slugs` as an array in the flow step config instead of a single `handler_slug`. The step executes each handler and collects results from all of them.

### Webhook Gate Step

The `webhook_gate` step type (@since v0.25.0) pauses a pipeline until an external webhook fires. It is handler-free — no handler config needed.

**How it works:**
1. Step generates a unique webhook URL and parks the job in a "waiting" state
2. The external system POSTs to the webhook URL when ready
3. The pipeline resumes from the next step with the webhook payload injected as data packets

**Configuration fields:**
- `timeout_hours` — How long to wait before failing (0 = no timeout, defaults to 7-day token expiry)
- `description` — Human-readable note about what the gate is waiting for

### Image Generation Modes

Image generation supports two modes via the `mode` parameter:
- `featured` (default) — Sets the generated image as the post's featured image
- `insert` — Inserts an image block directly into the post content

In `insert` mode, the `position` parameter controls placement: `after_intro` (default), `before_heading`, `end`, or `index:N`.

### Per-Agent Model Configuration

The `agent_models` setting allows different AI providers and models per agent type (e.g., pipeline vs chat). Configure via Settings → Agent Models in the admin UI or via `wp datamachine settings set agent_models <json>`.

### Prompt Hierarchy
Three levels, applied in order:
1. **Global system prompt** (`datamachine_settings.global_system_prompt`) - Personality, formatting rules, site-wide standards
2. **Pipeline system prompt** (`pipeline_config[step_id].system_prompt`) - Workflow-specific instructions
3. **User message** (queue item or step config) - The actual task/topic

### Abilities-First Architecture
All service logic uses WordPress Abilities API. Key abilities include:
- `datamachine/create-flow`, `datamachine/update-flow`, `datamachine/delete-flow`
- `datamachine/create-pipeline`, `datamachine/update-pipeline`, `datamachine/delete-pipeline`
- `datamachine/queue-add`, `datamachine/queue-list`, `datamachine/queue-clear`, `datamachine/queue-remove`, `datamachine/queue-update`, `datamachine/queue-move`
- `datamachine/send-ping`, `datamachine/execute-workflow`

## CLI Commands

```bash
# Settings
wp datamachine settings list
wp datamachine settings get <key>
wp datamachine settings set <key> <value>

# Pipelines
wp datamachine pipelines

# Flows
wp datamachine flows
wp datamachine flows run <flow_id>
wp datamachine flows run <flow_id> --count=<n>

# Prompt Queue (step-level)
wp datamachine flows queue add <flow_id> --step=<flow_step_id> "prompt text"
wp datamachine flows queue list <flow_id> --step=<flow_step_id>
wp datamachine flows queue clear <flow_id> --step=<flow_step_id>

# Queue management
wp datamachine flows queue update <flow_id> <index> "Updated prompt text"
wp datamachine flows queue move <flow_id> <from_index> <to_index>

# Jobs
wp datamachine job list
wp datamachine job list --status=<status>
wp datamachine job summary
```

## Working with Flows

### Running a Flow
```bash
# Run once (pops from queue if enabled)
wp datamachine flows run 25

# Trigger background execution
wp datamachine flows run 25 && wp action-scheduler run --hooks=datamachine_run_flow_now
```

### Managing the Prompt Queue
```bash
# Add topics to a step's queue (requires flow_step_id)
wp datamachine flows queue add 25 --step=ai_step_1_25 "Topic one"
wp datamachine flows queue add 25 --step=ai_step_1_25 "Topic two"

# Check a step's queue
wp datamachine flows queue list 25 --step=ai_step_1_25

# Clear a step's queue
wp datamachine flows queue clear 25 --step=ai_step_1_25

# Find flow_step_id: Check flow config or use the UI
```

## Taxonomy Handling

### Handler Config Options
For `taxonomy_{name}_selection`:
- `skip` - Don't assign this taxonomy
- `ai_decides` - AI provides values via tool parameters
- `<term_id|name|slug>` - Pre-select specific term

### Best Practices
- For reliable multi-term assignment, use `skip` and assign programmatically after publish
- Match existing site conventions for tag formatting
- Use existing terms rather than creating new ones when possible

## Agent Ping Step

Notifies external agents/webhooks during pipeline execution (outbound-only):

### Configuration
Configure via the Flow UI: select the Agent Ping step and set the webhook URL in the handler configuration modal. Each flow can have its own webhook destination.

### Sending Pings
Use the `datamachine/send-ping` ability or trigger via pipeline execution. The step sends pipeline context to configured webhook URLs with support for Discord webhook formatting. For inbound triggers, use REST endpoints like `/wp-json/datamachine/v1/execute` or `/wp-json/datamachine/v1/chat`.

## Content Quality Guidelines

When configuring AI content generation, include in global system prompt:
- **Paragraph length**: Specify max sentences (e.g., "2-3 sentences per paragraph")
- **Heading hierarchy**: h2 for sections, h3 for subsections
- **Formatting preferences**: Lists, blockquotes, etc.
- **Taxonomy conventions**: Tag formatting (Title Case vs lowercase)

## Debugging

### Log Locations
```bash
# Pipeline execution logs
tail -f wp-content/uploads/datamachine-logs/datamachine-pipeline.log

# Filter for specific job
grep "job_id\":123" datamachine-pipeline.log
```

### Common Issues

**Empty data packet error**: AI step didn't call the expected tool
- Check system prompt clarity
- Simplify instructions
- Verify tool is available

**Wrong taxonomy terms**: AI created new terms instead of using existing
- Use `skip` mode and assign programmatically
- Or improve prompt with explicit term names

**Job stuck in pending**: Action scheduler not running
```bash
wp action-scheduler run --hooks=datamachine_run_flow_now
```

## Code Structure

```
inc/
├── Abilities/           # Service layer (WordPress Abilities API)
│   ├── Flow/           # Flow-specific abilities
│   ├── Pipeline/       # Pipeline-specific abilities
│   └── AgentPing/      # Agent ping abilities
├── Core/
│   ├── Steps/          # Step type implementations
│   │   ├── AI/
│   │   ├── Fetch/
│   │   ├── Publish/    # Handlers: WordPress, Twitter, Discord, Threads, Bluesky, Facebook, Pinterest, GoogleSheets
│   │   ├── Update/
│   │   ├── AgentPing/
│   │   └── WebhookGate/
│   ├── WordPress/      # WP integrations (TaxonomyHandler, etc.)
│   └── Admin/          # React admin UI
├── Cli/                # WP-CLI commands
└── Engine/             # Core execution engine
```

## Integration with AI Agents

Data Machine is designed for agent-agnostic integration:

1. **Agent Ping**: Configure webhook URL to notify your agent framework
2. **Prompt Queue**: Agents can bulk-load topics via CLI
3. **Abilities API**: Programmatic access to all Data Machine functions

The agent framework handles the webhook, processes the notification, and can interact with WordPress via WP-CLI or the abilities API.
