# WP-CLI Commands

Data Machine provides a broad WP-CLI surface for managing pipelines, flows, jobs, agents, memory, system tasks, and more from the command line. Commands and their singular/plural aliases are registered under the `datamachine` namespace via `inc/Cli/Bootstrap.php`; use `wp help datamachine` for the authoritative list in a running install.

> **Note:** The `wp datamachine workspace` and `wp datamachine github` commands have been moved to the `data-machine-code` extension plugin.

## Contract Boundaries

- **Pipelines** are reusable step templates. They define step order and durable structure.
- **Flows** are runnable pipeline instances. They own schedules, queues, handler config, prompts, and per-flow step settings.
- **Step types** are execution primitives such as `fetch`, `ai`, `publish`, `upsert`, and `system_task`.
- **Handlers** are integrations selected by handler-backed step types. Only step types with `uses_handler=yes` accept handlers.
- **System tasks** are named operational jobs. Use them for bounded tasks such as alt text, retention, internal links, and memory maintenance. Use pipelines/flows for reusable multi-step workflows instead of hiding workflow composition inside one system task.

Workflow JSON uses the same canonical fields across entry points: `step_type`, `handler_slugs`, `handler_configs`, and `flow_step_settings`. The `type` field is accepted only as a compatibility alias for older ephemeral workflow specs; new workflows, pipelines, flows, and system tasks should use `step_type`. Legacy handler aliases such as `handler`, `handler_slug`, and `handler_config` are rejected on normal workflow paths.

## Available Commands

### datamachine pipelines

Manage pipelines. **Alias**: `pipeline`

```bash
# List all pipelines
wp datamachine pipelines list

# Get a specific pipeline (shows steps and flows)
wp datamachine pipelines get 5

# Create a pipeline with minimal step structure
wp datamachine pipelines create --name="My Pipeline" --steps='[{"step_type":"fetch","label":"RSS Fetch"}]'

# Update pipeline name or config
wp datamachine pipelines update 5 --name="New Name" --config='{"key":"value"}'

# Update an AI step system prompt. If the pipeline has one AI step, --step is optional.
wp datamachine pipelines update 5 --set-system-prompt="Write concise summaries."
wp datamachine pipelines update 5 --step=5_abc123 --set-system-prompt="Write concise summaries."

# Delete a pipeline
wp datamachine pipelines delete 5 --force

# Manage pipeline memory files
wp datamachine pipelines memory-files 5
wp datamachine pipelines memory-files 5 --add=strategy.md
wp datamachine pipelines memory-files 5 --remove=strategy.md
```

**Options**: `--per_page`, `--offset`, `--format`, `--fields`, `--dry-run`

`--steps` creates pipeline step structure. Use workflow/bundle specs when preserving full step settings such as `handler_slugs`, `handler_configs`, `flow_step_settings`, AI prompts, queues, or tool policy. Use `flow_config` only when intentionally creating the first runnable flow with the pipeline.

### datamachine flows

Manage flows. **Alias**: `flow`

```bash
# List all flows (optionally filter by pipeline)
wp datamachine flows list [pipeline_id]
wp datamachine flows list --handler=rss --format=json

# Get a specific flow with step configs
wp datamachine flows get 10

# Create a flow from pipeline
wp datamachine flows create --pipeline_id=5 --name="Daily Flow"

# Run a flow immediately
wp datamachine flows run 10
wp datamachine flows run 10 --count=5 --timestamp="2026-01-01 00:00:00"

# Update flow properties
wp datamachine flows update 10 --name="New Name"
wp datamachine flows update 10 --scheduling=daily
wp datamachine flows update 10 --set-user-message="Summarize this week's source data" --step=ai_2
wp datamachine flows update 10 --handler-config='{"key":"val"}' --step=fetch_1

# Delete a flow
wp datamachine flows delete 10 --yes

# Handler management
wp datamachine flows add-handler 10 --handler=rss --step=fetch_1
wp datamachine flows remove-handler 10 --handler=rss --step=fetch_1
wp datamachine flows list-handlers 10

# Memory file management
wp datamachine flows memory-files 10 --add=context.md
```

