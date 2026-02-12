# Data Machine Architecture

Data Machine is an AI-first WordPress plugin that uses a Pipeline+Flow architecture for automated content processing and publication. It provides multi-provider AI integration with tool-first design patterns, centered around a reliability-first **Single Item Execution Model**.

## Core Components

### Pipeline+Flow System
- **Pipelines**: Reusable templates containing step configurations
- **Flows**: Configured instances of pipelines with scheduling
- **Jobs**: Individual executions of flows with status tracking, each processing exactly one item

### Execution Engine
Services layer architecture with direct method calls for optimal performance. The engine implements a four-action execution cycle that processes exactly one item per job to ensure maximum reliability and isolation.

### Database Schema
- `wp_datamachine_pipelines` - Pipeline templates (reusable)
- `wp_datamachine_flows` - Flow instances (scheduled + configured)
- `wp_datamachine_logs` - Centralized system logs for all agent activity (@since v0.4.0)
- `wp_datamachine_processed_items` - Deduplication tracking per execution

### Engine Data Architecture

**Clean Data Separation**: AI agents receive clean data packets without URLs while handlers access engine parameters via centralized filter pattern.

**Enhanced Database Storage + Filter Access**: Fetch handlers store engine parameters (source_url, image_url) in database; steps retrieve via centralized `datamachine_engine_data` filter with storage/retrieval mode detection for unified access.

**Core Pattern**:
```php
// Fetch handlers store via centralized filter (array storage)
if ($job_id) {
    apply_filters('datamachine_engine_data', null, $job_id, [
        'source_url' => $source_url,
        'image_url' => $image_url
    ]);
}

// Steps retrieve via centralized filter (EngineData.php)
$engine_data = apply_filters('datamachine_engine_data', [], $job_id);
$source_url = $engine_data['source_url'] ?? null;
$image_url = $engine_data['image_url'] ?? null;
```

**Benefits**:
- **Clean AI Data**: AI processes content without URLs for better model performance
- **Centralized Access**: Single filter interface for all engine data retrieval
- **Filter Consistency**: Maintains architectural pattern of filter-based service discovery
- **Flexible Storage**: Steps access only what they need via filter call

### Services Layer Architecture (@since v0.4.0)
**Performance Revolution**: Complete replacement of filter-based action system with OOP service managers for 3x performance improvement through direct method calls. Most services have been migrated to the WordPress 6.9 Abilities API.

**Remaining Services** (utilities for cross-cutting concerns):
- **JobManager** - Job execution monitoring and management
- **LogsManager** - Centralized log access and filtering
- **Cache Invalidation** - Ability-level `clearCache()` methods for handlers, step types, tools, and settings

**Abilities API** (business logic):
- **FlowAbilities** - Flow CRUD operations, duplication
- **PipelineAbilities** - Pipeline CRUD operations with complete/simple creation modes
- **FlowStepAbilities** - Individual flow step configuration and handler management
- **PipelineStepAbilities** - Pipeline step template management
- **ProcessedItemsAbilities** - Deduplication tracking across workflows

**Benefits**:
- **3x Performance Improvement**: Direct method calls eliminate filter indirection
- **Centralized Business Logic**: Consistent validation and error handling
- **Reduced Database Queries**: Optimized data access patterns
- **Clean Architecture**: Single responsibility per ability class
- **Backward Compatibility**: Maintains WordPress hook integration

### Step Types
- **Fetch**: Data retrieval with clean content processing (Files, RSS, Reddit, Google Sheets, WordPress Local, WordPress Media, WordPress API)
- **AI**: Content processing with multi-provider support (OpenAI, Anthropic, Google, Grok)
- **Publish**: Content distribution with modular handler architecture (Twitter, Facebook, Threads, Bluesky, WordPress with specialized components)
- **Update**: Content modification (WordPress posts/pages)

### Authentication System

**Base Authentication Provider Architecture** (@since v0.2.6): Complete inheritance system with centralized option storage and validation across all authentication providers.

