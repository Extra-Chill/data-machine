=== Data Machine ===
Contributors: extrachill
Tags: ai, automation, content, workflow, pipeline
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 0.32.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-first WordPress plugin for visual pipeline automation, chat, and REST API control.

== Description ==

Data Machine is a powerful WordPress automation plugin that combines AI processing with visual workflow building. Create reusable pipeline templates, configure specific flow instances, and automate content workflows across multiple platforms.

**Key Features:**

* **Modern React Admin Interface** - Complete Pipelines page with 6,591 lines of React code using @wordpress/element and @wordpress/components
* **Zero jQuery/AJAX Architecture** - Modern React frontend with REST API integration
* **Tool-First AI** - Universal Engine architecture with multi-turn conversation loops and centralized tool execution
* **Visual Pipeline Builder** - Real-time updates with 50+ React components and Context API state management
* **Multi-Provider AI** - Support for OpenAI, Anthropic, Google, Grok, and OpenRouter (200+ models)
* **Complete REST API** - 16 endpoints for flow execution, pipeline management, and system monitoring
* **Chat API** - Conversational interface for building and executing workflows through natural language
* **Pipeline+Flow Architecture** - Pipelines are reusable templates, Flows are configured instances

**Requirements:**

* WordPress 6.9 or higher
* PHP 8.2 or higher
* Action Scheduler (woocommerce/action-scheduler)
* At least one AI provider API key (OpenAI, Anthropic, Google, Grok, or OpenRouter)

**Example Workflow:**

Create a "Content Enhancement" pipeline with three steps: Fetch → AI → Update. Configure a flow instance to fetch old blog posts from WordPress, enhance them with AI using Google Search for research, then update the original posts with improved content.

**Available Handlers:**

Fetch Sources:
* Local/remote files
* RSS feeds
* Reddit posts
* WordPress (local posts, media, external sites via REST API)
* Google Sheets

Publish Destinations:
* Twitter, Bluesky, Threads, Facebook
* WordPress (with modular components for images, taxonomies, and source attribution)
* Google Sheets

Update Handlers:
* WordPress Update (modify existing posts/pages)

AI Tools:
* Google Search
* Local WordPress Search
* Web Fetch
* WordPress Post Reader

== Installation ==

**Automatic Installation:**

1. Log in to your WordPress admin panel
2. Navigate to Plugins → Add New
3. Search for "Data Machine"
4. Click "Install Now" and then "Activate"
5. Go to Settings → Data Machine to configure your AI provider

**Manual Installation:**

1. Download the plugin ZIP file
2. Upload to `/wp-content/plugins/` directory
3. Extract the ZIP file
4. Activate the plugin through the 'Plugins' menu in WordPress
5. Configure your AI provider at Settings → Data Machine

**Configuration:**

1. Navigate to Settings → Data Machine
2. Add your AI provider API key (OpenAI, Anthropic, Google, Grok, or OpenRouter)
3. (Optional) Configure Google Search API credentials for AI search tool
4. (Optional) Set up OAuth connections for social media publishing
5. Create your first pipeline at Data Machine → Pipelines

== Frequently Asked Questions ==

= What AI providers are supported? =

Data Machine supports OpenAI, Anthropic (Claude), Google Gemini, Grok (X.AI), and OpenRouter. OpenRouter provides access to 200+ models from multiple providers.

= Do I need to configure all AI providers? =

No, you only need to configure at least one AI provider to use Data Machine. You can add additional providers later.

= What external services does this plugin connect to? =

The plugin can connect to AI providers (OpenAI, Anthropic, Google, Grok, OpenRouter), social media platforms (Twitter, Facebook, Threads, Bluesky), content sources (Reddit, Google Sheets), and optional tools (Google Custom Search). All connections are user-configured and opt-in only. See the External Services section for complete details.

= Does this plugin send data automatically? =

No. The plugin only connects to external services when you explicitly create and execute a workflow. No automatic connections are made without your configuration and execution.

= What is the difference between a Pipeline and a Flow? =

Pipelines are reusable templates that define the structure of a workflow (e.g., Fetch → AI → Publish). Flows are configured instances of pipelines with specific handlers and scheduling (e.g., RSS → OpenAI → Twitter, running hourly).

= Can I use this without the admin interface? =

Yes. Data Machine provides a complete REST API with 16 endpoints. You can execute flows, manage pipelines, and monitor jobs entirely via API calls. 

= How do I schedule automated workflows? =

When creating a flow instance, configure the scheduling settings to run at specific intervals (hourly, daily, weekly, etc.). The plugin uses Action Scheduler for reliable background execution.

