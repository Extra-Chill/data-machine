# AI Tools Overview

AI tools provide capabilities to AI agents for interacting with external services, processing data, and performing research tasks. Data Machine supports both global tools and handler-specific tools.

## Tool Categories

### Global Tools (Universal)

Available to all AI agents (pipeline + chat + standalone) via `datamachine_global_tools` filter:

**Google Search** (`google_search`)
- **Purpose**: Search Google and return structured JSON results with titles, links, and snippets from external websites. Use for external information, current events, and fact-checking.
- **Configuration**: API key + Custom Search Engine ID required
- **Use Cases**: Fact-checking, research, external context gathering

**Local Search** (`local_search`)
- **Purpose**: Search this WordPress site and return structured JSON results with post titles, excerpts, permalinks, and metadata. Use ONCE to find existing content before creating new content.
- **Configuration**: None required (uses WordPress core)
- **Use Cases**: Content discovery, internal link suggestions, avoiding duplicate content

**WebFetch** (`web_fetch`)
- **Purpose**: Fetch and extract readable content from web pages. Use after Google Search to retrieve full article content. Returns page title and cleaned text content from any HTTP/HTTPS URL.
- **Configuration**: None required
- **Features**: 50K character limit, HTML processing, URL validation
- **Use Cases**: Web content analysis, reference material extraction, competitive research

**WordPress Post Reader** (`wordpress_post_reader`)
- **Purpose**: Read full WordPress post content by URL for detailed analysis
- **Configuration**: None required
- **Features**: Complete post content retrieval, optional custom fields inclusion
- **Use Cases**: Content analysis before WordPress Update operations, detailed post examination after Local Search

**Update Taxonomy Term** (`update_taxonomy_term`) (@since v0.8.0)
- **Purpose**: Update existing taxonomy terms including core fields and custom meta.
- **Configuration**: None required
- **Features**: Modifies name, slug, description, parent, and custom meta (e.g., venue_address).
- **Use Cases**: Correcting venue details, updating artist bios, managing taxonomy hierarchies.
- **Documentation**: [Update Taxonomy Term](update-taxonomy-term.md)

