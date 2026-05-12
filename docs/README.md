# Data Machine Documentation

Complete user and agent documentation for Data Machine, the WordPress automation product layer that combines pipelines, flows, jobs, chat agents, system tasks, policy-gated tools, REST/WP-CLI surfaces, and extension-owned handlers on top of the generic Agents API substrate.

## Agent Orientation

If you are an agent landing in this repository, start with this map:

```text
Data Machine core
  -> pipelines/flows/jobs: repeatable automation and execution state
  -> engine/actions: bounded cycle execution and job transitions
  -> AI runtime: request assembly, tools, policies, memory, transcripts
  -> REST/WP-CLI/admin: operator and integration surfaces

Agents API dependency
  -> generic agent contracts, durable conversation loop, memory-store and
     approval vocabulary, transcript/lock/event primitives

Extension plugins
  -> additional handlers, tools, abilities, bundle extras, GitHub/workspace,
     social, business, editor, frontend chat, and events behavior
```

Core should expose behavior through abilities, hooks, REST, CLI, handlers, and bundle contracts. Extension-specific behavior belongs in extension plugins unless it is a generic Data Machine primitive.

## Quick Navigation

### Core Concepts
- **Overview**: Product map, pipeline/flow/job model, agent modes, and extension boundaries ([overview.md](overview.md)).
- **Architecture**: Execution engine, services layer, handler infrastructure, and boundary principles ([architecture.md](architecture.md)).
- **Engine Execution**: Bounded execution cycle, Single Item Execution Model, job status logic, and retry behavior ([core-system/engine-execution.md](core-system/engine-execution.md)).
- **Pipeline Execution Axes**: Queue, fan-out, per-step iteration, and scheduled runs as separate dimensions ([architecture/pipeline-execution-axes.md](architecture/pipeline-execution-axes.md)).
- **Troubleshooting Problem Flows**: Automated monitoring of consecutive failures/no-items and how to resolve them ([core-system/troubleshooting-problem-flows.md](core-system/troubleshooting-problem-flows.md)).
- **Abilities API**: WordPress capability discovery and execution for Data Machine operations ([core-system/abilities-api.md](core-system/abilities-api.md)).
- **WP-CLI**: Current command surface including cycle, drain, worker, pending actions, retention, bundles, and aliases ([core-system/wp-cli.md](core-system/wp-cli.md)).

### Architecture Deep Dives
- **Agents API Boundary**: Why durable agent runtime primitives live in Agents API while Data Machine owns product automation ([development/agents-api-pre-extraction-audit.md](development/agents-api-pre-extraction-audit.md)).
- **Duplicated Substrate Inventory**: Follow-up map for remaining generic seams and Data Machine adapters ([development/agents-api-duplicated-substrate-inventory.md](development/agents-api-duplicated-substrate-inventory.md)).
- **Agent Memory Backends**: Store selection model for disk-backed memory, optional guideline-backed stores, and the DMC file-projection boundary ([architecture/agent-memory-backends.md](architecture/agent-memory-backends.md)).
- **Pipeline Execution Axes**: Queue, fan-out, and per-step iteration semantics ([architecture/pipeline-execution-axes.md](architecture/pipeline-execution-axes.md)).
- **Policy Resolvers**: Why tool, memory, directive, action, and transcript policies stay as single-purpose classes ([architecture/policy-resolvers.md](architecture/policy-resolvers.md)).
- **Iteration Budget**: Shared bounded-iteration primitive backing `conversation_turns` and `chain_depth` budgets ([architecture/iteration-budget.md](architecture/iteration-budget.md)).