**Options**: `--per_page`, `--offset`, `--handler`, `--format`, `--fields`, `--dry-run`, `--yes`

Flows own runnable configuration. Put handler config, prompt queues, per-flow task settings, schedules, and run-time overrides on flows, not on the pipeline template.

### datamachine flows queue

Manage flow queues. **Since**: 0.31.0

```bash
# Add a prompt to the queue
wp datamachine flows queue add 10 "Write about AI agents"

# Add a fetch config patch to the queue
wp datamachine flows queue add 10 --patch='{"params":{"after":"2026-01-01"}}' --step=fetch_1

# List queue contents
wp datamachine flows queue list 10

# Set queue access mode: drain, loop, or static
wp datamachine flows queue mode 10 drain --step=ai_2
wp datamachine flows queue mode 10 loop --step=fetch_1

# Update a queue item
wp datamachine flows queue update 10 0 "Updated prompt"

# Move an item
wp datamachine flows queue move 10 0 2

# Remove an item
wp datamachine flows queue remove 10 0

# Clear the queue
wp datamachine flows queue clear 10

# Validate a topic for duplicates
wp datamachine flows queue validate 10 "AI agents" --post_type=post --threshold=0.8
```

**Options**: `--step`, `--format`

### datamachine flows webhook

Manage webhook triggers. Two auth primitives: **bearer** (default) and **hmac**
(template-based, provider-agnostic). **Since**: 0.31.0 (Bearer), 0.79.0 (HMAC
template verifier).

```bash
# Enable with default Bearer auth
wp datamachine flows webhook enable 10

# Enable with HMAC via a registered preset (core ships zero presets;
# they come from plugins / mu-plugins registering the filter).
wp datamachine flows webhook enable 10 --preset=<name> --generate-secret

# Enable with HMAC via an explicit template config
wp datamachine flows webhook enable 10 --config=@template.json --secret=<value>

# Deep-merge overrides on top of a preset or config
wp datamachine flows webhook enable 10 --preset=<name> \
  --overrides=@overrides.json --generate-secret

# List available presets
wp datamachine flows webhook presets

# Zero-downtime secret rotation — keeps the old secret verifying for 7d.
wp datamachine flows webhook rotate 10 --generate
wp datamachine flows webhook rotate 10 --generate --previous-ttl-seconds=86400
wp datamachine flows webhook forget 10 previous

# Replace a single secret id (no grace window). HMAC mode only.
wp datamachine flows webhook set-secret 10 --generate

# Regenerate the Bearer token (bearer mode only)
wp datamachine flows webhook regenerate 10

# Check webhook status — shows auth mode, template, secret ids (never values).
wp datamachine flows webhook status 10

# List all webhook-enabled flows
wp datamachine flows webhook list

# Configure rate limiting
wp datamachine flows webhook rate-limit 10 --max=10 --window=60

# Disable webhook (clears all auth material)
wp datamachine flows webhook disable 10
```

**DM core ships no provider names.** Preset registrations belong in companion
plugins. See [Webhook Triggers](../api/endpoints/webhook-triggers.md) for the
template config grammar, the `datamachine_webhook_auth_presets` filter, and
the backward-compat migration path for legacy v1 flows.

### datamachine flows bulk-config

Bulk update handler config across flows. **Since**: 0.39.0

```bash
# Preview changes (dry-run by default)
wp datamachine flows bulk-config --handler=wordpress --config='{"post_status":"draft"}'

# Scope to a pipeline
wp datamachine flows bulk-config --handler=wordpress --config='{"post_status":"draft"}' \
  --scope=pipeline --pipeline_id=5

# Execute changes
wp datamachine flows bulk-config --handler=wordpress --config='{"post_status":"draft"}' --execute
```

**Options**: `--handler`, `--config`, `--scope=global|pipeline|flow`, `--pipeline_id`, `--flow_id`, `--step_type`, `--dry-run`, `--execute`, `--format`

### datamachine jobs

Manage jobs. **Alias**: `job`. **Since**: 0.14.6