**Agent Memory** (`agent_memory`) (@since v0.30.0)
- **Purpose**: Manage persistent agent memory (MEMORY.md) — long-lived knowledge that survives across sessions. Stored as markdown sections (## headers).
- **Configuration**: None required
- **Features**: Actions: `list_sections` (see what exists), `get` (read content), `update` (write). Supports `append` mode to add without losing existing content and `set` mode to replace a section entirely. Optional `user_id` for layered memory context.
- **Use Cases**: Persistent knowledge storage, cross-session state, agent self-improvement

**Agent Daily Memory** (`agent_daily_memory`) (@since v0.33.0)
- **Purpose**: Manage daily memory journal entries (daily/YYYY/MM/DD.md). Use for session activity, temporal events, and work logs.
- **Configuration**: None required
- **Features**: Actions: `write` (record session notes, defaults to append), `read` (review a specific day), `search` (find past entries by keyword), `list` (see which days have entries). Date defaults to today. Supports `from`/`to` range for search.
- **Use Cases**: Session logging, temporal event tracking, work history review

**Internal Link Audit** (`internal_link_audit`) (@since v0.32.0)
- **Purpose**: Audit links on the WordPress site. Three actions: `audit` scans post content to build a link graph (cached 24hr), `orphans` lists posts with zero inbound links, `broken` performs HTTP HEAD checks on cached links to find broken URLs.
- **Configuration**: None required
- **Features**: Post type and category filtering, force rebuild option, internal/external/all scope for broken link checks, configurable result limits
- **Use Cases**: SEO link auditing, orphaned content discovery, broken link detection

**GitHub Tools** — multi-tool class (@since v0.24.0, **moved to data-machine-code extension**)
- `create_github_issue` — Create a GitHub issue in a repository. Async — uses System Agent for execution.
- `list_github_issues` — List issues from a GitHub repository with state, label, and pagination filters
- `get_github_issue` — Get a single GitHub issue with full details including body, labels, and comments
- `manage_github_issue` — Update, close, or comment on a GitHub issue
- `list_github_pulls` — List pull requests from a repository with state filtering
- `list_github_repos` — List GitHub repositories for a user or organization
- **Configuration**: GitHub PAT required
- **Use Cases**: Bug reports, feature requests, task tracking from AI workflows

**Workspace Tools** — multi-tool class (@since v0.37.0, **moved to data-machine-code extension**)
- `workspace_path` — Get the Data Machine workspace path, optionally ensure it exists
- `workspace_list` — List repositories currently present in the workspace
- `workspace_show` — Show detailed repo info (branch, remote, latest commit, dirty count)
- `workspace_ls` — List directory contents within a workspace repository
- `workspace_read` — Read a text file from a workspace repo with optional offset/limit for large files
- **Configuration**: None required
- **Use Cases**: Repository browsing, code review, workspace navigation

**Image Generation** (`image_generation`)
- **Purpose**: Generate images using AI models (Google Imagen 4, Flux, etc.) via Replicate. Returns a URL to the generated image. Async — uses System Agent.
- **Configuration**: Replicate API key required
- **Features**: Default aspect ratio 3:4 (portrait, ideal for Pinterest/blog featured images). Supports 1:1, 3:4, 4:3, 9:16, 16:9 ratios. Configurable model selection.
- **Use Cases**: Featured image generation, visual content creation, illustration

**Amazon Affiliate Link** (`amazon_affiliate_link`) (@since v0.24.0)
- **Purpose**: Search Amazon products and return an affiliate link with the product title, URL, and thumbnail.
- **Configuration**: Amazon affiliate credentials required
- **Features**: Single-query product search, returns product title + affiliate URL + thumbnail
- **Use Cases**: Contextual product recommendations in content, affiliate monetization

**Queue Validator** (`queue_validator`)
- **Purpose**: Check if a topic already exists as a published post or in a Data Machine queue before generating content. Returns "clear" if no duplicates found, or "duplicate" with match details.
- **Configuration**: None required
- **Features**: Title similarity scoring via Jaccard threshold (configurable, default 0.65), checks both published posts and flow queues, supports post type filtering
- **Use Cases**: Duplicate prevention before content generation, queue hygiene

**Google Search Console** (`google_search_console`) (@since v0.25.0)
- **Purpose**: Fetch search analytics from Google Search Console — query performance, page stats, URL inspection, and sitemap management.
- **Configuration**: Service account JSON + site URL required
- **Features**: 8 actions (query_stats, page_stats, query_page_stats, date_stats, inspect_url, list_sitemaps, get_sitemap, submit_sitemap), URL and query filters, date range control
- **Use Cases**: SEO monitoring, indexing verification, search performance analysis
- **Documentation**: [Google Search Console](google-search-console.md)

**Bing Webmaster Tools** (`bing_webmaster`) (@since v0.23.0)
- **Purpose**: Fetch search analytics from Bing Webmaster Tools — query stats, traffic stats, page stats, and crawl stats.
- **Configuration**: API key required
- **Features**: 4 actions (query_stats, traffic_stats, page_stats, crawl_stats), configurable result limits
- **Use Cases**: Bing search performance, crawl monitoring, multi-engine SEO
- **Documentation**: [Bing Webmaster Tools](bing-webmaster.md)

**Google Analytics** (`google_analytics`) (@since v0.31.0)
- **Purpose**: Fetch visitor analytics from Google Analytics (GA4) — page performance, traffic sources, daily trends, real-time users, top events, demographics.
- **Configuration**: Service account JSON + GA4 property ID required (can reuse GSC service account)
- **Features**: 6 actions (page_stats, traffic_sources, date_stats, realtime, top_events, user_demographics), date range control, page path filtering
- **Use Cases**: Traffic analysis, content performance, visitor behavior, real-time monitoring
- **Documentation**: [Google Analytics (GA4)](google-analytics.md)

**PageSpeed Insights** (`pagespeed`) (@since v0.31.0)
- **Purpose**: Run Lighthouse audits via PageSpeed Insights API — performance scores, Core Web Vitals, accessibility, SEO, and optimization opportunities.
- **Configuration**: None required (optional API key for higher rate limits)
- **Features**: 3 actions (analyze, performance, opportunities), mobile/desktop strategies, any public URL
- **Use Cases**: Performance auditing, Core Web Vitals monitoring, optimization planning
- **Documentation**: [PageSpeed Insights](pagespeed-insights.md)

### Chat-Specific Tools

Available only to chat AI agents via `datamachine_chat_tools` filter. These specialized tools provide focused, operation-specific functionality for conversational workflow management:

**ExecuteWorkflow** (`execute_workflow`) (@since v0.3.0)
- **Purpose**: Execute complete multi-step workflows in a single tool call with automatic provider/model defaults injection
- **Configuration**: None required
- **Architecture**: Streamlined single-file implementation at `/inc/Api/Chat/Tools/ExecuteWorkflowTool.php` that delegates execution to the Execute API, with shared handler documentation utilities
- **Use Cases**: Direct workflow execution, ephemeral workflows without pipeline creation

**AddPipelineStep** (`add_pipeline_step`) (@since v0.4.3)
- **Purpose**: Add steps to existing pipelines with automatic flow synchronization
- **Configuration**: None required
- **Features**: Automatically syncs new steps to all flows on the pipeline
- **Use Cases**: Incrementally building pipelines through conversation

**ApiQuery** (`api_query`) (@since v0.4.3)
- **Purpose**: Strictly read-only REST API query tool for discovery, monitoring, and troubleshooting
- **Configuration**: None required
- **Features**: Complete API endpoint catalog with usage examples. Mutation operations are restricted.
- **Use Cases**: System monitoring, handler discovery, job status checking, configuration verification.

**ConfigureFlowSteps** (`configure_flow_steps`) (@since v0.4.2)
- **Purpose**: Configure handler settings and AI user messages for flow steps, supporting both single-step and bulk pipeline-scoped operations
- **Configuration**: None required
- **Features**: 
  - **Single mode**: Configure individual steps.
  - **Bulk mode**: Configure matching steps across all flows in a pipeline.
  - **Handler Switching**: Use `target_handler_slug` to switch handlers with optional `field_map` for data migration.
  - **Per-Flow Config**: Support for unique settings per flow in bulk mode via `flow_configs`.
- **Use Cases**: Setting up fetch/publish/upsert handlers, customizing AI prompts, bulk configuration changes across pipelines, migrating handlers.

**ConfigurePipelineStep** (`configure_pipeline_step`) (@since v0.4.4)
- **Purpose**: Configure pipeline-level AI settings including system prompt, provider, model, and enabled tools
- **Configuration**: None required
- **Features**: Pipeline-wide AI configuration affecting all associated flows
- **Use Cases**: Setting AI provider/model, system prompts, and tool enablement across workflows

**CreateFlow** (`create_flow`) (@since v0.4.2)
- **Purpose**: Create flow instances from existing pipelines with automatic step synchronization
- **Configuration**: None required
- **Features**: Supports manual, recurring, and one-time scheduling
- **Use Cases**: Instantiating pipelines as executable, schedulable flows

**CreatePipeline** (`create_pipeline`) (@since v0.4.3)
- **Purpose**: Create pipelines with optional predefined steps and automatic flow instantiation
- **Configuration**: None required
- **Features**: Automatically creates associated flow, supports AI step configuration in step definitions
- **Use Cases**: Creating complete workflow templates through conversation

**RunFlow** (`run_flow`) (@since v0.4.4)
- **Purpose**: Execute existing flows immediately or schedule delayed execution with job tracking
- **Configuration**: None required
- **Features**: Asynchronous execution via WordPress Action Scheduler, comprehensive job monitoring
- **Use Cases**: Immediate workflow execution, scheduled automation, manual testing

**UpdateFlow** (`update_flow`) (@since v0.4.4)
- **Purpose**: Update flow-level properties including title and scheduling configuration
- **Configuration**: None required
- **Features**: Modify flow names, change scheduling intervals, switch to manual execution
- **Use Cases**: Workflow organization, schedule adjustments, maintenance operations

**DeletePipeline** (`delete_pipeline`)
- **Purpose**: Delete a pipeline and all its associated flows
- **Configuration**: None required
- **Use Cases**: Pipeline cleanup, workflow removal

**DeleteFlow** (`delete_flow`)
- **Purpose**: Delete a flow instance
- **Configuration**: None required
- **Use Cases**: Flow cleanup, removing unused workflow instances

**CopyFlow** (`copy_flow`) (@since v0.6.25)
- **Purpose**: Copy a flow to the same or different pipeline. Cross-pipeline requires compatible step structures. Copies handlers, messages, and schedule.
- **Configuration**: None required
- **Features**: Override schedule and step configs during copy via optional parameters
- **Use Cases**: Flow duplication, cross-pipeline flow migration, template instantiation

**ListFlows** (`list_flows`)
- **Purpose**: List flows with optional filtering by pipeline ID or handler slug. Supports pagination.
- **Configuration**: None required
- **Use Cases**: Flow discovery, workflow inventory, dashboard queries

**DeletePipelineStep** (`delete_pipeline_step`)
- **Purpose**: Remove a step from a pipeline. Cascades removal to all flows on the pipeline.
- **Configuration**: None required
- **Use Cases**: Pipeline step cleanup, simplifying workflow structure

**ReorderPipelineSteps** (`reorder_pipeline_steps`)
- **Purpose**: Reorder steps within a pipeline by providing a new step order array
- **Configuration**: None required
- **Use Cases**: Pipeline restructuring, execution order adjustments

**ManageJobs** (`manage_jobs`) (@since v0.24.0)
- **Purpose**: Manage Data Machine jobs with actions: `list` (with filtering by flow_id, pipeline_id, status), `summary` (counts by status), `delete` (by type: all or failed), `fail` (manually fail a job), `retry` (retry a failed job), `recover` (recover stuck processing jobs).
- **Configuration**: None required
- **Use Cases**: Job monitoring, failure recovery, execution management

**ManageLogs** (`manage_logs`) (@since v0.8.2)
- **Purpose**: Manage Data Machine logs with actions: `clear` (clear logs for agent_id or all), `get_metadata` (get log counts and time range for agent_id or all). Logs are scoped by agent_id.
- **Configuration**: None required
- **Use Cases**: Log maintenance, storage management, log metadata inspection

**ReadLogs** (`read_logs`) (@since v0.8.2)
- **Purpose**: Read Data Machine logs for troubleshooting. Filter by agent_id, job_id, pipeline_id, flow_id (combined with AND logic). Modes: `recent` (default, limited) or `full`.
- **Configuration**: None required
- **Use Cases**: Troubleshooting, execution auditing, error investigation

**ManageQueue** (`manage_queue`) (@since v0.24.0)
- **Purpose**: Manage prompt queues for flow steps with actions: `add`, `list`, `clear`, `remove`, `update`, `move`, `settings`. All actions require flow_id and flow_step_id.
- **Configuration**: None required
- **Use Cases**: Queue management, prompt scheduling, content pipeline control

**SendPing** (`send_ping`) (@since v0.24.0)
- **Purpose**: Send a ping to one or more webhook URLs. Useful for triggering external agents or notifying services.
- **Configuration**: None required
- **Features**: Accepts single or newline-separated URLs, optional prompt for receiving agent
- **Use Cases**: Agent orchestration, webhook notifications, external service triggers

**SystemHealthCheck** (`system_health_check`) (@since v0.24.0)
- **Purpose**: Run unified health diagnostics for Data Machine and extensions. Returns status of various system components.
- **Configuration**: None required
- **Features**: Supports specific check types or "all" for full diagnostics, type-specific options
- **Use Cases**: System monitoring, proactive issue detection, health reporting

**GetProblemFlows** (`get_problem_flows`)
- **Purpose**: Identify flows with issues: consecutive failures (broken) or consecutive no-items runs (source exhausted). Configurable threshold.
- **Configuration**: None required
- **Use Cases**: Proactive flow monitoring, failure detection, source exhaustion alerts

**GetHandlerDefaults** (`get_handler_defaults`)
- **Purpose**: Get site-wide handler defaults. Returns defaults for a specific handler or all handlers.
- **Configuration**: None required
- **Use Cases**: Configuration discovery, understanding site standards before flow setup

**SetHandlerDefaults** (`set_handler_defaults`)
- **Purpose**: Set site-wide handler defaults. Establishes standard configuration values that apply to all new flows.
- **Configuration**: None required
- **Use Cases**: Standardizing handler configuration, site-wide defaults management

**SearchTaxonomyTerms** (`search_taxonomy_terms`)
- **Purpose**: Search existing taxonomy terms to discover what terms exist before creating new ones or configuring handler assignments.
- **Configuration**: None required
- **Use Cases**: Term discovery, duplicate prevention, handler configuration

**CreateTaxonomyTerm** (`create_taxonomy_term`)
- **Purpose**: Create a taxonomy term if it does not exist. Supports hierarchical terms with parent assignment.
- **Configuration**: None required
- **Use Cases**: Taxonomy setup during flow configuration, creating categories/tags on demand

**AssignTaxonomyTerm** (`assign_taxonomy_term`)
- **Purpose**: Assign a taxonomy term to one or more posts. Can append to existing terms or replace them.
- **Configuration**: None required
- **Use Cases**: Bulk term assignment, content categorization, taxonomy management

**MergeTaxonomyTerms** (`merge_taxonomy_terms`)
- **Purpose**: Merge two taxonomy terms into one. Reassigns all posts from source to target, optionally merges meta data, then deletes the source term.
- **Configuration**: None required
- **Use Cases**: Consolidating duplicate terms, taxonomy cleanup

**AuthenticateHandler** (`authenticate_handler`) (@since v0.6.1)
- **Purpose**: Manage authentication for handlers with actions: `list` (all handlers requiring auth), `status` (specific handler), `configure` (save credentials), `get_oauth_url` (authorization URL for OAuth), `disconnect` (remove auth).
- **Configuration**: None required
- **Use Cases**: Handler authentication setup, OAuth flow management, credential management

**DeleteFile** (`delete_file`)
- **Purpose**: Delete an uploaded file. Requires flow_step_id to identify the file scope.
- **Configuration**: None required
- **Use Cases**: File cleanup, storage management

### Handler-Specific Tools

Available only when the adjacent step matches the handler slug or type, registered into the unified `datamachine_tools` registry as `_handler_callable` entries:

**Publishing Tools**:
- `twitter_publish` - Post to Twitter (280 char limit)
- `bluesky_publish` - Post to Bluesky (300 char limit)  
- `facebook_publish` - Post to Facebook (no limit)
- `threads_publish` - Post to Threads (500 char limit)
- `wordpress_publish` - Create WordPress posts; accepts `content_format` (`markdown`, `html`, or `blocks`) and stores content in the post type's configured format
- `google_sheets_publish` - Add data to Google Sheets

**Update Tools**:
- `wordpress_update` - Modify existing WordPress content

## Tool Architecture

### Registration System

**Global Tools** (available to all AI agents - pipeline + chat + standalone):
```php
// Registered via datamachine_global_tools filter
add_filter('datamachine_global_tools', function($tools) {
    $tools['google_search'] = [
        'class' => 'DataMachine\\Engine\\AI\\Tools\\GoogleSearch',
        'method' => 'handle_tool_call',
        'description' => 'Search Google for information',
        'requires_config' => true,
        'parameters' => [
            'query' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Search query'
            ]
        ]
    ];
    return $tools;
}, 10, 1);
```

## Tool Directory Structure

Global tools are located in `/inc/Engine/AI/Tools/Global/`:
- `AgentMemory.php` - Section-based persistent memory read/write (MEMORY.md)
- `AgentDailyMemory.php` - Daily memory journal file access (daily/YYYY/MM/DD.md)
- `AmazonAffiliateLink.php` - Amazon product search with affiliate links
- `BingWebmaster.php` - Bing Webmaster Tools analytics (delegates to `BingWebmasterAbilities`)
- `GitHubTools.php` - GitHub repository operations (issues, PRs, repos — multi-tool)
- `GoogleAnalytics.php` - Google Analytics GA4 data (delegates to `GoogleAnalyticsAbilities`)
- `GoogleSearch.php` - Web search with Custom Search API
- `GoogleSearchConsole.php` - Google Search Console analytics (delegates to `GoogleSearchConsoleAbilities`)
- `ImageGeneration.php` - AI image generation via Replicate (async, System Agent)
- `InternalLinkAudit.php` - Internal link auditing, orphan detection, broken link checks
- `LocalSearch.php` - WordPress internal search
- `PageSpeed.php` - PageSpeed Insights Lighthouse audits (delegates to `PageSpeedAbilities`)
- `QueueValidator.php` - Flow queue duplicate validation before content generation
- `WebFetch.php` - Web page content retrieval
- `WordPressPostReader.php` - Single post analysis
- `WorkspaceTools.php` - Workspace repository operations (**moved to data-machine-code extension**)

Additional global tools outside the Global directory:
- `GitHubIssueTool.php` (`/inc/Engine/AI/Tools/`) - GitHub issue creation (**moved to data-machine-code extension**)

Analytics abilities are located in `/inc/Abilities/Analytics/`:
- `GoogleSearchConsoleAbilities.php` - GSC API integration and JWT auth
- `BingWebmasterAbilities.php` - Bing Webmaster API integration
- `GoogleAnalyticsAbilities.php` - GA4 Data API integration and JWT auth
- `PageSpeedAbilities.php` - PageSpeed Insights API integration

Chat-specific tools at `/inc/Api/Chat/Tools/`:
- `AddPipelineStep.php` - Add steps to pipelines with flow synchronization
- `ApiQuery.php` - REST API discovery and queries with comprehensive endpoint documentation
- `AssignTaxonomyTerm.php` - Assign taxonomy terms to posts
- `AuthenticateHandler.php` - Handler authentication management (OAuth, credentials)
- `ConfigureFlowSteps.php` - Flow step configuration (single and bulk modes)
- `ConfigurePipelineStep.php` - Pipeline-level AI settings configuration
- `CopyFlow.php` - Flow duplication within or across pipelines
- `CreateFlow.php` - Flow instance creation with scheduling support
- `CreatePipeline.php` - Pipeline creation with optional predefined steps
- `CreateTaxonomyTerm.php` - Taxonomy term creation
- `DeleteFile.php` - Uploaded file deletion
- `DeleteFlow.php` - Flow deletion
- `DeletePipeline.php` - Pipeline and associated flows deletion
- `DeletePipelineStep.php` - Pipeline step removal with flow cascade
- `ExecuteWorkflowTool.php` - Direct workflow execution with Execute API delegation
- `GetHandlerDefaults.php` - Site-wide handler defaults retrieval
- `GetProblemFlows.php` - Problem flow detection (failures, exhausted sources)
- `ListFlows.php` - Flow listing with filtering and pagination
- `ManageJobs.php` - Job management (list, summary, delete, fail, retry, recover)
- `ManageLogs.php` - Log management (clear, metadata)
- `ManageQueue.php` - Flow step queue management (add, list, clear, remove, update, move)
- `MergeTaxonomyTerms.php` - Taxonomy term merging and consolidation
- `ReadLogs.php` - Log reading with filtering and mode selection
- `ReorderPipelineSteps.php` - Pipeline step reordering
- `RunFlow.php` - Flow execution and scheduling with job tracking
- `SearchTaxonomyTerms.php` - Taxonomy term search and discovery
- `SendPing.php` - Webhook ping for agent orchestration
- `SetHandlerDefaults.php` - Site-wide handler defaults configuration
- `SystemHealthCheck.php` - System health diagnostics
- `UpdateFlow.php` - Flow property updates and scheduling modifications

Handler-specific tools registered into the unified `datamachine_tools` registry using HandlerRegistrationTrait in each handler class. Each entry carries a `_handler_callable` that is resolved at pipeline execution time with the adjacent step's runtime handler config.

## Tool Management

**ToolManager** (`/inc/Engine/AI/Tools/ToolManager.php`) centralizes tool discovery and validation:
- `get_all_tools()` - Discover all tools
- `is_tool_available()` - Validate global and step-specific enablement
- `is_tool_configured()` - Check configuration requirements
- `get_opt_out_defaults()` - WordPress-native tools (no config needed)

**BaseTool** (`/inc/Engine/AI/Tools/BaseTool.php`) provides unified base class for all AI tools with standardized registration and error handling.

**Chat-Specific Tools** (available only to chat AI agents):
```php
// Registered via datamachine_chat_tools filter
add_filter('datamachine_chat_tools', function($tools) {
    $tools['create_pipeline'] = [
        'class' => 'DataMachine\\Api\\Chat\\Tools\\CreatePipeline',
        'method' => 'handle_tool_call',
        'description' => 'Create a new pipeline with optional steps',
        'parameters' => [/* ... */]
    ];
    return $tools;
});
```

**Handler-Specific Tools** (available when adjacent step matches handler slug or type):
```php
// Registered into the unified datamachine_tools registry as a deferred
// _handler_callable entry. Preferred path is HandlerRegistrationTrait.
add_filter('datamachine_tools', function($tools) {
    $tools['__handler_tools_twitter'] = [
        '_handler_callable' => function($handler_slug, $handler_config, $engine_data) {
            return [
                'twitter_publish' => [
                    'class'          => 'Twitter\\Handler',
                    'method'         => 'handle_tool_call',
                    'handler'        => $handler_slug,
                    'description'    => 'Post to Twitter',
                    'parameters'     => ['content' => ['type' => 'string', 'required' => true]],
                    'handler_config' => $handler_config,
                ],
            ];
        },
        'handler'      => 'twitter',
        'modes'        => ['pipeline'],
        'access_level' => 'admin',
    ];
    return $tools;
});
```

### Discovery Hierarchy

**ToolManager** implements three-layer validation for tool availability:

1. **Global Level**: Admin settings enable/disable tools site-wide
2. **Modal Level**: Per-step tool selection in pipeline configuration
3. **Runtime Level**: Configuration validation checks at execution

**Validation Flow**:
```php
$tool_manager = new ToolManager();

// Layer 1: Global enablement
$is_globally_enabled = $tool_manager->is_globally_enabled('google_search');

// Layer 2: Step-specific selection
    $step_context_id = 'pipeline_step_id_here'; // Example placeholder step context ID
    $is_step_enabled = $tool_manager->is_step_tool_enabled($step_context_id, 'google_search');

// Layer 3: Configuration requirements
    $is_configured = $tool_manager->is_tool_configured('google_search');

// Final availability
$is_available = $is_globally_enabled && $is_step_enabled && $is_configured;
```

See Tool Manager for complete documentation.

## Tool Execution Architecture

### ToolExecutor Pattern

All tools integrate via the universal `ToolExecutor` class
(`/inc/Engine/AI/Tools/ToolExecutor.php`) for execution, and the
`ToolPolicyResolver` for discovery:

```php
// Tool discovery
$resolver        = new \DataMachine\Engine\AI\Tools\ToolPolicyResolver();
$available_tools = $resolver->resolve( array(
    'mode'             => \DataMachine\Engine\AI\Tools\ToolPolicyResolver::MODE_PIPELINE,
    'pipeline_step_id' => $flow_step_id,
    'engine_data'      => $engine_data,
) );
```

**Discovery Process**:
1. **Handler Tools**: Retrieved from the `datamachine_tools` registry — `_handler_callable` entries resolved per adjacent step
2. **Global Tools**: Retrieved via `datamachine_global_tools` filter
3. **Chat Tools**: Retrieved via `datamachine_chat_tools` filter (chat agent only)
4. **Enablement Check**: Each tool filtered through `datamachine_tool_enabled`

### Filter-Based Enablement

Tools can be enabled/disabled per agent type via filters:

```php
add_filter('datamachine_tool_enabled', function($enabled, $tool_id, $agent_type) {
    if ($agent_type === 'chat' && $tool_id === 'create_pipeline') {
        return true;  // Chat-only tool
    }
    return $enabled;
}, 10, 3);
```

### Parameter Building

`ToolParameters` (`/inc/Engine/AI/Tools/ToolParameters.php`) provides unified parameter construction:

**Standard Tools** (global tools):
```php
$parameters = \DataMachine\Engine\AI\ToolParameters::buildParameters(
    $data,
    $job_id,
    $flow_step_id
);
// Returns: ['content_string' => ..., 'title' => ..., 'job_id' => ..., 'flow_step_id' => ...]
```

**Handler Tools** (publish/upsert handlers):
```php
$parameters = \DataMachine\Engine\AI\ToolParameters::buildForHandlerTool(
    $data,
    $tool_def,
    $job_id,
    $flow_step_id
);
// Returns: [...standard params, 'source_url' => ..., 'image_url' => ..., 'tool_definition' => ..., 'handler_config' => ...]
```

**Benefits**:
- Handler tools receive engine data (source_url, image_url) for link attribution and post identification
- Global tools receive clean data packets for content processing
- All tools receive job_id and flow_step_id context for tracking
- Unified flat parameter structure for AI simplicity

## Tool Interface

### `handle_tool_call()` Method

All tools implement the same interface:

```php
public function handle_tool_call(array $parameters, array $tool_def = []): array
```

**Parameters**:
- `$parameters` - AI-provided parameters (validated against tool definition)
- `$tool_def` - Complete tool definition including configuration

**Return Format**:
```php
[
    'success' => true|false,
    'data' => $result_data, // Tool-specific response data
    'error' => 'error_message', // Only if success = false
    'tool_name' => 'tool_identifier'
]
```

### Parameter Validation

**Required Parameters**:
```php
if (empty($parameters['query'])) {
    return [
        'success' => false,
        'error' => 'Missing required query parameter',
        'tool_name' => 'google_search'
    ];
}
```

**Type Validation**:
- `string` - Text content, URLs, identifiers
- `integer` - Numeric values, IDs, counts
- `boolean` - True/false flags

## Configuration Management

### Configuration Requirements

**Requires Config Flag**:
```php
'requires_config' => true // Shows configure link in UI
```

**Configuration Storage**:
- Global tools: WordPress options table
- Handler tools: Handler-specific configuration
- OAuth tools: Separate OAuth storage system

### Configuration Validation

```php
add_filter('datamachine_tool_configured', function($configured, $tool_id) {
    switch ($tool_id) {
        case 'google_search':
            $config = get_option('datamachine_search_config', []);
            $google_config = $config['google_search'] ?? [];
            return !empty($google_config['api_key']) && !empty($google_config['search_engine_id']);
        
    }
    return $configured;
}, 10, 2);
```

## AI Integration

### Tool Selection

AI agents receive available tools based on:
1. **Global Settings** - Admin-enabled tools
2. **Step Configuration** - Modal-selected tools  
3. **Handler Context** - Next step handler type
4. **Configuration Status** - Tools with valid configuration

### Tool Descriptions

**AI-Optimized Descriptions**:
- Clear purpose and capabilities
- Usage instructions for AI
- Parameter requirements and formats
- Expected return data structure

**Example**:
```php
'description' => 'Search Google for current information and context. Provides real-time web data to inform content creation, fact-checking, and research. Use max_results to control response size.'
```

### Conversation Integration

**Universal Engine Architecture** - Tool execution flows through centralized Engine components:

**AIConversationLoop** (`/inc/Engine/AI/AIConversationLoop.php`):
- Multi-turn conversation execution with automatic tool calling
- Executes tools returned by AI and appends results to conversation
- Continues conversation loop until AI completes without tool calls
- Prevents infinite loops with maximum turn counter

**ToolExecutor** (`/inc/Engine/AI/Tools/ToolExecutor.php`):
- Universal tool discovery via `getAvailableTools()` method
- Filter-based tool enablement per agent type (pipeline vs chat)
- Handler tool and global tool integration
- Tool configuration validation

**ToolParameters** (`/inc/Engine/AI/Tools/ToolParameters.php`):
- Centralized parameter building for all AI tools
- `buildParameters()` for standard AI tools with clean data extraction
- `buildForHandlerTool()` for handler tools with engine parameters (source_url, image_url)
- Flat parameter structure for AI simplicity

**ConversationManager** (`/inc/Engine/AI/ConversationManager.php`):
- Message formatting utilities for AI providers
- Tool call recording and tracking
- Conversation message normalization
- Chronological message ordering

**RequestBuilder** (`/inc/Engine/AI/RequestBuilder.php`):
- Centralized AI request construction for all agents
- Directive application system (global, agent-specific, pipeline, chat)
- Tool restructuring for AI provider compatibility
- Integration with ai-http-client library

**Tool Results Processing**:
- Tool responses formatted by ConversationManager for AI consumption
- Structured data converted to human-readable success messages
- Platform-specific messaging enables natural AI agent conversation termination
- Multi-turn context preservation via AIConversationLoop

## Tool Implementation Examples

### Global Tool (Google Search)

```php
class GoogleSearch {
    public function handle_tool_call(array $parameters, array $tool_def = []): array {
        $config = apply_filters('datamachine_get_tool_config', [], 'google_search');
        $api_key = $config['api_key'] ?? '';
        $search_engine_id = $config['search_engine_id'] ?? '';
        if (empty($api_key) || empty($search_engine_id)) {
            return [ 'success' => false, 'error' => 'Google Search not configured', 'tool_name' => 'google_search' ];
        }
        $query = $parameters['query'];
        $results = $this->perform_search($query, $api_key, $search_engine_id, 10); // Fixed size
        return [
            'success' => true,
            'data' => [ 'results' => $results, 'query' => $query, 'total_results' => count($results) ],
            'tool_name' => 'google_search'
        ];
    }
}
```

### Handler Tool (Twitter Publish)

```php
class TwitterHandler {
    public function handle_tool_call(array $parameters, array $tool_def = []): array {
        // Get handler configuration
        $handler_config = $tool_def['handler_config'] ?? [];
        $twitter_config = $handler_config['twitter'] ?? [];
        
        // Process content
        $content = $parameters['content'];
        $formatted_content = $this->format_for_twitter($content, $twitter_config);
        
        // Publish to Twitter
        $result = $this->publish_tweet($formatted_content);
        
        return [
            'success' => true,
            'data' => [
                'tweet_id' => $result['id'],
                'tweet_url' => $result['url'],
                'content' => $formatted_content
            ],
            'tool_name' => 'twitter_publish'
        ];
    }
}
```

## Error Handling

### Configuration Errors

**Missing Configuration**:
- Tool returns error with configuration instructions
- UI shows configure link for unconfigured tools
- Runtime validation prevents broken tool calls

**Invalid Configuration**:
- API key validation during configuration save
- OAuth token refresh on authentication errors
- Clear error messages for troubleshooting

### Runtime Errors

**API Failures**:
- Network errors logged and returned to AI
- Rate limiting handled gracefully
- Service outages communicated clearly

**Parameter Errors**:
- Type validation with specific error messages
- Required parameter checking
- Format validation for complex parameters

## Performance Considerations

### Request Optimization

**External API Calls**:
- Single request per tool execution
- Timeout handling with WordPress defaults
- No automatic retries (AI can retry if needed)

**Data Processing**:
- Minimal memory usage during processing
- Streaming for large responses
- Efficient JSON parsing and formatting

### Caching Strategy

**Search Results**: Not cached (real-time data priority)
**Configuration Data**: Cached in WordPress options
**OAuth Tokens**: Cached with automatic refresh

## Extension Development

### Custom Global Tool

```php
class CustomTool {
    public function __construct() {
        // Self-register via datamachine_global_tools filter
        add_filter('datamachine_global_tools', [$this, 'register_tool'], 10, 1);
    }

    public function register_tool($tools) {
        $tools['custom_tool'] = [
            'class' => __CLASS__,
            'method' => 'handle_tool_call',
            'description' => 'Custom data processing tool',
            'requires_config' => false,
            'parameters' => [
                'input' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Data to process'
                ]
            ]
        ];
        return $tools;
    }

    public function handle_tool_call(array $parameters, array $tool_def = []): array {
        // Validate parameters
        if (empty($parameters['input'])) {
            return [
                'success' => false,
                'error' => 'Missing input parameter',
                'tool_name' => 'custom_tool'
            ];
        }

        // Process data
        $result = $this->process_data($parameters['input']);

        return [
            'success' => true,
            'data' => ['processed_result' => $result],
            'tool_name' => 'custom_tool'
        ];
    }
}

// Self-register the tool
new CustomTool();
```