= Can I update existing WordPress posts? =

Yes. Use the WordPress Update handler in your workflow. The fetch handler provides a source_url that the update handler uses to identify which post to modify.

= Is there a file size limit for processing? =

The plugin can process files of various sizes. Web fetch operations have a 50K character limit. File uploads are subject to your WordPress/server upload limits.

== External Services ==

This plugin connects to third-party services for AI processing, content fetching, and publishing. All external connections are **user-initiated** through workflow configuration. No data is sent to external services without explicit user setup and workflow execution.

= AI Providers (User Configured, Optional) =

Users must configure at least one AI provider to use Data Machine's workflow automation features. API keys and configuration are stored locally in your WordPress database.

**OpenAI** - https://openai.com/
* Purpose: AI text processing and content generation for workflow automation
* Data Sent: User-configured prompts, content for processing, selected AI model preferences
* When: During flow execution when OpenAI is selected as the AI provider
* Terms of Service: https://openai.com/policies/terms-of-use
* Privacy Policy: https://openai.com/policies/privacy-policy

**Anthropic (Claude)** - https://anthropic.com/
* Purpose: AI text processing and content generation for workflow automation
* Data Sent: User-configured prompts, content for processing, selected AI model preferences
* When: During flow execution when Anthropic is selected as the AI provider
* Terms of Service: https://www.anthropic.com/legal/consumer-terms
* Privacy Policy: https://www.anthropic.com/legal/privacy

**Google Gemini** - https://ai.google.dev/
* Purpose: AI text processing and content generation for workflow automation
* Data Sent: User-configured prompts, content for processing, selected AI model preferences
* When: During flow execution when Google is selected as the AI provider
* Terms of Service: https://ai.google.dev/gemini-api/terms
* Privacy Policy: https://policies.google.com/privacy

**Grok (X.AI)** - https://x.ai/
* Purpose: AI text processing and content generation for workflow automation
* Data Sent: User-configured prompts, content for processing, selected AI model preferences
* When: During flow execution when Grok is selected as the AI provider
* Terms of Service: https://x.ai/legal/terms-of-service
* Privacy Policy: https://x.ai/legal/privacy-policy

**OpenRouter** - https://openrouter.ai/
* Purpose: AI model routing and processing (provides access to 200+ AI models from multiple providers)
* Data Sent: User-configured prompts, content for processing, selected AI model preferences
* When: During flow execution when OpenRouter is selected as the AI provider
* Terms of Service: https://openrouter.ai/terms
* Privacy Policy: https://openrouter.ai/privacy

= Content Fetch Handlers (User Configured, Optional) =

**Google Sheets API** - https://developers.google.com/sheets/api
* Purpose: Read data from Google Sheets spreadsheets for content workflows
* Data Sent: OAuth2 credentials, spreadsheet IDs, range specifications
* When: During flow execution with Google Sheets fetch handler enabled
* Terms of Service: https://developers.google.com/terms
* Privacy Policy: https://policies.google.com/privacy

**Reddit API** - https://www.reddit.com/dev/api
* Purpose: Fetch posts and comments from specified subreddits for content workflows
* Data Sent: OAuth2 credentials, subreddit names, search queries, timeframe filters
* When: During flow execution with Reddit fetch handler enabled
* Terms of Service: https://www.redditinc.com/policies/user-agreement
* Privacy Policy: https://www.reddit.com/policies/privacy-policy

= Content Publish Handlers (User Configured, Optional) =

**Twitter API** - https://developer.twitter.com/
* Purpose: Post tweets with media support as part of content publishing workflows
* Data Sent: OAuth 1.0a credentials, tweet content (max 280 characters), media files
* When: During flow execution with Twitter publish handler enabled
* Terms of Service: https://developer.twitter.com/en/developer-terms/agreement-and-policy
* Privacy Policy: https://twitter.com/en/privacy

**Facebook Graph API** - https://developers.facebook.com/
* Purpose: Post content to Facebook pages as part of content publishing workflows
* Data Sent: OAuth2 credentials, post content, media files, page access tokens
* When: During flow execution with Facebook publish handler enabled
* Terms of Service: https://developers.facebook.com/terms
* Privacy Policy: https://www.facebook.com/privacy/policy

**Threads API** - https://developers.facebook.com/docs/threads
* Purpose: Post content to Instagram Threads as part of content publishing workflows
* Data Sent: OAuth2 credentials (via Facebook authentication), post content (max 500 characters), media files
* When: During flow execution with Threads publish handler enabled
* Terms of Service: https://developers.facebook.com/terms
* Privacy Policy: https://help.instagram.com/privacy/

