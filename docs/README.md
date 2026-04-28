# Data Machine Documentation

Complete user documentation for Data Machine, the AI-first WordPress plugin that combines a visual pipeline builder, conversational chat agent, REST API, and handler/tool extensibility under a single workflow engine.

## Quick Navigation

### Core Concepts
- **Engine Execution**: Breakdown of four-action execution cycle, Single Item Execution Model, and job status logic.
- **Troubleshooting Problem Flows**: Automated monitoring of consecutive failures/no-items and how to resolve them.
- **Architecture**: End-to-end breakdown of execution engine, services layer, and handler infrastructure.
- **Abilities API**: WordPress 6.9 capability discovery and execution for Data Machine operations.
- **Database Schema**: Tables that persist pipelines, flows, jobs, and processed items.
- **Changelog**: Historical summary of notable releases and architectural changes.

### Architecture Deep Dives
- **Agent Memory Backends**: Store selection model for disk-backed memory, optional guideline-backed stores, and the DMC file-projection boundary ([architecture/agent-memory-backends.md](architecture/agent-memory-backends.md)).
- **Pipeline Execution Axes**: Queue, fan-out, and per-step iteration semantics ([architecture/pipeline-execution-axes.md](architecture/pipeline-execution-axes.md)).
- **Policy Resolvers**: Why `ToolPolicyResolver`, `MemoryPolicyResolver`, `ActionPolicyResolver`, and `PipelineTranscriptPolicy` stay as four single-purpose classes ([architecture/policy-resolvers.md](architecture/policy-resolvers.md)).
- **Iteration Budget**: Shared bounded-iteration primitive backing `conversation_turns` and `chain_depth` budgets ([architecture/iteration-budget.md](architecture/iteration-budget.md)).

### Engine & Services
- **Universal Engine**: Shared AI infrastructure for pipeline and chat agents.
- **AI Conversation Loop**: Turn-based conversation execution with directive orchestration.
- **AI Directives System**: Hierarchical directive injection for contextual AI behavior.
- **Tool Execution**: Centralized discovery, validation, and execution of AI tools.
- **Tool Manager**: Runtime tool enablement, provider checks, and contextual metadata.
- **Request Builder**: Directive-aware construction of provider requests.
- **Conversation Manager**: Message normalization, logging, and tool call tracking.
- **Prompt Builder**: Priority-based directive registration via filters.
- **Parameter Systems**: Unified parameter handling across tools and handlers.
- **Tool Result Finder**: Utility for interpreting tool responses inside data packets.
- **OAuth Handlers**: Base classes for OAuth1/OAuth2 providers and app-password flows.
- **Handler Registration Trait**: Centralized registration pattern for fetch, publish, and upsert handlers.
- **HTTP Client**: Standardized outbound request flow for handlers with structured logging and browser-mode header support.
- **Import/Export System**: Pipeline configuration backup, migration, and sharing functionality.

### Handler Documentation
- **Fetch Handlers**: Source-specific data retrieval with deduplication, filtering, and engine data storage.
- **Publish Handlers**: Modular destination integrations with consistent response formatting and logging.
- **Upsert Handlers**: Identity-aware create-or-update operations — find existing content by identity strategy, update if changed, create if new.

### AI Tools
- **Tools Overview**: Global and context-aware tools available to AI agents.
- **Execute Workflow**: Modular execution of multi-step workflows from the chat toolset.
- **Global Tools**: Google Search, Local Search, Web Fetch, WordPress Post Reader, and others used across agents.
- **Chat Tools**: AddPipelineStep, ApiQuery, ConfigureFlowSteps, ConfigurePipelineStep, CreateFlow, CreatePipeline, RunFlow, UpdateFlow, and other workflow management tools.

### API Reference
- **API Overview**: Catalog of REST endpoints for API consumers.
- **Endpoints**: Auth, Execute, Files, Flows, Jobs, Logs, and other REST resources.

### Development
- **Hooks**: Core actions, filters, and engine hooks for extension development.
- **REST Integration**: Patterns for extending the REST API and custom endpoints.

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
│   ├── engine-execution.md            # Execution cycle and Single Item Execution Model
│   ├── troubleshooting-problem-flows.md # Monitoring consecutive failures and no-items
│   ├── http-client.md                 # Centralized HTTP client architecture
│   ├── import-export.md               # Pipeline import/export functionality
│   └── [other core system docs...]
├── handlers/                          # Fetch, publish, and update handler specifics
├── ai-tools/                          # AI agent tools, workflows, and tool usage
├── admin-interface/                   # User guidance for admin pages
├── api/                               # REST API for consumers
│   ├── index.md                       # Complete API overview and common patterns
│   └── endpoints/                     # Individual REST endpoint documentation
│       └── errors.md                  # Error handling reference
├── development/                       # Developer-focused documentation
│   ├── hooks/                         # Core actions, filters, and engine hooks
│   └── rest-integration.md            # REST API extension patterns
└── README.md                          # This navigation and orientation page
```

## Component Coverage
Refer to the individual files listed above for implementation details, operational guidance, and API references.
