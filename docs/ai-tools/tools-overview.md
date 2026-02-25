# AI Tools Overview

AI tools provide capabilities to AI agents for interacting with external services, processing data, and performing research tasks. Data Machine supports both global tools and handler-specific tools.

## Tool Categories

### Global Tools (Universal)

Available to all AI agents (pipeline + chat) via `datamachine_global_tools` filter:

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
- **Use Cases**: Setting up fetch/publish/update handlers, customizing AI prompts, bulk configuration changes across pipelines, migrating handlers.

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

### Handler-Specific Tools

Available only when next step matches the handler type, registered via `chubes_ai_tools` filter:

**Publishing Tools**:
- `twitter_publish` - Post to Twitter (280 char limit)
- `bluesky_publish` - Post to Bluesky (300 char limit)  
- `facebook_publish` - Post to Facebook (no limit)
- `threads_publish` - Post to Threads (500 char limit)
- `wordpress_publish` - Create WordPress posts
- `google_sheets_publish` - Add data to Google Sheets

**Update Tools**:
- `wordpress_update` - Modify existing WordPress content

## Tool Architecture

### Registration System

**Global Tools** (available to all AI agents - pipeline + chat):
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
- `GoogleSearch.php` - Web search with Custom Search API
- `LocalSearch.php` - WordPress internal search
- `WebFetch.php` - Web page content retrieval
- `WordPressPostReader.php` - Single post analysis
- `GoogleSearchConsole.php` - Google Search Console analytics (delegates to `GoogleSearchConsoleAbilities`)
- `BingWebmaster.php` - Bing Webmaster Tools analytics (delegates to `BingWebmasterAbilities`)
- `GoogleAnalytics.php` - Google Analytics GA4 data (delegates to `GoogleAnalyticsAbilities`)
- `PageSpeed.php` - PageSpeed Insights Lighthouse audits (delegates to `PageSpeedAbilities`)

Analytics abilities are located in `/inc/Abilities/Analytics/`:
- `GoogleSearchConsoleAbilities.php` - GSC API integration and JWT auth
- `BingWebmasterAbilities.php` - Bing Webmaster API integration
- `GoogleAnalyticsAbilities.php` - GA4 Data API integration and JWT auth
- `PageSpeedAbilities.php` - PageSpeed Insights API integration

Chat-specific tools at `/inc/Api/Chat/Tools/`:
- `ExecuteWorkflowTool.php` - Direct workflow execution with Execute API delegation
- `AddPipelineStep.php` - Add steps to pipelines with flow synchronization
- `ApiQuery.php` - REST API discovery and queries with comprehensive endpoint documentation
- `ConfigureFlowSteps.php` - Flow step configuration (single and bulk modes)
- `ConfigurePipelineStep.php` - Pipeline-level AI settings configuration
- `CreateFlow.php` - Flow instance creation with scheduling support
- `CreatePipeline.php` - Pipeline creation with optional predefined steps
- `RunFlow.php` - Flow execution and scheduling with job tracking
- `UpdateFlow.php` - Flow property updates and scheduling modifications

Handler-specific tools registered via `chubes_ai_tools` filter using HandlerRegistrationTrait in each handler class.

## Tool Management

**ToolManager** (`/inc/Engine/AI/Tools/ToolManager.php`) centralizes tool discovery and validation:
- `get_global_tools()` - Discover global tools
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

**Handler-Specific Tools** (available when next step matches handler type):
```php
// Registered via chubes_ai_tools filter with handler context
add_filter('chubes_ai_tools', function($tools, $handler_slug = null, $handler_config = []) {
    if ($handler_slug === 'twitter') {
        $tools['twitter_publish'] = [
            'class' => 'Twitter\\Handler',
            'method' => 'handle_tool_call',
            'handler' => 'twitter',
            'description' => 'Post to Twitter',
            'parameters' => ['content' => ['type' => 'string', 'required' => true]],
            'handler_config' => $handler_config
        ];
    }
    return $tools;
}, 10, 3);
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

All tools integrate via the universal `ToolExecutor` class (`/inc/Engine/AI/Tools/ToolExecutor.php`):

```php
// Tool discovery
$available_tools = \DataMachine\Engine\AI\ToolExecutor::getAvailableTools(
    $agent_type,  // 'pipeline' or 'chat'
    $handler_slug,
    $handler_config,
    $flow_step_id
);
```

**Discovery Process**:
1. **Handler Tools**: Retrieved via `chubes_ai_tools` filter for specific handler
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

**Handler Tools** (publish/update handlers):
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