**Bluesky (AT Protocol)** - https://bsky.app/
* Purpose: Post content to Bluesky social network as part of content publishing workflows
* Data Sent: App password credentials, post content (max 300 characters), media files
* When: During flow execution with Bluesky publish handler enabled
* Terms of Service: https://bsky.social/about/support/tos
* Privacy Policy: https://bsky.social/about/support/privacy-policy

**Google Sheets API** (Publishing)
* Purpose: Write data to Google Sheets spreadsheets as part of content publishing workflows
* Data Sent: OAuth2 credentials, spreadsheet IDs, row data for insertion
* When: During flow execution with Google Sheets publish handler enabled
* Terms of Service: https://developers.google.com/terms
* Privacy Policy: https://policies.google.com/privacy

= AI Tools (User Configured, Optional) =

**Google Custom Search API** - https://developers.google.com/custom-search
* Purpose: Provide web search functionality for AI agents during content research and generation
* Data Sent: API key, Custom Search Engine ID, search queries initiated by AI
* When: When AI uses the Google Search tool during workflow execution (requires user-configured API credentials)
* Terms of Service: https://developers.google.com/terms
* Privacy Policy: https://policies.google.com/privacy
* Free Tier: 100 queries per day

= Data Handling and Privacy =

* **Local Storage**: All API keys, OAuth credentials, and configuration data are stored locally in your WordPress database
* **User Control**: Users have complete control over which services are configured and when workflows execute
* **No Automatic Connections**: The plugin makes no external connections until a user explicitly creates and executes a workflow
* **Data Transmission**: Only user-configured content and prompts are sent to external services during workflow execution
* **Opt-In Only**: All external service integrations require explicit user configuration and are disabled by default

== Screenshots ==

1. Visual Pipeline Builder - Create reusable workflow templates with drag-and-drop interface
2. Flow Configuration - Configure specific instances with handlers and scheduling
3. Job Monitoring - Track workflow executions and view detailed logs
4. Settings Panel - Configure AI providers, tools, and WordPress defaults
5. Chat Interface - Build workflows through conversational AI

== Changelog ==

= 0.10.2 =
* Improved: WordPress source attribution now detects Gutenberg blocks and uses proper block markup for the attribution paragraph to avoid mixed content.
* Fixed: Updated .buildignore to ensure local /build/ directories are properly excluded while preserving necessary assets.
* Testing: Added unit tests for WordPress source attribution logic.

= 0.9.12 =
* Improved: ApiQuery tool error reporting with specific error messages for HTTP failures.
* Fixed: Flow selection dropdown in Jobs UI (data path correction).
* Refined: Chat Sidebar UI now explicitly labels tool results and filters redundant assistant tool calls.
* Refined: AI conversation loop now treats duplicate tool calls as tool failures rather than user messages.

= 0.9.11 =
* Google Sheets: Refactored authentication to lazy-load the auth provider only when needed, improving performance and avoiding unnecessary API calls.
* API: Added `AuthProviderService::clearCache()` and integrated it into the centralized `CacheManager` to ensure auth provider caches are synchronized during dynamic registration.
* Chat Agent: Simplified `ExecuteWorkflowTool` documentation by removing redundant taxonomy configuration examples, relying on the unified handler documentation instead.
* Core: Standardized auth provider cache invalidation across the plugin lifecycle.

= 0.8.16 =
* Settings: Added new Tool Configuration UI in the Agent tab, allowing users to configure tool-specific settings directly.
* API: Added new REST API endpoints for retrieving and saving tool-specific configurations.
* Chat Agent: Enhanced ConfigureFlowSteps tool with support for unique settings per flow in bulk operations.
* Chat Agent: Documented and integrated the UpdateTaxonomyTerm tool for term modification and venue data management.
* Core: Improved tool registration with support for dynamic configuration field definitions.
* Core: Integrated Universal Web Scraper into the engine documentation and handlers overview.

= 0.8.15 =
* Chat Agent: Improved Tool Call extraction to prioritize metadata, ensuring more reliable tool execution tracking.
* Chat Agent: Fixed tool parameter truncation in log messages and conversation history.
* UI: Enhanced Chat Sidebar with better word-wrapping for long strings and code blocks.
* UI: Improved Chat Input loading states and accessibility.
* API: Removed redundant `oauth-url` endpoint in favor of unified `status` endpoint.

= 0.8.14 =
* Chat Agent: Enhanced ConfigureFlowSteps tool with handler switching, automated field mapping, and bulk flow configuration overrides.
* Core: Standardized flow ID handling to integer types across configuration services.