```bash
# List jobs with filters
wp datamachine jobs list
wp datamachine jobs list --status=failed --flow=10 --since="24 hours ago"

# Show detailed job info (engine data, error traces, AS status)
wp datamachine jobs show 42

# Get status summary
wp datamachine jobs summary

# Retry a failed job
wp datamachine jobs retry 42

# Manually fail a processing job
wp datamachine jobs fail 42 --reason="Stuck in loop"

# Delete jobs
wp datamachine jobs delete --type=failed --yes
wp datamachine jobs delete --type=all --cleanup-processed --yes

# Cleanup old jobs
wp datamachine jobs cleanup --older-than=30d --status=completed --dry-run

# Recover stuck jobs
wp datamachine jobs recover-stuck --timeout=3600 --dry-run

# Undo a completed job
wp datamachine jobs undo 42 --dry-run
wp datamachine jobs undo 42 --task-type=alt_text --force
```

**Options**: `--status`, `--flow`, `--source`, `--since`, `--limit`, `--format`, `--fields`

### datamachine agents

Manage agent identities. **Aliases**: `agent`. **Since**: 0.37.0

```bash
# List all agents
wp datamachine agents list
wp datamachine agent list  # alias

# Show agent details (config, access grants, directory info)
wp datamachine agents show my-agent

# Create a new agent
wp datamachine agents create my-agent --name="My Agent" --owner=1

# Read or update agent config
wp datamachine agents config my-agent
wp datamachine agents config my-agent --set='model=gpt-4o'

# Rename an agent (updates DB and filesystem)
wp datamachine agents rename old-slug new-slug --dry-run

# Delete an agent
wp datamachine agents delete my-agent --delete-files --yes

# Manage access grants
wp datamachine agents access grant my-agent 2 --role=operator
wp datamachine agents access grant-audience my-agent audience:public --role=operator
wp datamachine agents access revoke my-agent 2
wp datamachine agents access revoke-audience my-agent audience:public
wp datamachine agents access list my-agent

# Manage runtime bearer tokens. Raw token values are shown only at creation time.
wp datamachine agents token create my-agent --label="ci" --expires-in=7776000
wp datamachine agents token list my-agent
wp datamachine agents token revoke my-agent 3

# Portable agent bundle lifecycle
wp datamachine agents export my-agent --format=zip --destination=/tmp/my-agent.zip
wp datamachine agents import /tmp/my-agent.zip --dry-run
wp datamachine agents install /tmp/my-agent-bundle --slug=my-agent --dry-run
wp datamachine agents installed
wp datamachine agents status my-agent
wp datamachine agents diff /tmp/my-agent-bundle --slug=my-agent --format=json
wp datamachine agents upgrade /tmp/my-agent-bundle --slug=my-agent --dry-run
wp datamachine agents upgrade /tmp/my-agent-bundle --slug=my-agent --rebase-local --dry-run
wp datamachine agents rebase /tmp/my-agent-bundle --slug=my-agent --artifact=flow:weekly-digest
wp datamachine agents apply act_123
```

Use `agent` or `agents` for agent identities, access, tokens, config, import/export, and bundle lifecycle. Memory files live under the separate `memory` namespace.

### datamachine memory

Agent memory-file operations. **Since**: 0.30.0

`memory` reads and writes layered markdown files and sections. It is not an alias for agent identity management; use `agent` or `agents` for CRUD, access grants, tokens, and bundles.

