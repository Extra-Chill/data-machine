# WP-CLI Commands

Data Machine provides 23 WP-CLI command namespaces for managing pipelines, flows, jobs, agents, and more from the command line. All commands are registered under the `datamachine` namespace via `inc/Cli/Bootstrap.php`.

> **Note:** The `wp datamachine workspace` and `wp datamachine github` commands have been moved to the `data-machine-code` extension plugin.

## Available Commands

### datamachine pipelines

Manage pipelines. **Alias**: `pipeline`

```bash
# List all pipelines
wp datamachine pipelines list

# Get a specific pipeline (shows steps and flows)
wp datamachine pipelines get 5

# Create a pipeline with steps
wp datamachine pipelines create --name="My Pipeline" --steps='[{"type":"fetch","name":"RSS Fetch"}]'

# Update pipeline name or config
wp datamachine pipelines update 5 --name="New Name" --config='{"key":"value"}'

# Update pipeline system prompt
wp datamachine pipelines update 5 --set-system-prompt --step=fetch_1

# Delete a pipeline
wp datamachine pipelines delete 5 --force

# Manage pipeline memory files
wp datamachine pipelines memory-files 5
wp datamachine pipelines memory-files 5 --add=strategy.md
wp datamachine pipelines memory-files 5 --remove=strategy.md
```

**Options**: `--per_page`, `--offset`, `--format`, `--fields`, `--dry-run`

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
wp datamachine flows update 10 --set-prompt --step=ai_2
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

**Options**: `--per_page`, `--offset`, `--handler`, `--format`, `--fields`

### datamachine flows queue

Manage flow queues. **Since**: 0.31.0

```bash
# Add a prompt to the queue
wp datamachine flows queue add 10 "Write about AI agents"

# List queue contents
wp datamachine flows queue list 10

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

Manage webhook triggers. Supports two auth modes: Bearer (default) and HMAC-SHA256. **Since**: 0.31.0 (Bearer), 0.79.0 (HMAC).

```bash
# Enable webhook trigger with default Bearer auth
wp datamachine flows webhook enable 10

# Enable with HMAC-SHA256 auth (GitHub-style) and a generated secret
wp datamachine flows webhook enable 10 --auth-mode=hmac_sha256 --generate-secret

# Enable with HMAC for a non-GitHub provider (Shopify example)
wp datamachine flows webhook enable 10 \
  --auth-mode=hmac_sha256 \
  --signature-header=X-Shopify-Hmac-Sha256 \
  --signature-format=base64 \
  --secret=<shopify_secret>

# Set or rotate the HMAC secret (prints the new secret once)
wp datamachine flows webhook set-secret 10 --generate
wp datamachine flows webhook set-secret 10 --secret=<value>

# Check webhook status (shows auth mode; never shows secret/token)
wp datamachine flows webhook status 10

# List all webhook-enabled flows
wp datamachine flows webhook list

# Regenerate Bearer token (bearer mode only)
wp datamachine flows webhook regenerate 10

# Configure rate limiting
wp datamachine flows webhook rate-limit 10 --max=10 --window=60

# Disable webhook (clears all auth material, both modes)
wp datamachine flows webhook disable 10
```

**Signature formats for HMAC mode** (`--signature-format`):
- `sha256=hex` (default) — GitHub-style `sha256=<hex>` header values.
- `hex` — raw hex digest (e.g. Linear).
- `base64` — base64-encoded raw digest (e.g. Shopify).

See [Webhook Triggers](../api/endpoints/webhook-triggers.md) for the full
GitHub walkthrough and security notes.

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

# Rename an agent (updates DB and filesystem)
wp datamachine agents rename old-slug new-slug --dry-run

# Delete an agent
wp datamachine agents delete my-agent --delete-files --yes

# Manage access grants
wp datamachine agents access grant my-agent 2 --role=operator
wp datamachine agents access revoke my-agent 2
wp datamachine agents access list my-agent
```

### datamachine memory

Agent memory-file operations. **Since**: 0.30.0

```bash
# Read full memory
wp datamachine memory read

# Read a specific section
wp datamachine memory read "## State"

# List sections
wp datamachine memory sections

# Write to a section
wp datamachine memory write "## State" "Active and running"
wp datamachine memory write "## State" "New note" --mode=append

# Search memory
wp datamachine memory search "deployment" --section="## State"

# Daily memory operations
wp datamachine memory daily list
wp datamachine memory daily read 2026-03-15
wp datamachine memory daily write 2026-03-15 "Session notes"
wp datamachine memory daily append 2026-03-15 "More notes"
wp datamachine memory daily delete 2026-03-15
wp datamachine memory daily search "keyword" --from=2026-03-01 --to=2026-03-15

# Agent file management
wp datamachine memory files list
wp datamachine memory files read SOUL.md
echo "content" | wp datamachine memory files write CUSTOM.md
wp datamachine memory files check --days=7

# Show resolved file paths for all memory layers
wp datamachine memory paths
wp datamachine memory paths --agent=my-agent --format=json
```


System tasks and health checks. **Since**: 0.41.0

```bash
# Run system health checks
wp datamachine system health
wp datamachine system health --types=php,wp,plugin

# Run a system task immediately
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
```

**Options**: `--model`, `--aspect_ratio`, `--mode=featured|insert`, `--format`

### datamachine analytics

Analytics integrations. **Since**: 0.31.0

```bash
# Google Search Console
wp datamachine analytics gsc query_stats --start-date=2026-01-01 --end-date=2026-03-01
wp datamachine analytics gsc page_stats --url-filter="/blog/"
wp datamachine analytics gsc inspect_url --inspect-url="https://example.com/post"
wp datamachine analytics gsc list_sitemaps

# Bing Webmaster Tools
wp datamachine analytics bing query_stats --days=30
wp datamachine analytics bing traffic_stats
wp datamachine analytics bing crawl_stats

# Google Analytics (GA4)
wp datamachine analytics ga page_stats --start-date=2026-01-01
wp datamachine analytics ga traffic_sources --limit=20
wp datamachine analytics ga realtime
wp datamachine analytics ga top_events
wp datamachine analytics ga user_demographics
wp datamachine analytics ga engagement --compare

# PageSpeed Insights
wp datamachine analytics pagespeed analyze --page-url="https://example.com" --strategy=mobile
wp datamachine analytics pagespeed performance --page-url="https://example.com"
wp datamachine analytics pagespeed opportunities --page-url="https://example.com"
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

# Disconnect
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
# Clear processed items for a pipeline or flow
wp datamachine processed-items clear --pipeline=5 --yes
wp datamachine processed-items clear --flow=10 --yes

# Check if an item was processed
wp datamachine processed-items check --flow-step=fetch_1_10 --source=rss --item="https://example.com/feed/1"

# Check if a flow step has processing history
wp datamachine processed-items history --flow-step=fetch_1_10

# Revisit semantics (since 0.71.0) — time-windowed reads on processed_timestamp
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
- `wp datamachine setting` / `wp datamachine settings`
- `wp datamachine agent` / `wp datamachine agents` (agent management)
- `wp datamachine memory` (agent memory-file operations)

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
wp datamachine memory write "## Status" "All systems operational"
wp datamachine memory search "operational"