**Base Classes**:
- **BaseAuthProvider** (`/inc/Core/OAuth/BaseAuthProvider.php`): Abstract base for all authentication providers with unified option storage, callback URL generation, and authentication state checking
- **BaseOAuth1Provider** (`/inc/Core/OAuth/BaseOAuth1Provider.php`): OAuth 1.0a providers (TwitterAuth) extending BaseAuthProvider
- **BaseOAuth2Provider** (`/inc/Core/OAuth/BaseOAuth2Provider.php`): OAuth 2.0 providers (RedditAuth, FacebookAuth, ThreadsAuth, GoogleSheetsAuth) extending BaseAuthProvider

**OAuth Handlers**:
- **OAuth1Handler** (`/inc/Core/OAuth/OAuth1Handler.php`): Three-legged OAuth 1.0a flow implementation
- **OAuth2Handler** (`/inc/Core/OAuth/OAuth2Handler.php`): Authorization code flow implementation

**Authentication Providers**:
- **OAuth 1.0a**: TwitterAuth extends BaseOAuth1Provider
- **OAuth 2.0**: RedditAuth, FacebookAuth, ThreadsAuth, GoogleSheetsAuth extend BaseOAuth2Provider
- **Direct**: BlueskyAuth extends BaseAuthProvider (app password authentication)

**OAuth2 Flow**:
1. Create state nonce for CSRF protection
2. Build authorization URL with parameters
3. Handle callback: verify state, exchange code for token, retrieve account details, store credentials

**OAuth1 Flow**:
1. Get request token
2. Build authorization URL
3. Handle callback: validate parameters, exchange for access token, store credentials

**Benefits**:
- Eliminates duplicated storage logic across all providers (~60% code reduction per provider)
- Standardized error handling and logging
- Unified security implementation
- Easy integration of new providers via base class extension

### Universal Engine Architecture

Data Machine v0.2.0 introduced a universal Engine layer (`/inc/Engine/AI/`) that serves both Pipeline and Chat agents with shared AI infrastructure:

**Core Engine Components**:

- **AIConversationLoop**: Multi-turn conversation execution with tool calling, completion detection, and state management
- **ToolExecutor**: Universal tool discovery, enablement validation, and execution across agent types
- **ToolParameters**: Centralized parameter building for AI tools with data packet integration
- **ConversationManager**: Message formatting and conversation state management
- **RequestBuilder**: AI request construction with directive application and tool restructuring
- **ToolResultFinder**: Utility for finding tool execution results in data packets

**Tool Categories**:
- Handler-specific tools for publish/update operations
- Global tools for search and analysis (GoogleSearch, LocalSearch, WebFetch, WordPressPostReader)
- Chat-only tools for workflow building (@since v0.4.3):
  - AddPipelineStep, ApiQuery, AuthenticateHandler, ConfigureFlowSteps, ConfigurePipelineStep, CopyFlow, CreateFlow, CreatePipeline, CreateTaxonomyTerm, ExecuteWorkflowTool, GetHandlerDefaults, ManageLogs, ReadLogs, RunFlow, SearchTaxonomyTerms, SetHandlerDefaults, UpdateFlow
- Automatic tool discovery and three-layer enablement system

### Filter-Based Discovery
All components self-register via WordPress filters:
- `datamachine_handlers` - Register fetch/publish/update handlers
- `chubes_ai_tools` - Register AI tools and capabilities
- `datamachine_auth_providers` - Register authentication providers
- `datamachine_step_types` - Register custom step types
- `datamachine_get_oauth1_handler` - OAuth 1.0a handler service discovery
- `datamachine_get_oauth2_handler` - OAuth 2.0 handler service discovery

### Modular Component Architecture (@since v0.2.1)

Data Machine v0.2.1 introduced modular component systems for enhanced code organization and maintainability:

**FilesRepository Components** (`/inc/Core/FilesRepository/`):
- **DirectoryManager** - Directory creation and path management
- **FileStorage** - File operations and flow-isolated storage
- **FileCleanup** - Retention policy enforcement and cleanup
- **ImageValidator** - Image validation and metadata extraction
- **RemoteFileDownloader** - Remote file downloading with validation
- **FileRetrieval** - Data retrieval from file storage