```bash
# Read full MEMORY.md
wp datamachine memory read

# Read a specific MEMORY.md section. Pass section names without leading ##.
wp datamachine memory read "State"

# Read full files or sections in specific files
wp datamachine memory read SOUL.md
wp datamachine memory read SOUL.md "Identity"
wp datamachine memory read USER.md --agent=my-agent

# List sections
wp datamachine memory sections

# Write to a section. Pass section names without leading ##.
wp datamachine memory write "State" "Active and running"
wp datamachine memory write "State" "New note" --mode=append
wp datamachine memory write SOUL.md "Voice" "Concise and direct" --agent=my-agent
wp datamachine memory write "Session Notes" --from-file=/tmp/notes.md --mode=append
echo "- New lesson" | wp datamachine memory write "Lessons Learned" - --mode=append

# Search memory
wp datamachine memory search "deployment" --section="State"

# Daily memory operations
wp datamachine memory daily list
wp datamachine memory daily read 2026-03-15
wp datamachine memory daily write 2026-03-15 "Session notes"
wp datamachine memory daily append 2026-03-15 "More notes"
wp datamachine memory daily delete 2026-03-15
wp datamachine memory daily search "keyword" --from=2026-03-01 --to=2026-03-15

# Agent file listing and staleness checks. File content read/write is handled
# by `memory read` and `memory write`, not by `memory files`.
wp datamachine memory files list
wp datamachine memory files check --days=7

# Show resolved file paths for all memory layers
wp datamachine memory paths
wp datamachine memory paths --agent=my-agent --format=json
```

### datamachine system

System tasks and health checks. **Since**: 0.41.0

```bash
# Run system health checks
wp datamachine system health
wp datamachine system health --types=php,wp,plugin

# Schedule a system task to run now
wp datamachine system run alt_text_generation
wp datamachine system run daily_memory_generation

# Generate a chat session title
wp datamachine system title abc-123 --force

# Manage system task prompts
wp datamachine system prompts
wp datamachine system prompt-get alt_text_generation system_prompt
wp datamachine system prompt-set alt_text_generation system_prompt "New prompt"
wp datamachine system prompt-reset alt_text_generation system_prompt
```

`system run` schedules a job through the system task contract; it does not execute the task inline in the CLI process. Process queued work with `wp datamachine worker run` or `wp datamachine drain`.

### datamachine batch

Manage batch operations. **Since**: 0.33.0

```bash
# List batch operations
wp datamachine batch list
wp datamachine batch list --status=running

# Show batch status with progress bar
wp datamachine batch status 42

# Cancel a running batch
wp datamachine batch cancel 42
```

### datamachine worker

Headless automation worker. Composes stuck-job recovery with the Data Machine drain loop.

```bash
# Run a bounded worker loop
wp datamachine worker run --time-limit=3600 --sleep=30

# Run one recovery/drain pass and stop if approval is required.
wp datamachine worker run --once --stop-on-pending-actions

# Leave margin before an external supervisor timeout
wp datamachine worker run --time-limit=900 --max-passes=10 --stop-before-timeout=60

# Inspect queue/job status without draining
wp datamachine worker status
wp datamachine worker status --format=json
```

**Options**: `--time-limit`, `--batch-size`, `--drain-limit`, `--drain-time-limit`, `--sleep`, `--stuck-timeout`, `--no-recover-stuck`, `--stop-on-pending-actions`, `--max-passes`, `--stop-before-timeout`, `--once`, `--format`

### datamachine drain

Drain due Data Machine Action Scheduler actions until the queue is empty or a budget is reached.

```bash
# Drain all due Data Machine actions
wp datamachine drain

# Bound work for cron/supervisors
wp datamachine drain --limit=500 --batch-size=25 --time-limit=300
wp datamachine drain --time-limit=600 --stop-before-timeout=30 --format=json
wp datamachine drain --limit=500 --time-limit=300 --format=json
```

**Options**: `--limit`, `--batch-size`, `--time-limit`, `--stop-before-timeout`, `--job-id`, `--format=table|json`

### datamachine cycle / cycles

Run flows that are due during an external cycle. Manual flows participate only when their scheduling config sets `cycle_policy: every_cycle`.

```bash
# Run due flows for a named cycle, then drain Data Machine actions
wp datamachine cycle run world-of-wordpress
wp datamachine cycles run world-of-wordpress

# Preview selected flows without starting jobs
wp datamachine cycle run world-of-wordpress --dry-run --format=json

# Start due flows but leave draining to another worker
wp datamachine cycle run world-of-wordpress --no-drain
```

**Options**: `[<cycle>]`, `--dry-run`, `--[no-]drain`, `--format=table|json`

### datamachine pending-actions

Inspect durable pending approval actions. **Alias**: `pending-action`

