# Data Machine Agent Skill

AI-powered content automation for WordPress. This skill helps AI agents understand and work with Data Machine.

## Core Philosophy

Data Machine treats AI as a first-class participant, not just a tool. Pipelines automate content workflows with AI making decisions and taking actions.

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

### Prompt Hierarchy
Three levels, applied in order:
1. **Global system prompt** (`datamachine_settings.global_system_prompt`) - Personality, formatting rules, site-wide standards
2. **Pipeline system prompt** (`pipeline_config[step_id].system_prompt`) - Workflow-specific instructions
3. **User message** (queue item or step config) - The actual task/topic

### Abilities-First Architecture
All service logic uses WordPress Abilities API. Key abilities include:
- `datamachine/create-flow`, `datamachine/update-flow`, `datamachine/delete-flow`
- `datamachine/create-pipeline`, `datamachine/update-pipeline`, `datamachine/delete-pipeline`
- `datamachine/queue-add`, `datamachine/queue-list`, `datamachine/queue-clear`, `datamachine/queue-update`
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

# Prompt Queue
wp datamachine flows queue add <flow_id> "prompt text"
wp datamachine flows queue list <flow_id>
wp datamachine flows queue clear <flow_id>

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
# Add topics to queue
wp datamachine flows queue add 25 "Topic one"
wp datamachine flows queue add 25 "Topic two"

# Check queue
wp datamachine flows queue list 25

# Clear queue
wp datamachine flows queue clear 25
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

Notifies external agents/webhooks after pipeline steps:

### Configuration
Handler config is stored at **flow level**, not pipeline level:
```php
$flow['flow_config']['step_id']['handler_config']['webhook_url'] = 'https://...';
```

### Sending Pings
Use the `datamachine/send-ping` ability or configure via the Flow UI.

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
│   │   ├── Publish/
│   │   └── AgentPing/
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