**WordPress Shared Components** (`/inc/Core/WordPress/`):
- **TaxonomyHandler** - Taxonomy selection and term creation (skip, AI-decided, pre-selected modes)
- **WordPressSettingsHandler** - Shared WordPress settings fields
- **WordPressFilters** - Service discovery registration

**EngineData** (`/inc/Core/EngineData.php`):
- **Consolidated Operations** - Featured image attachment, source URL attribution, and engine data access (@since v0.2.1, enhanced v0.2.6)
- **Unified Interface** - Single class for all engine data operations (replaces FeaturedImageHandler and SourceUrlHandler in v0.2.6)

**Engine Components** (`/inc/Engine/`):
- **StepNavigator** - Centralized step navigation logic for execution flow

**Benefits**:
- **Code Deduplication**: Eliminates repetitive functionality across handlers
- **Single Responsibility**: Each component has focused purpose
- **Maintainability**: Centralized logic simplifies updates
- **Extensibility**: Easy to add new functionality via composition

For detailed documentation:
- FilesRepository Components
- WordPress Shared Components
- EngineData
- StepNavigator

### Centralized Handler Filter System

**Unified Cross-Cutting Functionality**: The engine provides centralized filters for shared functionality across multiple handlers, eliminating code duplication and ensuring consistency.

**Core Centralized Filters**:
- **`datamachine_timeframe_limit`**: Shared timeframe parsing with discovery/conversion modes
  - Discovery mode: Returns available timeframe options for UI dropdowns
  - Conversion mode: Returns Unix timestamp for specified timeframe
  - Used by: RSS, Reddit, WordPress Local, WordPress Media, WordPress API
- **`datamachine_keyword_search_match`**: Universal keyword matching with OR logic
  - Case-insensitive Unicode-safe matching
  - Comma-separated keyword support
  - Used by: RSS, Reddit, WordPress Local, WordPress Media, WordPress API
- **`datamachine_data_packet`**: Standardized data packet creation and structure
  - Ensures type and timestamp fields are present
  - Maintains chronological ordering via array_unshift()
  - Used by: All step types for consistent data flow

**Implementation**:
```php
// Timeframe parsing example
$cutoff_timestamp = apply_filters('datamachine_timeframe_limit', null, '24_hours');
$date_query = $cutoff_timestamp ? ['after' => gmdate('Y-m-d H:i:s', $cutoff_timestamp)] : [];

// Keyword matching example
$matches = apply_filters('datamachine_keyword_search_match', true, $content, $search_keywords);
if (!$matches) continue; // Skip non-matching items

// Data packet creation example
$data = apply_filters('datamachine_data_packet', $data, $packet_data, $flow_step_id, $step_type);
```

**Benefits**:
- **Code Consistency**: Identical behavior across all handlers using shared filters
- **Maintainability**: Single implementation location for shared functionality
- **Extensibility**: New handlers automatically inherit shared capabilities
- **Performance**: Optimized implementations used across all handlers

### WordPress Publish Handler Architecture
**Modular Component System**: The WordPress publish handler uses specialized processing modules for enhanced maintainability and extensibility.

**Core Components**:
- **EngineData**: Consolidated featured image attachment and source URL attribution with configuration hierarchy (system defaults override handler config) (@since v0.2.1, enhanced v0.2.6)
- **TaxonomyHandler**: Configuration-based taxonomy processing with three selection modes (skip, AI-decided, pre-selected)
- **Direct Integration**: WordPress handlers use EngineData and TaxonomyHandler directly for single source of truth data access

**Configuration Hierarchy**: System-wide defaults ALWAYS override handler-specific configuration when set, providing consistent behavior across all WordPress publish operations.

**Features**:
- Specialized component isolation for maintainability
- Configuration validation and error handling per component
- WordPress native function integration for optimal performance
- Comprehensive logging throughout all components
- Unified engine data operations via EngineData class

### File Management

Flow-isolated UUID storage with automatic cleanup:
- Files organized by flow instance
- Automatic purging on job completion
- Support for local and remote file processing