```bash
# List approval actions
wp datamachine pending-actions list
wp datamachine pending-actions list --status=pending --kind=bundle_upgrade --format=json

# Inspect one action
wp datamachine pending-actions get act_123

# Summarize counts by kind/status
wp datamachine pending-actions summary
wp datamachine pending-actions summary --status=pending --format=json
```

**Options**: `--status`, `--kind`, `--agent_id`, `--created_by`, `--limit`, `--offset`, `--format`

### datamachine image

Image generation and optimization. **Since**: 0.33.0

```bash
# Generate an AI image
wp datamachine image generate "A sunset over mountains"
wp datamachine image generate "Hero image" --post_id=123 --mode=featured

# Render from a GD template
wp datamachine image render --template_id=social-card --data='{"title":"Hello"}'

# List available templates
wp datamachine image templates

# Check image generation config
wp datamachine image status

# Diagnose optimization issues
wp datamachine image diagnose --size_threshold=500000

# Optimize oversized images
wp datamachine image optimize --size_threshold=500000 --quality=82 --limit=50 --dry-run

# Diagnose broken image references (multisite-aware, read-only)
wp datamachine image broken
wp datamachine image broken --post_id=123
wp datamachine image broken --network --format=json
```

**Options**: `--model`, `--aspect_ratio`, `--mode=featured|insert`, `--post_type`, `--post_id`, `--limit`, `--network`, `--format`

### datamachine analytics

Analytics integrations. **Since**: 0.31.0

```bash
# Bing Webmaster Tools
wp datamachine analytics bing query_stats --days=30
wp datamachine analytics bing traffic_stats
wp datamachine analytics bing crawl_stats

# Google Analytics (GA4, requires Data Machine Business)
wp datamachine analytics ga page_stats --start-date=2026-01-01
wp datamachine analytics ga traffic_sources --limit=20
wp datamachine analytics ga realtime
wp datamachine analytics ga top_events
wp datamachine analytics ga user_demographics
wp datamachine analytics ga engagement --compare

```

### datamachine auth

Authentication management. **Since**: 0.36.0

```bash
# Check auth status for all providers
wp datamachine auth status

# Check a specific handler
wp datamachine auth status wordpress-api

# Connect (OAuth shows URL; direct accepts credentials)
wp datamachine auth connect google --client_id=xxx --client_secret=xxx

# Revoke site-wide credentials
wp datamachine auth revoke google --yes

# Deprecated alias for site-wide revoke
wp datamachine auth disconnect google --yes

# View or save API config
wp datamachine auth config google --show-secrets
wp datamachine auth config reddit --client_id=xxx --client_secret=xxx
```

### datamachine chat

Manage chat sessions. **Since**: 0.40.0

```bash
# List chat sessions
wp datamachine chat list --user=1 --limit=20

# Get a session with conversation
wp datamachine chat get abc-123

# Create a session
wp datamachine chat create --user=1 --context=sidebar

# Delete a session
wp datamachine chat delete abc-123 --yes

# Generate a session title
wp datamachine chat title abc-123 --force
```

### datamachine posts

Query Data Machine posts. **Alias**: `post`

```bash
# List all DM-managed posts
wp datamachine posts list
wp datamachine posts list --handler=wordpress --post_type=post --format=table

# Query by handler, flow, or pipeline
wp datamachine posts by-handler wordpress
wp datamachine posts by-flow 10
wp datamachine posts by-pipeline 5

# Recent posts
wp datamachine posts recent --limit=20
```

**Options**: `--handler`, `--flow-id`, `--pipeline-id`, `--post_type`, `--post_status`, `--per_page`, `--offset`, `--orderby`, `--order`, `--format`, `--fields`

### datamachine logs

Manage logs. **Alias**: `log`. **Since**: 0.15.2

```bash
# Read logs with filters
wp datamachine logs read
wp datamachine logs read --agent=pipeline --level=error --since="1 hour ago"
wp datamachine logs read --job-id=42 --format=json
wp datamachine logs read --search="timeout" --limit=50

# Log metadata
wp datamachine logs info
wp datamachine logs info --agent=pipeline

# Clear logs
wp datamachine logs clear --agent=pipeline --before="7 days ago" --yes
```