### Engine & Services
- **Daily Memory System**: DailyMemoryTask lifecycle, deterministic overflow, opt-in daily injection, and daily memory artifacts ([core-system/daily-memory-system.md](core-system/daily-memory-system.md)).
- **Memory Policy**: Per-agent memory injection policy and safe self-memory write gates ([core-system/memory-policy.md](core-system/memory-policy.md)).
- **Agent Bundles**: Portable agent package schema, artifact tracking, extras, extension artifacts, run artifacts, and authenticated sources ([core-system/agent-bundles.md](core-system/agent-bundles.md)).
- **Universal Engine**: Shared AI infrastructure for pipeline and chat agents.
- **AI Conversation Loop**: Data Machine turn runner over `AgentsAPI\AI\WP_Agent_Conversation_Loop` ([core-system/ai-conversation-loop.md](core-system/ai-conversation-loop.md)).
- **AI Directives System**: Hierarchical directive injection for contextual AI behavior.
- **Tool Execution**: Resolved tool dispatch, action policy, ability-only execution, and approval staging.
- **Tool Manager**: Data Machine tool registry, source-level availability checks, policy resolution, and handler tool expansion.
- **Request Builder**: Directive-aware construction of provider requests.
- **Conversation Manager**: Message normalization, logging, and tool call tracking.
- **Prompt Builder**: Priority-based directive registration via filters.
- **Parameter Systems**: Unified parameter handling across tools and handlers.
- **Tool Result Finder**: Utility for interpreting tool responses inside data packets.
- **OAuth Handlers**: Base classes for OAuth1/OAuth2 providers and app-password flows.
- **Handler Registration Trait**: Centralized registration pattern for fetch, publish, and upsert handlers.
- **HTTP Client**: Standardized outbound request flow for handlers with structured logging and browser-mode header support.
- **Import/Export System**: Pipeline configuration backup, migration, and sharing functionality.
- **Agent Bundles**: Portable agent recipes with memory, pipelines, flows, reserved schema trees, and extension-owned extras ([core-system/agent-bundles.md](core-system/agent-bundles.md)).
- **Daily Memory System**: Append-only daily artifacts, daily-memory directive, chat tool, REST/CLI, and summarization task ([core-system/daily-memory-system.md](core-system/daily-memory-system.md)).
- **Ephemeral Workflows**: Execute workflow definitions without first saving a persistent pipeline ([core-system/ephemeral-workflows.md](core-system/ephemeral-workflows.md)).
- **Memory Policy**: Read/write/editability policy for self-memory and file sections ([core-system/memory-policy.md](core-system/memory-policy.md)).

### Handler Documentation
- **Fetch Handlers**: Source-specific data retrieval with deduplication, filtering, and engine data storage.
- **Publish Handlers**: Modular destination integrations with consistent response formatting and logging.
- **Upsert Handlers**: Identity-aware create-or-update operations — find existing content by identity strategy, update if changed, create if new.

### AI Tools
- **Tools Overview**: Static, ability-backed, and adjacent handler tools available to AI agents.
- **Execute Workflow**: Modular execution of multi-step workflows from the chat toolset.
- **Static Registry Tools**: Research, memory, workflow-management, and site-operation tools registered through `datamachine_tools`.
- **Pipeline Handler Tools**: Runtime tools generated from adjacent fetch, publish, and upsert handlers.
- **Policy-Gated Tools**: Tools are composed from sources and filtered by mode, memory, action, and handler policies before exposure.
- **Global Tools**: Google Search, Local Search, Web Fetch, WordPress Post Reader, daily memory, image generation, search-console/analytics, and others used across agents.
- **Chat Tools**: AddPipelineStep, ApiQuery, ConfigureFlowSteps, ConfigurePipelineStep, CreateFlow, CreatePipeline, RunFlow, UpdateFlow, and other workflow management tools.

### API Reference
- **API Overview**: Canonical REST route inventory sourced from `inc/Api` registrations ([api/index.md](api/index.md)).
- **Endpoints**: Agents, Agent Ping, Analytics, Auth, Chat, Email, Execute, Files, Flows, Internal Links, Jobs, Logs, Pipelines, Settings, System, and other REST resources.
- **Workflow Endpoints**: Execute, flows, queues, webhooks, jobs, logs, and processed items.
- **Agent Endpoints**: Chat, chat sessions, agents, access, tokens, memory files, daily memory, Agent Ping callbacks, and pending-action resolution.
- **Discovery/Settings Endpoints**: Handlers, step types, providers, tools, settings, system tasks, auth, and users.