### HTTP Client

The centralized `HttpClient` class (`/inc/Core/HttpClient.php`) standardizes all outbound requests for fetch and publish handlers. It wraps the native WordPress HTTP helpers while:

- exposing explicit methods (`get`, `post`, `put`, `patch`, `delete`) that accept consistent option bags
- merging default headers (plugin `DATAMACHINE_VERSION` and optional browser-mode headers) with user-supplied headers
- honoring `timeout`, `body`, and `browser_mode` options so handlers can simulate browser traffic when needed
- validating success codes per method before returning parsed responses
- logging WP_Error and non-success HTTP responses via `datamachine_log` and returning structured error payloads for downstream handling
- extracting error metadata from JSON bodies to improve diagnostics

See [HTTP Client](core-system/http-client.md) for implementation details and usage guidance.

### Admin Interface

**Modern React Architecture**: The entire Data Machine admin interface (Pipelines, Logs, Settings, and Jobs) uses a complete React implementation with zero jQuery or AJAX dependencies.

**React Implementation**:
- A unified React-based admin UI built with `@wordpress/components`.
- Specialized apps for each page (PipelinesApp, LogsApp, SettingsApp, JobsApp).
- Modern state management using TanStack Query for server state (and a small Zustand store on the Pipelines page for UI state).
- Complete REST API integration for all data operations.
- Real-time updates via TanStack Query background refetching.
- Optimistic UI updates for instant user feedback.

**Component Architecture**:
- **Core**: Page-specific App containers; UI state is either local React state or (for Pipelines) a small Zustand store.
- **Modals**: Centralized `ModalManager` and `ModalSwitch` for routing (Pipelines/Settings).
- **Queries/API**: Standardized TanStack Query hooks and REST client modules.

**Complete REST API Integration**:
All admin pages now use REST API architecture with zero jQuery/AJAX dependencies.

**Security Model**: All admin operations require `manage_options` capability with WordPress nonce validation.

### Extension Framework
Complete extension system for custom handlers and tools:
- Filter-based registration
- Template-driven development
- Automatic discovery and validation
- LLM-assisted development support

## Key Features

### AI Integration
- Support for multiple AI providers (OpenAI, Anthropic, Google, and others)
- **Unified Directive System**: Priority-based directive management via PromptBuilder:
  - `datamachine_directives` - Centralized filter with priority ordering and agent targeting
- **Universal Engine Architecture**: Shared AI infrastructure via `/inc/Engine/AI/` components:
  - AIConversationLoop for multi-turn conversation execution with automatic tool calling
  - ToolExecutor for universal tool discovery and execution
  - ToolParameters for centralized parameter building (`buildParameters()` for standard tools, `buildForHandlerTool()` for handler tools with engine data)
  - ConversationManager for message formatting and conversation utilities
  - RequestBuilder for centralized AI request construction with directive application
  - ToolResultFinder for universal tool result search in data packets
- Site context injection with automatic cache invalidation (`SiteContext::clear_cache()`)
- Tool result formatting with success/failure messages
- Clear tool result messaging enabling natural AI agent conversation termination

### Data Processing
- **Explicit Data Separation Architecture**: Clean data packets for AI processing vs engine parameters for handlers
- **Engine Data Filter Architecture**: Fetch handlers store engine_data (source_url, image_url) in database; steps retrieve via centralized `datamachine_engine_data` filter
- DataPacket structure for consistent data flow with chronological ordering
- Clear data packet structure for AI agents with chronological ordering:
  - Root wrapper with data_packets array
  - Index 0 = newest packet (chronological ordering)
  - Type-specific fields (handler, attachments, tool_name)
  - Workflow dynamics and turn-based updates
- Deduplication tracking
- Comprehensive logging

### Scheduling
- WordPress Action Scheduler integration
- Configurable intervals
- Manual execution support
- Job failure handling

### Security
- Admin-only access (`manage_options` capability)
- CSRF protection via WordPress nonces
- Input sanitization and validation
- Secure OAuth implementation