**Options**: `--agent`, `--level`, `--job-id`, `--pipeline-id`, `--flow-id`, `--search`, `--since`, `--before`, `--limit`, `--page`, `--format`, `--fields`

### datamachine settings

Manage plugin settings. **Alias**: `setting`. **Since**: 0.11.0

```bash
# List all settings
wp datamachine settings list

# Get a specific setting
wp datamachine settings get provider

# Set a setting (auto-coerces types)
wp datamachine settings set provider anthropic
wp datamachine settings set model claude-sonnet-4-20250514
```

**Options**: `--format=value|json|table`

### datamachine handlers

Handler discovery. **Alias**: `handler`. **Since**: 0.41.0

```bash
# List all handlers
wp datamachine handlers list
wp datamachine handlers list --step-type=fetch

# Validate a handler exists
wp datamachine handlers validate rss

# Get handler config fields
wp datamachine handlers fields rss

# Get site-wide handler defaults
wp datamachine handlers defaults
wp datamachine handlers defaults wordpress
```

### datamachine taxonomy

Taxonomy management. **Since**: 0.41.0

```bash
# List terms
wp datamachine taxonomy list --taxonomy=category

# Create a term
wp datamachine taxonomy create --name="News" --taxonomy=category

# Update a term
wp datamachine taxonomy update 42 --name="Updated News"

# Delete a term
wp datamachine taxonomy delete 42 --yes

# Resolve a term by name/slug
wp datamachine taxonomy resolve "news" --taxonomy=category --create
```

### datamachine step-types

Step type discovery. **Alias**: `step-type`. **Since**: 0.41.0

```bash
# List all step types
wp datamachine step-types list

# Get a specific step type
wp datamachine step-types get fetch

# Validate a step type exists
wp datamachine step-types validate ai
```

### datamachine processed-items

Processed items (deduplication). **Alias**: `processed-item`. **Since**: 0.41.0

```bash
# Audit processed-item rows by flow/handler
wp datamachine processed-items audit
wp datamachine processed-items audit --handler=ticketmaster --min-waste=0

# Clear processed items for a pipeline or flow
wp datamachine processed-items clear --pipeline=5 --yes
wp datamachine processed-items clear --flow=10 --yes
wp datamachine processed-items clear --handler=ticketmaster --after=2025-01-01 --dry-run
wp datamachine processed-items clear --all --yes

# Clean dedupe rows whose jobs have been purged
wp datamachine processed-items cleanup-orphans --dry-run
wp datamachine processed-items cleanup-orphans --flow=10 --yes

# Audit and replay rows marked processed by agent source rejection
wp datamachine processed-items source-rejected --pipeline=5 --source-type=rss
wp datamachine processed-items clear-source-rejected --pipeline=5 --after=2026-05-01 --dry-run
wp datamachine processed-items clear-source-rejected --pipeline=5 --after=2026-05-01 --yes

# Check if an item was processed
wp datamachine processed-items check --flow-step=fetch_1_10 --source=rss --item="https://example.com/feed/1"

# Check if a flow step has processing history
wp datamachine processed-items history --flow-step=fetch_1_10

# Revisit semantics: time-windowed reads on processed_timestamp.
# When was this item last processed?
wp datamachine processed-items get-processed-at \
  --flow-step-id=fetch_1_10 --source-type=wiki_post --item-identifier=42

# Of these candidates, which are stale (older than N days)?
wp datamachine processed-items find-stale \
  --flow-step-id=fetch_1_10 --source-type=wiki_post \
  --candidate-ids=1,2,3,4 --max-age-days=7

# Of these candidates, which have never been processed?
wp datamachine processed-items find-never-processed \
  --flow-step-id=fetch_1_10 --source-type=wiki_post \
  --candidate-ids=1,2,3,4
```