= 0.8.13 =
* Core: Implemented reliability-first Single Item Execution Model across the entire engine and handler architecture.
* Chat Agent: Added UpdateTaxonomyTerm specialized tool for agent-led term modification and venue data management.
* UI: Standardized AI configuration components across Pipelines and Settings pages for improved consistency.
* Documentation: Comprehensive architectural updates reflecting the Single Item Execution Model and reliability-first design.

= 0.8.12 =
* Core: Implemented Shared Authentication support, allowing handlers to share credentials via a centralized provider key.
* Flows: Refined flow duplication to ensure only scheduling intervals are copied, excluding run history and status metadata.
* Services: Added automatic configuration cleaning when switching handlers in a flow step to prevent legacy field leakage.
* API: Added settings_summary to flow steps for improved UI overview display.
* Settings: Renamed admin localization object to dataMachineSettingsConfig to prevent collisions.
* Testing: Enhanced test bootstrap with support for the datamachine-events extension and improved database table setup.

= 0.8.11 =
* API: Added PATCH /flows/steps/{id}/config endpoint for incremental configuration updates.
* API: Added GET /pipelines/{id} endpoint for single pipeline retrieval.
* Settings: Simplified admin page management by removing "Enabled Pages" (all pages now enabled by default).
* Core: Centralized scheduler intervals in SchedulerIntervals.php.
* Tools: Improved ConfigureFlowSteps tool with more flexible bulk and single-step configuration logic.

= 0.8.10 =
* Chat Agent UI: Integrated react-markdown for rich text rendering in the chat sidebar.
* Chat Agent Styling: Added comprehensive CSS for headers, lists, and code blocks in chat messages.
* Scheduling Refinement: Separated recurring intervals from manual and one-time scheduling logic for improved reliability.
* AI Tools: Updated CreateFlow, UpdateFlow, CopyFlow, and CreatePipeline tools with explicit non-recurring scheduling support.
* Maintenance: Cleaned up stale React build assets and updated build configurations.

= 0.8.9 =
* Ephemeral Workflows: Support for temporary workflows that execute without database persistence.
* Expanded Scheduling: Added every_5_minutes, every_2_hours, every_4_hours, qtrdaily, and twicedaily intervals.
* Chat Tool UI: New ToolMessage.jsx component and expanded interval support in tools.

= 0.8.8 =
* Settings UI Optimization: Simplified AI provider API key configuration while maintaining server-side security.
* Autoloading Refactor: Migrated to pure PSR-4 autoloading by removing manual file inclusion in composer.json.
* Testing Suite Improvements: Enhanced test bootstrap with better environment mocks and autoloader prioritization.

= 0.8.7 =
* AI Provider Key Masking: Implemented server-side masking for AI provider API keys in the Settings API.
* Key Status Feedback: Added visual "Saved" badges and improved "Clear" functionality for API keys.
* WP-Unit Testing Suite: Integrated wp-phpunit/wp-phpunit and standard WordPress test bootstrap.

= 0.8.4 =
* New AI Chat Tools: Expanded agent capabilities with ReadLogs, ManageLogs, SearchTaxonomyTerms, and CreateTaxonomyTerm tools
* Enhanced Logging System: Centralized logging in the Step base class with automatic job/pipeline/flow context injection
* Improved Log Filtering: Added support for filtering log content by pipeline_id and flow_id in the REST API and Logs interface
* UI State Management: Implemented optimistic updates for log clearing and visual feedback for log copy actions
* Agent Intelligence: Improved taxonomy discovery and management guidance in ChatAgentDirective
* Code Consistency: Refactored FetchStep and WordPress handlers to use the improved centralized logging system

= 0.8.2 =
* Lazy Tool Resolution: Implemented lazy loading for AI tool definitions to prevent WordPress 6.7+ translation errors
* Service Layer Caching: Added centralized caching to HandlerService and StepTypeService for 2x discovery performance
* Cache Invalidation System: New CacheManager service with dynamic invalidation hooks for handlers and step types
* Chat Context Enrichment: Enhanced AI chat with selected_pipeline_id for improved agent awareness
* Refined Tool Registration: Updated all chat tools to support lazy evaluation and dynamic documentation

= 0.8.0 =
* Integrated Chat Sidebar: New React-based AI chat interface embedded directly into the Pipeline Builder
* React Logs Interface: Complete migration of the Logs management page to modern React architecture
* Added StepTypeRegistrationTrait for standardized step type registration
* Added AgentContext and AgentType for managing specialized AI personalities
* Consolidated shared React components and styles into shared admin directory
* Removed individual filter classes for core steps in favor of trait-based registration
* Refined PipelineManager and PipelineStepManager with standardized patterns
* Deleted legacy admin-logs.js and associated PHP templates