### Development
- **Hooks**: Core actions, filters, and engine hooks for extension development.
- **REST Integration**: Patterns for extending the REST API and custom endpoints.
- **Extension Boundaries**: Core provides generic primitives; data-machine-code, socials, business, editor, frontend-chat, events, and other plugins own their product-specific handlers/tools/abilities.

### Admin Interface
- **Pipeline Builder**: React-based page for creating pipelines, configuring steps, and enabling tools.
- **Settings Configuration**: Provider credentials, tool defaults, and global behavior settings.
- **Jobs Management**: React-based job history and admin cleanup actions.

## Documentation Structure
```
docs/
├── overview.md                        # System overview, data flow, and key concepts
├── architecture.md                    # Execution engine, architecture principles, and shared components
├── architecture/                      # Architecture deep dives (axes, policies, primitives)
├── CHANGELOG.md                       # Semantic changelog for releases
├── core-system/                       # Engine, services, and core infrastructure pieces
│   ├── abilities-api.md               # WordPress 6.9 Abilities API for flow queries, logging, and post filtering
│   ├── ai-directives.md               # AI directive system and priority hierarchy
│   ├── ai-conversation-loop.md        # Data Machine turn runner over Agents API conversation loop
│   ├── agent-bundles.md               # Portable agent bundle schema and extras contract
│   ├── daily-memory-system.md         # Daily memory files, task, tool, CLI, and REST surface
│   ├── engine-execution.md            # Execution cycle and Single Item Execution Model
│   ├── troubleshooting-problem-flows.md # Monitoring consecutive failures and no-items
│   ├── http-client.md                 # Centralized HTTP client architecture
│   ├── import-export.md               # Pipeline import/export functionality
│   ├── memory-policy.md               # Memory section policy and pending writes
│   ├── wp-cli.md                      # Command reference and aliases
│   └── [other core system docs...]
├── handlers/                          # Fetch, publish, and update handler specifics
├── ai-tools/                          # AI agent tools, workflows, and tool usage
├── admin-interface/                   # User guidance for admin pages
├── api/                               # REST API for consumers
│   ├── index.md                       # Complete API overview and common patterns
│   └── endpoints/                     # Individual REST endpoint documentation
│       ├── agents.md                  # Agent CRUD, access grants, and tokens
│       ├── agent-ping.md              # Bearer-token ping callback routes
│       ├── email.md                   # Email send/fetch/mailbox routes
│       ├── internal-links.md          # Link audit and diagnostics routes
│       └── errors.md                  # Error handling reference
├── development/                       # Developer-focused documentation
│   ├── agents-api-pre-extraction-audit.md # Agents API/Data Machine boundary record
│   ├── agents-api-duplicated-substrate-inventory.md # Remaining substrate follow-ups
│   ├── hooks/                         # Core actions, filters, and engine hooks
│   └── rest-integration.md            # REST API extension patterns
└── README.md                          # This navigation and orientation page
```

## Current Runtime Surfaces

Use these source files as authoritative anchors when docs and code disagree:

- `inc/Cli/Bootstrap.php` registers the WP-CLI command tree and aliases.
- `inc/Api/` registers the Data Machine REST product API.
- `inc/Engine/AI/conversation-loop.php` shows the Data Machine turn runner and Agents API loop boundary.
- `inc/Engine/AI/Tools/` contains tool sources, execution, and policy resolution.
- `inc/Engine/AI/Actions/` contains pending-action adapters, REST/ability surfaces, and resolver plumbing.
- `inc/Engine/AI/System/Tasks/` contains background system tasks including daily memory and retention.

## Component Coverage
Refer to the individual files listed above for implementation details, operational guidance, and API references.