**Options**: `audit` supports `--handler`, `--pipeline`, `--min-waste`, `--format`; `clear` supports `--pipeline`, `--flow`, `--handler`, `--after`, `--before`, `--all`, `--dry-run`, `--yes`; `source-rejected` and `clear-source-rejected` support `--pipeline`, `--flow`, `--source-type`, `--after`, `--before`, `--job-status`, `--dry-run`, `--yes`, `--limit`, `--format` as applicable; revisit helpers support `--flow-step-id`, `--source-type`, `--candidate-ids`, `--limit`, `--format`.

### datamachine links

Internal linking. **Alias**: `link`. **Since**: 0.24.0

```bash
# AI-powered cross-linking
wp datamachine links crosslink --post_id=123 --links-per-post=3
wp datamachine links crosslink --category=news --all --dry-run

# Meta-based link coverage report
wp datamachine links diagnose

# Content-based link audit (builds link graph)
wp datamachine links audit --post_type=post --show=all
wp datamachine links audit --category=news --show=orphans

# Find orphaned posts
wp datamachine links orphans --post_type=post --limit=50

# Check for broken links
wp datamachine links broken --scope=internal --limit=100 --timeout=10

# Deterministic keyword-matching link injection (no AI)
wp datamachine links inject-category --category=news --links-per-post=3 --orphans-only --dry-run
```

### datamachine blocks

Gutenberg block management. **Alias**: `block`. **Since**: 0.28.0

These commands are storage-format aware: Data Machine converts the post type's
canonical stored format to blocks for the edit, then writes back in the canonical
format selected by `datamachine_post_content_format`.

```bash
# List blocks in a post
wp datamachine blocks list 123
wp datamachine blocks list 123 --type=core/paragraph

# Edit a block (find/replace)
wp datamachine blocks edit 123 0 --find="old text" --replace="new text" --dry-run

# Replace entire block content
wp datamachine blocks replace 123 0 --content="<p>New content</p>"
```

### datamachine alt-text

Image alt text management.

```bash
# Diagnose alt text coverage
wp datamachine alt-text diagnose

# Generate alt text
wp datamachine alt-text generate --attachment_id=456
wp datamachine alt-text generate --post_id=123 --force
```

### datamachine meta-description

Meta description management. **Since**: 0.31.0

```bash
# Diagnose meta description coverage
wp datamachine meta-description diagnose --post_type=post

# Generate meta descriptions
wp datamachine meta-description generate --post_id=123
wp datamachine meta-description generate --post_type=post --limit=50 --force
```

### datamachine indexnow

IndexNow search engine integration. **Since**: 0.36.0

```bash
# Submit URLs for indexing
wp datamachine indexnow submit https://example.com/new-post
wp datamachine indexnow submit --post-id=123
wp datamachine indexnow submit --post-type=post --limit=50

# Check status
wp datamachine indexnow status

# Manage API key
wp datamachine indexnow key generate
wp datamachine indexnow key verify
wp datamachine indexnow key show

# Enable/disable auto-ping
wp datamachine indexnow enable
wp datamachine indexnow disable
```

### datamachine retention

Inspect and schedule retention cleanup for jobs, logs, processed items, Action Scheduler rows, stale claims, files, and chat sessions.

```bash
# Show policies and storage metrics
wp datamachine retention show
wp datamachine retention show --format=json

# Preview or schedule cleanup jobs
wp datamachine retention run --dry-run
wp datamachine retention run --yes
```

**Options**: `show --format=table|json|yaml`; `run --dry-run`, `--yes`, `--format=table|json|yaml`

### datamachine email

Send email and operate on an IMAP mailbox through configured email auth.

```bash
# Send mail
wp datamachine email send --to=user@example.com --subject="Report" --body="<p>Hello</p>"

# Fetch and read mail
wp datamachine email fetch --search=UNSEEN --max=10
wp datamachine email fetch --search=ALL --headers-only --fields=uid,from,subject,date
wp datamachine email read 12345 --format=json

# Reply and manage messages
wp datamachine email reply --to=sender@example.com --subject="Re: Hello" --body="Thanks" --in-reply-to="<msgid@example.com>"
wp datamachine email move 12345 Archive
wp datamachine email flag 12345 Seen --action=clear
wp datamachine email delete 12345 --yes

# Batch mailbox operations
wp datamachine email batch-move --search='FROM "github.com"' --destination="[Gmail]/GitHub" --yes
wp datamachine email batch-flag --search=UNSEEN Flagged --max=10
wp datamachine email batch-delete --search='SUBJECT "unsubscribe" BEFORE "1-Jan-2025"' --yes
wp datamachine email unsubscribe 83012
wp datamachine email batch-unsubscribe --search='SUBJECT "newsletter"' --max=10 --yes
```