= 0.7.1 =
* Refined ChatAgentDirective with architecture-focused instructions
* Enhanced ChatPipelinesDirective with flow summaries for improved agent learning
* Centralized handler configuration default logic into HandlerService
* Optimized flow update logic to ensure consistent default application
* Removed legacy tool definitions from composer classmap

= 0.7.0 =
* Added PluginSettings class for centralized settings access with request-level caching
* Added EngineData::getPipelineStepConfig() for pipeline step configuration retrieval
* Migrated 15+ files from scattered get_option() calls to centralized PluginSettings::get()
* Changed social media handler include_images default to false (Twitter, Bluesky, Threads)
* AIStep now falls back to default provider when pipeline step provider not configured
* Fixed settings option key typo in activation defaults
* Removed redundant debug logging from Bluesky and Threads handlers
* Added cache management and logger documentation

= 0.2.4 =
* Handler architecture refactoring: consolidated registration by removing individual filter files
* Schedule API consolidation: removed standalone endpoint, integrated into Flows API
* Added FlowScheduling.php for advanced flow scheduling operations
* Added ModalManager.jsx for centralized modal rendering
* Added useFormState.js hook for generic form state management
* Added FailJob.php for dedicated job failure handling
* Removed 14 redundant handler filter files (~800 lines)
* Removed Schedule.php API endpoint (292 lines)
* Improved React component state management and error handling
* Architecture simplification: reduced codebase by ~500 lines

= 0.2.3 =
* Major React architecture modernization: TanStack Query + Zustand state management
* Eliminated context-based state management for improved performance
* Intelligent caching with automatic background refetching
* Granular component updates - no more global refreshes
* Optimistic UI updates for better user experience
* Cleaner separation of server state (TanStack Query) and UI state (Zustand)
* Enhanced developer experience with better error handling and loading states

= 0.2.2 =
* Added HandlerRegistrationTrait for standardized handler registration
* Reduced code duplication by ~70% across handler registration files
* Improved WordPress filter integration with auto-registration patterns
* Enhanced tool registration system with dynamic filter creation

= 0.2.1 =
* Base class architecture implementation reducing code duplication
* FilesRepository modular architecture with specialized components
* WordPress shared components for centralized functionality
* StepNavigator for centralized step execution flow
* DataPacket standardization replacing scattered array construction

= 0.2.0 =
* Major architectural improvements to Universal Engine system
* Added complete REST API with 16 endpoints
* Implemented Chat API for conversational workflow building
* Added ephemeral workflow support (execute without database persistence)
* Enhanced handler system with universal filter patterns
* Improved cache management with granular clearing
* Added centralized engine data architecture
* Performance optimizations: 50% query reduction in handler operations
* WordPress prefix migration: dm_ → datamachine_ for WordPress.org compliance
* Complete React admin interface rebuild (6,591 lines)
* Zero jQuery/AJAX architecture with modern REST API integration

= 0.1.0 =
* Initial release
* Pipeline+Flow architecture implementation
* Multi-provider AI integration
* Visual workflow builder
* OAuth authentication system
* Action Scheduler integration

== Upgrade Notice ==

= 0.2.0 =
Major update with REST API, Chat interface, and performance improvements. Includes prefix migration from dm_ to datamachine_. All existing pipelines and flows will be automatically migrated.

== Developer Documentation ==

For technical specifications, architecture details, and development guides, see the CLAUDE.md file included with the plugin or visit the GitHub repository.

**REST API Documentation:** Available in docs/api/index.md

**Extension Development:** Complete framework supporting custom handlers, AI tools, and database services with filter-based auto-discovery.

== Source Code ==

This plugin includes minified JavaScript files compiled from source. The complete source code is included in the plugin package.

**React Admin Interface Source:**
* Location: `inc/Core/Admin/Pages/Pipelines/assets/react/`
* Entry point: `index.jsx`
* Components: 50+ React components using @wordpress/element

**Build Instructions:**
1. Install dependencies: `npm install`
2. Build for production: `npm run build`
3. Output location: `inc/Core/Admin/Pages/Pipelines/assets/build/`

**Dependencies:**
* @wordpress/element
* @wordpress/components
* @tanstack/react-query

== Support ==

For bug reports, feature requests, and technical support, please visit the GitHub repository or contact the developer.

**Developer:** Chris Huber - https://chubes.net
**GitHub:** https://github.com/chubes4