### datamachine external

Manage bearer-token connections to other Data Machine sites.

```bash
# Start browser authorization flow with a remote site
wp datamachine external connect chubes.net chubes-bot --label="local-agent"

# Manually store or list a remote token
echo "$TOKEN" | wp datamachine external add chubes.net chubes-bot --verify
wp datamachine external list
wp datamachine external show chubes.net/chubes-bot

# Test or call the remote site with the stored token
wp datamachine external test chubes.net/chubes-bot
wp datamachine external call chubes.net/chubes-bot GET /wp-json/wp/v2/users/me
wp datamachine external call chubes.net/chubes-bot POST /wp-json/datamachine/v1/chat --body='{"message":"hello"}'

# Remove a connection
wp datamachine external remove chubes.net/chubes-bot --yes
```

### datamachine test / fetch test

Dry-run fetch handlers and inspect packet summaries without creating jobs.

```bash
# List fetch-capable handlers
wp datamachine test --list

# Show handler config fields
wp datamachine test rss --describe

# Test with explicit config or an existing flow config
wp datamachine test rss --config='{"feed_url":"https://example.com/feed"}' --limit=3
wp datamachine test --flow=42 --format=json

# Alias useful when the handler is passed by flag
wp datamachine fetch test --handler=rss --config='{"feed_url":"https://example.com/feed"}'
```

### Other registered commands

The root namespace also registers `wp datamachine ai`; use `wp help datamachine ai` in a running install for the provider-specific diagnostic surface.

## Global Options

Most commands support these output options:

| Option | Description |
|--------|-------------|
| `--format=table\|json\|csv\|yaml\|ids\|count` | Output format |
| `--fields=<comma-separated>` | Limit output to specific fields |
| `--format=ids` | Output only IDs (space-separated) |
| `--format=count` | Output only the count |

## Aliases

Most commands have singular and plural forms:

- `wp datamachine pipeline` / `wp datamachine pipelines`
- `wp datamachine flow` / `wp datamachine flows`
- `wp datamachine job` / `wp datamachine jobs`
- `wp datamachine post` / `wp datamachine posts`
- `wp datamachine log` / `wp datamachine logs`
- `wp datamachine link` / `wp datamachine links`
- `wp datamachine block` / `wp datamachine blocks`
- `wp datamachine handler` / `wp datamachine handlers`
- `wp datamachine step-type` / `wp datamachine step-types`
- `wp datamachine processed-item` / `wp datamachine processed-items`
- `wp datamachine pending-action` / `wp datamachine pending-actions`
- `wp datamachine setting` / `wp datamachine settings`
- `wp datamachine agent` / `wp datamachine agents` (agent management)
- `wp datamachine cycle` / `wp datamachine cycles`
- `wp datamachine memory` (agent memory-file operations; no singular/plural agent alias)

## Examples

### Run a flow from a script

```bash
#!/bin/bash
# Daily sync script
wp datamachine flows run 10 --force
wp datamachine jobs list --status=failed
```

### Bulk create flows

```bash
# Create flows for multiple pipelines
for id in 1 2 3 4 5; do
  wp datamachine flows create --pipeline_id=$id --name="Flow for Pipeline $id"
done
```

### Monitor job status

```bash
# Check for failed jobs
failed=$(wp datamachine jobs list --status=failed --format=count)
if [ "$failed" -gt 0 ]; then
  echo "Warning: $failed failed jobs"
  wp datamachine jobs list --status=failed
fi
```

### Agent memory workflow

```bash
# Read current memory, update a section, verify
wp datamachine memory read
wp datamachine memory write "Status" "All systems operational"
wp datamachine memory search "operational"
