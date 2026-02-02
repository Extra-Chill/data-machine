# Changelog

All notable changes to Data Machine will be documented in this file. Also viewable at: 

## [0.19.0] - 2026-02-02

### Added
- QueueableTrait for shared queue pop functionality across step types
- Agent Ping step now supports prompt queue (same as AI step)

### Changed
- AIStep refactored to use QueueableTrait instead of inline queue logic
- Agent Ping includes `from_queue` flag in webhook payload

## [0.18.6] - 2026-02-01

### Changed
- Merge pull request #52 from saraichinwag/fix/restore-prompt-field

### Fixed
- use addToQueue when queue is empty
- restore prompt field alongside queue modal button

## [0.18.5] - 2026-02-01

### Changed
- Merge pull request #51 from saraichinwag/fix/queue-modal-from-step
- Merge pull request #50 from saraichinwag/fix/remove-footer-queue

### Fixed
- open queue modal from step card button
- remove queue button from flow footer

## [0.18.4] - 2026-02-01

### Changed
- Hide handler badge for non-handler steps
- Allow adding prompt to queue when empty

## [0.18.3] - 2026-02-01

### Changed
- Document agent self-orchestration in README and overview
- Extend Agent Ping payload with engine_data context

## [0.18.2] - 2026-02-01

### Changed
- Document AI agent integration in new SKILL.md
- Hide Configure button for non-handler steps while keeping settings display

## [0.18.1] - 2026-02-01

### Changed
- Remove deprecated CLI agent command (#37)

### Fixed
- fix(agent-ping): use flow-level handler_config via abilities pattern (#38)
- fix(agent-ping): use flow-level handler_config instead of pipeline config (#36)
- engine data access bugs in AI step and queue (#35)

## [0.18.0] - 2026-02-01

### Added
- add CLI CRUD commands for pipelines (#33)

### Changed
- modularize abilities files for maintainability (#34)

## [0.17.0] - 2026-02-01

### Added
- add React UI for prompt queue management (#30)

### Fixed
- initialize queue before WP_Ability check for CLI compatibility (#28)

## [0.16.3] - 2026-02-01

### Added
- Prompt Queue for AI Flows (#27)

## [0.16.2] - 2026-02-01

- Bump ai-http-client to v2.0.13

## [0.16.1] - 2026-01-30

### Fixed
- Update DirectoryManager type hints to support direct execution mode (int|string for pipeline/flow IDs)

## [0.16.0] - 2026-01-30

### Changed
- Migrate all file operations to WordPress WP_Filesystem API for Plugin Check compliance
- Add centralized FilesystemHelper for filesystem initialization
- Remove forbidden fallback pattern in RemoteFileDownloader
- Modularize FlowAbilities and FlowStepAbilities into focused ability classes with shared helper traits
- Add explicit selection modes to configure_flow_steps: flow_step_ids array, global handler scope, all_flows opt-in

### Fixed
- Fix configure_flow_steps bulk mode to require explicit opt-in (prevents accidental pipeline-wide updates)

## [0.15.2] - 2026-01-28

### Changed
- Improve create_pipeline UX for AI agents
- Update documentation for clarity and accuracy

### Fixed
- Fix array alignment per WordPress coding standards
- Fix system prompt not appearing in Configure Step modal
- Fix taxonomy selection mismatch between card and modal
- Fix React/API synchronization for step creation and chat invalidation
- Fix chat timestamps incorrectly showing "just now"
- Fix chat loading state bleeding across sessions

## [0.15.1] - 2026-01-28

- Add BaseCommand class with standard WP-CLI format options (table, json, csv, yaml, ids, count)
- Fix type safety and code quality issues across codebase

## [0.15.0] - 2026-01-27

### Changed
- Add async turn-by-turn chat execution
- Add bulk mode to pipeline and flow creation abilities
- improved error handling for chat tools
- Remove PHPUnit from composer dependencies
- Update @wordpress/scripts to fix lodash vulnerabilities

## [0.14.12] - 2026-01-27

### Fixed
- Fix flows run CLI subcommand argument parsing

## [0.14.11] - 2026-01-27

### Changed
- Remove orphaned ToolRegistrationTrait require

### Fixed
- Complete truncated test function

## [0.14.10] - 2026-01-27

### Changed
- Unified BaseTool architecture for all AI tools

## [0.14.9] - 2026-01-27

### Fixed
- Add ChatToolErrorTrait for consistent WP_Error handling in chat tools with error_type classification to prevent AI infinite retry loops

## [0.14.8] - 2026-01-26

### Changed
- expand jobs.status column to varchar(255) for compound statuses with reasons

## [0.14.7] - 2026-01-26

### Added
- Add 'flows run' CLI subcommand for immediate/scheduled flow execution (#13)

## [0.14.6] - 2026-01-26

### Changed
- add stuck job recovery feature with abilities-first architecture
- made sure default model assigned to new pipeline ai steps (based on global settings)

## [0.14.5] - 2026-01-26

### Changed
- Add unified system health check ability with filter-based registration

## [0.14.4] - 2026-01-26

### Changed
- Add current_date to SiteContext for AI date awareness

## [0.14.3] - 2026-01-26

- Add dry-run support to base PublishHandler class for all publish handlers

## [0.14.2] - 2026-01-25

### Removed
- Delete deprecated SessionTitleGenerator.php (superseded by SystemAbilities)

### Fixed
- Move chat session title generation after database persistence to fix stale data issue (#7)

## [0.14.1] - 2026-01-25

### Fixed
- Fix WordPress Abilities API usage - use wp_get_ability()->execute() instead of non-existent wp_execute_ability()
- Fix malformed .gitignore entry that prevented build directory from being ignored
- Clean up duplicate version targets in homeboy configuration for reliable version bumping

## [0.14.0] - 2026-01-25

- Added System Agent Architecture - Hook-based system for infrastructure operations with automatic chat session title generation

## [0.13.6] - 2026-01-25

### Fixed
- Fix pipeline step deletion to sync flows and clean processed items

## [0.13.5] - 2026-01-24

### Fixed
- Fix WP Abilities API late registration warnings causing 'category string' notices in WP-CLI commands

## [0.13.4] - 2026-01-24

### Changed
- Restructure documentation from api-reference to development/hooks directory

### Fixed
- Fix undefined variable warnings in chat tools by adding missing self:: prefix to static property references

## [0.13.3] - 2026-01-24

- Fix uninitialized property errors by initializing instance properties before static registration guard

## [0.13.2] - 2026-01-24

- Remove duplicate datamachine ability category registration

## [0.13.1] - 2026-01-24

- Fix duplicate ability registrations during WP-CLI execution by adding static registration guards to all 14 ability classes

## [0.13.0] - 2026-01-22

- BREAKING: Consolidate singular/plural abilities - remove 7 redundant singular abilities (get-flow, get-job, get-pipeline, get-pipeline-step, get-flow-step, get-handler, get-step-type) in favor of plural abilities with optional ID parameters for single lookups

## [0.12.5] - 2026-01-22

- fix: Correct undefined variable references in DateFormatter static methods
- test: Add DateFormatterTest for comprehensive coverage

## [0.12.4] - 2026-01-20

### Added
- ToolExecutor now validates required parameters before execution with clear error messages
- LocalSearchAbilities class for WordPress 6.9 Abilities API

### Changed
- LocalSearch tool now delegates to LocalSearchAbilities (Abilities API integration)

### Fixed
- Log clearing functions renamed for clarity (datamachine_clear_log_files to datamachine_clear_all_log_files, datamachine_clear_log_file for single agent)

## [0.12.3] - 2026-01-20

- Fix React admin pages blank due to const vs window. declaration mismatch

## [0.12.2] - 2026-01-20

- Fixed regex pattern in admin asset enqueue - now correctly matches WordPress hook suffix format for all admin pages

## [0.12.1] - 2026-01-20

### Fixed
- Resolved blank React admin pages after Abilities API migration by refactoring asset enqueueing to use direct slug extraction instead of options storage

## [0.12.0] - 2026-01-20

### Added
- WordPress 6.9 Abilities API integration with 64 registered abilities across 13 ability classes
- PipelineAbilities with 8 abilities for pipeline CRUD and import/export operations
- PipelineStepAbilities with 6 abilities for step management
- FlowAbilities with 6 abilities for flow CRUD and duplication
- FlowStepAbilities with 4 abilities for flow step configuration
- JobAbilities with 6 abilities for execution, health monitoring, and problem flow detection
- FileAbilities with 5 abilities for file management and uploads
- ProcessedItemsAbilities with 3 abilities for deduplication tracking
- SettingsAbilities with 7 abilities for plugin and handler settings
- AuthAbilities with 3 abilities for OAuth authentication management
- LogAbilities with 6 abilities for logging operations
- HandlerAbilities with 6 abilities for handler discovery and configuration
- StepTypeAbilities with 3 abilities for step type discovery and validation
- PostQueryAbilities with unified query-posts ability supporting handler/flow/pipeline filters

### Changed
- Minimum WordPress requirement bumped to 6.9 for Abilities API support
- REST API endpoints now delegate to Abilities for all business logic
- CLI commands execute Abilities directly for consistent behavior
- Chat tools delegate to Abilities for all mutation operations
- Cache invalidation moved from CacheManager to individual ability classes

### Removed
- Services layer deleted - HandlerService, StepTypeService, PipelineManager, PipelineStepManager, FlowManager, FlowStepManager, ProcessedItemsManager, JobManager, AuthProviderService, LogsManager, CacheManager (~3000 lines removed)

## [0.11.6] - 2026-01-19

- Fixed Yoda fixer breaking null comparisons (self::null -> null)

## [0.11.5] - 2026-01-19

- Added PostTrackingTrait for upsert operations
- Linter and documentation fixes
- Test infrastructure now handled by Homeboy

## [0.11.4] - 2026-01-18

- Fixed: DATAMACHINE_VERSION constant now matches plugin header version (0.11.3)

## [0.11.3] - 2026-01-17

- {"component_id":"data-machine","type":"Removed","summary":"Removed unused post_date_source setting"}

## [0.11.3] - 2026-01-17

- {"component_id":"data-machine","type":"Removed","summary":"Removed unused post_date_source setting"}

## [0.11.2] - 2026-01-16

- Fixed: Chat session deduplication now catches sessions with status=processing in metadata, preventing duplicate sessions on Cloudflare timeout
- Changed: Updated ai-http-client to 2.0.12 for improved invalid JSON response handling

## [0.11.1] - 2026-01-16

- Fixed: Pass explicit agent_type in chat session creation and API queries to fix session listing and creation errors

## [0.11.0] - 2026-01-15

- Add WP-CLI agent command for chat interactions.
- Chat sessions table now records agent_type for chat and CLI sessions.

## [0.10.3] - 2026-01-15

- Docs: clarify direct execution cycle and WP-CLI agent usage.
- Docs: expand wp-ai-client migration blocker details for handler tools.

## [0.10.2] - 2026-01-08

### Changed
- **Chat tool message grouping** - `inc/Core/Admin/Pages/Pipelines/assets/react/components/chat/ChatMessages.jsx` now groups tool call/result messages within a single exchange using position-based buffering and pairs tool calls/results by tool name for display.
- **Chat request id propagation** - `inc/Core/Admin/Pages/Pipelines/assets/react/components/chat/ChatSidebar.jsx` now generates a `requestId` once per send and passes it into the request; `inc/Core/Admin/Pages/Pipelines/assets/react/queries/chat.js` now accepts `requestId` from the caller rather than generating it internally.

## [0.10.1] - 2026-01-08

### Fixed
- **WordPress publish empty-content guard** - `inc/Core/Steps/Publish/Handlers/WordPress/WordPress.php` now validates that `content` is not empty after `wp_filter_post_kses()` sanitization and returns a structured error response when sanitization strips everything, preventing accidental publication of empty posts.

## [0.10.0] - 2026-01-08

### Added
- **ExecutionContext** - New `inc/Core/ExecutionContext.php` centralizes flow vs direct execution context, deduplication checks, engine snapshot access, file context, and handler-scoped logging helpers.

### Changed
- **Flows scheduling contract** - `inc/Api/Flows/Flows.php` now delegates schedule updates to `inc/Api/Flows/FlowScheduling.php` and standardizes manual/one-time/recurring scheduling updates.
- **Flow status metadata sourcing** - Flow list/response metadata now derives last-run status/running state and next scheduled run from jobs history + Action Scheduler rather than flow row fields.
- **Job status finalization** - `inc/Core/Database/Jobs/JobsStatus.php` now validates completion using `JobStatus::isStatusFinal()` (supports compound statuses).
- **Fetch handler execution context** - `inc/Core/Steps/Fetch/Handlers/FetchHandler.php` and fetch handlers (Files/RSS/Reddit/Google Sheets/WordPress*) now consume `ExecutionContext` for consistent engine data access (e.g. `source_url`, `image_file_path`) and direct-mode compatibility.

### Improved
- **Flows UI status display** - `inc/Core/Admin/Pages/Pipelines/assets/react/components/flows/FlowCard.jsx` and `inc/Core/Admin/Pages/Pipelines/assets/react/components/flows/FlowFooter.jsx` now display a "Running" state and refine last-run status styling.

### Fixed
- **Direct execution job IDs** - `inc/Api/Execute.php` now creates direct execution jobs with `pipeline_id='direct'` and `flow_id='direct'` for consistent downstream handling.
- **Direct execution file context** - `inc/Api/Files.php` now supports `flow_id='direct'` in `get_file_context()`.

### Testing
- Updated `tests/Unit/Services/FlowManagerTest.php` to reflect the new flow metadata and scheduling behavior.

## [0.9.16] - 2026-01-07

### Improved
- **Chat session durability** - `inc/Api/Chat/Chat.php` now persists the user message immediately (status `processing`) and records failures as session status `error` with `error_message` when the AI loop returns an error or throws.
- **Chat completion status** - `inc/Api/Chat/Chat.php` now includes `status=completed` in the final session metadata.
- **ApiQuery parameter validation** - `inc/Api/Chat/Tools/ApiQuery.php` now requires either `endpoint` (single mode) or `requests` (batch mode) and returns a clear error directing external URLs to `web_fetch`.

### Changed
- **Direct execution identifiers** - `inc/Api/Execute.php` now marks direct execution jobs with `pipeline_id='direct'` and `flow_id='direct'` in engine configs (stored as `0` in the DB).
- **Job creation direct-mode normalization** - `inc/Core/Database/Jobs/JobsOperations.php` treats `'direct'` (or `0,0`) as direct execution and stores IDs as `0` while still rejecting mixed/invalid pipeline/flow ID combinations.
- **FetchHandler flow ID typing** - `inc/Core/Steps/Fetch/Handlers/FetchHandler.php` now supports `flow_id='direct'` in handler configs and returns `int|string` from `getFlowId()`.

## [0.9.15] - 2026-01-07

### Improved
- **Admin status display unification** - `inc/Core/Admin/assets/css/root.css` adds shared status utility classes (success/error/warning/neutral) and `inc/Core/Admin/Pages/Jobs/assets/react/components/JobsTable.jsx` now applies them based on the base job status (including compound statuses).
- **Flow last-run status visibility** - `inc/Core/Admin/Pages/Pipelines/assets/react/components/flows/FlowFooter.jsx` now displays the last run job status next to the last-run timestamp.
- **LocalSearch missing-query guidance** - `inc/Engine/AI/Tools/Global/LocalSearch.php` now returns a more actionable error message when `query` is missing.

### Changed
- **DateFormatter status suffix removal** - `inc/Core/Admin/DateFormatter.php` now returns only formatted timestamps and no longer appends status-specific suffixes.
- **Jobs page status CSS** - `inc/Core/Admin/Pages/Jobs/assets/css/jobs-page.css` removes job-status color classes in favor of the shared status utilities.
- **Dependency update** - `composer.lock` updates `chubes4/ai-http-client` from `v2.0.10` to `v2.0.11`.

## [0.9.14] - 2026-01-07

### Improved
- **Chat sessions table name hardening** - `inc/Core/Database/Chat/Chat.php` now centralizes table name sanitization/escaping and uses an identifier placeholder (`%i`) for safer table-name interpolation.
- **Engine data packet retrieval** - `inc/Engine/Actions/Engine.php` now retrieves step input packets via `FileRetrieval::retrieve_data_by_job_id()` using file context derived from the step’s `flow_id`.

### Fixed
- **Core action parameter validation** - `inc/Engine/Actions/DataMachineActions.php` now validates required params and `job_id` for `datamachine_mark_item_processed` (and logs clearer errors) before writing processed items.

## [0.9.13] - 2026-01-06

### Improved
- **Block-aware Source Attribution** - `inc/Core/WordPress/WordPressPublishHelper.php` now detects if content contains Gutenberg blocks and appends source attribution using proper `<!-- wp:paragraph -->` markup when necessary, preventing mixed HTML/block validation errors.

### Fixed
- **Build exclusions** - `.buildignore` updated to use `/build/` pattern for better directory matching.

### Added
- **Unit tests for WordPressPublishHelper** - `tests/Unit/WordPress/WordPressPublishHelperTest.php` provides coverage for the new block-aware attribution logic.

## [0.9.12] - 2026-01-06

### Improved
- **ApiQuery tool error reporting** - `inc/Api/Chat/Tools/ApiQuery.php` now provides specific error messages and HTTP status codes for failed requests, improving diagnostic visibility for AI agents.

### Fixed
- **Flow selection dropdown in Jobs UI** - `inc/Core/Admin/Pages/Jobs/assets/react/queries/jobs.js` corrects the data path (`response.data.flows`) for fetching flows, fixing the empty dropdown in the Jobs filter.

### Refined
- **Chat Sidebar UI tool result labeling** - `inc/Core/Admin/Pages/Pipelines/assets/react/components/chat/ChatSidebar.jsx` now explicitly labels tool results with name and success/failure status, and filters redundant assistant tool-call messages for a cleaner conversation history.
- **Duplicate tool call handling** - `inc/Engine/AI/AIConversationLoop.php` and `inc/Engine/AI/ConversationManager.php` now treat duplicate tool calls as failed tool results rather than user correction messages, improving consistency in the conversation loop.

## [0.9.11] - 2026-01-06

### Added
- **LocalSearch fallback search strategies** - `inc/Engine/AI/Tools/Global/LocalSearch.php` adds a title-only query mode (`title_only=true`) and automatic fallbacks (title matching and comma/semicolon split queries) when a standard WordPress search returns no results.

### Changed
- **Jobs table schema supports compound statuses** - `inc/Core/Database/Jobs/Jobs.php` expands the `datamachine_jobs.status` column size from `varchar(20)` to `varchar(100)` and includes an upgrade path that alters the column on existing installs.

### Fixed
- **Engine refresh captures tool-set job status** - `inc/Engine/Actions/Engine.php` refreshes `EngineData` after step execution so tools like `skip_item` can reliably set `job_status` before the job is finalized.

## [0.9.10] - 2026-01-06

### Added
- **Chat request idempotency support** - `inc/Api/Chat/Chat.php` accepts `X-Request-ID` and caches the REST response in a 60s transient to avoid duplicate AI runs when the client re-sends the same request.

### Improved
- **Chat UI double-submit hardening** - `inc/Core/Admin/Pages/Pipelines/assets/react/components/chat/ChatInput.jsx` adds a lightweight submit cooldown to prevent rapid-fire Enter submissions; `inc/Core/Admin/Pages/Pipelines/assets/react/components/chat/ChatSidebar.jsx` prevents concurrent "create new session" requests.
- **Chat request headers** - `inc/Core/Admin/Pages/Pipelines/assets/react/queries/chat.js` generates a request UUID and sends it as `X-Request-ID` with chat POST requests.
- **WordPress Post Reader tool contract** - `inc/Engine/AI/Tools/Global/WordPressPostReader.php` clarifies that `source_url` must be a WordPress permalink/shortlink and explicitly rejects REST API URLs.

## [0.9.9] - 2026-01-06

### Changed
- **Chat session list query** - `inc/Core/Database/Chat/Chat.php` updates the sessions query to `SELECT *` and adds null-safe handling for `messages`, `title`, `created_at`, and `updated_at` when assembling the sessions list response.
- **Pipelines UI styling** - `inc/Core/Admin/Pages/Pipelines/assets/css/pipelines-page.css` constrains the `.datamachine-pipeline-selector` width on desktop breakpoints.

## [0.9.8] - 2026-01-06

### Added
- **Persistent Chat Session Listing/Deletion** - New REST endpoints `GET /datamachine/v1/chat/sessions` (paginated) and `DELETE /datamachine/v1/chat/{session_id}` with user ownership enforcement.
- **Automatic Chat Session Titles** - New `ChatTitleGenerator` generates titles from the first user message (AI-generated when possible, with deterministic truncation fallback) and stores `title`, `provider`, and `model` per session.
- **Chat Sessions UI Components** - New React components for switching sessions (`ChatSessionSwitcher.jsx`) and browsing session history (`ChatSessionList.jsx`).

### Improved
- **Chat Session DB Contract** - `datamachine_chat_sessions` records now persist `title`, `provider`, and `model`, and expose `updated_at` ordering for recent sessions.
- **RunFlow Tool Behavior** - Tool definition and handler enforce: omit `timestamp` for immediate execution; support `count` (1–10) for multiple immediate runs; reject `timestamp` with `count > 1`.
- **Pipeline Builder Chat Sidebar** - UI and React Query layer updated to handle multiple sessions and improved message/session loading flows.
- **Settings UI (General Tab)** - Added/updated global settings fields in React (`GeneralTab.jsx`) and settings REST contract to support them.

### Fixed
- **Job Status Naming in Docs/Tools** - Consistently use `processing` for running jobs across docs and tool descriptions.

### Technical Details
- **New Chat session queries**: Added query/mutation helpers in `inc/Core/Admin/Pages/Pipelines/assets/react/queries/chat.js` for sessions list, session delete, and session switching.
- **Database methods**: Expanded `inc/Core/Database/Chat/Chat.php` with `get_user_sessions()` / `get_user_session_count()` and session update semantics.

## [0.9.7] - 2026-01-05

### Added
- **JobStatus Value Object** - Centralized job status management in `inc/Core/JobStatus.php` with support for compound statuses like `agent_skipped - reason`.
- **SkipItemTool** - New AI tool for fetch-type handlers allowing agents to explicitly skip items that don't meet processing criteria.
- **Tools Display in Pipeline Builder** - Added a "Tools" label to the Pipeline Step Card to show enabled AI tools for each step.

### Improved
- **Job Monitoring** - Enhanced `JobManager`, `Engine`, and `Flows` to use `JobStatus` for more granular status tracking and consecutive failure/no-items monitoring.
- **Date Formatting** - Updated `DateFormatter` to support localized display of skipped reasons and other compound statuses.

### Technical Details
- **Compound Status Support**: Implemented base status and reason parsing in `JobStatus` for better diagnostic visibility in logs and UI.
- **Tool Registration**: Automated `skip_item` tool registration for all fetch-type handlers via `FetchHandler::init()`.
- **Code Consolidation**: Replaced scattered status strings with `JobStatus` constants across core services.

## [0.9.6] - 2026-01-05

### Fixed
- **Tool Name Consistency** - Corrected references from `configure_flow_step` to `configure_flow_steps` across AddPipelineStep, ConfigurePipelineStep, and CreatePipeline tools
- **ApiQuery Parameter Naming** - Fixed UpdateFlow tool parameter from `schedule` to `scheduling_config` for consistency with codebase patterns
- **Chat Tool Response Format** - Added `tool_name` to AuthenticateHandler success/error responses for consistent tool identification

### Improved
- **ApiQuery Tool Simplification** - Streamlined to GET-only read operations, removed method and data parameters to enforce separation of discovery and mutation operations
- **Tool Description Clarity** - Updated ApiQuery documentation to clearly direct mutation operations to focused tools, reducing AI agent confusion
- **Parameter Documentation** - Enhanced ConfigureFlowSteps flow_step_id parameter description with clear format specification

### Technical Details
- **ApiQuery Refactoring**: Removed HTTP method support (POST/PUT/PATCH/DELETE), simplified request handling to GET-only mode
- **Code Reduction**: ApiQuery simplified by ~60 lines through parameter removal and logic simplification
- **Naming Standardization**: Unified tool references across 4 chat tools for better developer experience


### Improved
- **Scheduling Documentation Centralization** - Created `SchedulingDocumentation` class to provide JSON-formatted scheduling interval documentation for chat tools, eliminating duplicate interval definitions across multiple tools
- **Chat Tool Descriptions** - Simplified and streamlined tool descriptions across chat tools (AddPipelineStep, ApiQuery, CopyFlow, CreateFlow, CreatePipeline, ExecuteWorkflowTool, UpdateFlow) for improved AI agent comprehension
- **ApiQuery Tool** - Removed verbose documentation and examples, focusing on key endpoints for better discoverability
- **ExecuteWorkflowTool** - Removed embedded handler documentation to reduce prompt overhead; now uses `api_query` tool for handler config discovery

### Technical Details
- **New Utility**: Added `inc/Api/Chat/Tools/SchedulingDocumentation.php` (67 lines) with cached JSON interval output
- **Code Reduction**: Net -80 lines across 7 modified tools through documentation simplification and centralized interval management
- **Parameter Cleanup**: Removed deprecated `scheduling_config` parameter from UpdateFlow tool (use `schedule` instead)

## [0.9.4] - 2026-01-05

### Fixed
- **React Query Cache Invalidation** - Enhanced cache management to handle all paginated queries instead of exact matches only
- **Flow Deletion Total Count** - Fixed `total` count decrement when deleting flows from paginated lists
- **Cache Context Restoration** - Improved error recovery in `useUpdateUserMessage` with proper paginated query context handling

### Technical Details
- **Frontend**: Updated `setFlowInCache`, `patchFlowInCache`, and `useDeleteFlow` in `/inc/Core/Admin/Pages/Pipelines/assets/react/queries/flows.js` to use `setQueriesData` with `{ exact: false }` for broader cache updates
- **Data Structure**: Improved data structure handling to support both simple arrays and paginated responses with `{ flows: [...], total: n }` format
- **FlowCard**: Updated cache mutation in `/inc/Core/Admin/Pages/Pipelines/assets/react/components/flows/FlowCard.jsx` to use `setQueriesData` with consistent data structure handling

## [0.9.3] - 2026-01-05

### Added
- **AssignTaxonomyTerm Tool** - New AI chat tool for assigning taxonomy terms to one or more posts with append or replace modes
- **MergeTaxonomyTerms Tool** - New AI chat tool for merging duplicate taxonomy terms with post reassignment, optional metadata merging, and source term deletion

### Technical Details
- **New Tools**: Added inc/Api/Chat/Tools/AssignTaxonomyTerm.php (190 lines) and MergeTaxonomyTerms.php (260 lines)
- **Taxonomy Validation**: Both tools leverage TaxonomyHandler for system taxonomy checks and term resolution
- **Meta Merging**: MergeTaxonomyTerms includes smart metadata merging that only fills empty target values

## [0.9.2] - 2026-01-05

### Fixed
- **API Response Spreading** - Added response property spreading in api.js utility to ensure all response fields (beyond success, data, message) are available to callers

### Technical Details
- **Frontend**: Updated `request()` response handler in `/inc/Core/Admin/Pages/Pipelines/assets/react/utils/api.js` to spread all response properties
- **Compatibility**: Ensures backward compatibility while providing access to additional response metadata

## [0.9.1] - 2026-01-04

### Added
- **Shared Pagination Component** - Reusable Pagination component for consistent UI across admin pages
- **Flows Pagination** - Added pagination support to Pipelines > Flows section for better performance with large flow lists
- **Settings-based Page Limits** - Configurable per-page limits for jobs (jobs_per_page) and flows (flows_per_page) via Settings API

### Improved
- **Code Deduplication** - Removed duplicate JobsPagination component (~74 lines) and CSS styles (~50 lines)
- **API Consistency** - Flows API now supports standard pagination parameters (per_page, offset)
- **React Query Optimization** - Paginated flows queries reduce initial payload for pipelines with many flows
- **Shared Architecture** - Extracted pagination logic to /inc/Core/Admin/shared/ for cross-page reusability

### Technical Details
- **Shared Components**: Created @shared/components/Pagination and @shared/styles/pagination.css
- **React Refactoring**: Migrated JobsApp and PipelinesApp to use shared pagination
- **Query Enhancement**: Updated useFlows hook to return paginated data structure
- **API Enhancement**: fetchFlows() accepts {page, perPage} options with offset calculation
- **Settings Integration**: New settings.js query hook for retrieving per-page configuration

## [0.9.0] - 2026-01-04

### Added
- **Troubleshooting Problem Flows System** - Complete flow monitoring and diagnostics system for production workflows:
  - **GetProblemFlows Chat Tool** - AI agent can identify and troubleshoot failing/idle flows
  - **`completed_no_items` Job Status** - Distinguishes "no new items" from actual "failures"
  - **Flow Monitoring Counters** - Automatically tracks `consecutive_failures` and `consecutive_no_items` in scheduling configuration
  - **`/flows/problems` REST API** - Retrieve flows exceeding threshold with pipeline context and status history
  - **Configurable Thresholds** - `problem_flow_threshold` setting (default: 3) via Settings API
  - **Smart Counter Reset** - Resets on successful runs, increments based on failure/no-items status
  - **Troubleshooting Documentation** - Comprehensive guide for diagnosing flow issues (auth, configuration, source exhaustion)
- **Universal Web Scraper Architecture** (`datamachine-events`) - High-performance multi-layered event data extraction:
  - **17+ Specialized Extractors** - Platform-specific extraction (AEG/AXS, Red Rocks, Squarespace, Wix, WordPress, Timely, etc.)
  - **Schema.org Support** - JSON-LD and Microdata structured data extraction
  - **AI-Enhanced HTML Fallback** - Identifies candidate sections and passes to AI for structured parsing
  - **Centralized Processing** - `StructuredDataProcessor` for venue overrides, engine data storage, deduplication
  - **Smart Captcha Handling** - Dual-mode header strategy for bot detection
  - **Automatic Pagination** - Up to 20 pages with integrated deduplication
  - **Single Item Execution Model** - Processes exactly one event per cycle for isolation and reliability

### Improved
- **Jobs API Filtering** - Enhanced `get_jobs_count()` and `get_jobs_for_list_table()` with flow_id, pipeline_id, and status filters
- **Jobs Query Security** - Refactored to use prepared statements with column whitelist instead of match statements
- **Engine Execution Logic** - Smart distinction between "no new items" and "first run with nothing" for fetch steps
- **ProcessedItems Manager** - Added `hasProcessedItems()` method for flow history checking
- **Database Operations** - Added `has_processed_items()` in ProcessedItems for first-run detection
- **Flow Scheduling Tracking** - `update_flow_last_run()` now manages consecutive failure/no-items counters with proper reset logic

### Technical Details
- **New Tool**: `inc/Api/Chat/Tools/GetProblemFlows.php` (139 lines)
- **Database Method**: `Flows::get_problem_flows()` with JSON_EXTRACT for counter filtering
- **Flow Logic**: Counter increment/reset on `completed`, `completed_no_items`, `failed` statuses
- **API Endpoint**: `GET /flows/problems` with customizable threshold parameter
- **Documentation**: `docs/core-system/troubleshooting-problem-flows.md` (50 lines)
- **Extension Docs**: `docs/handlers/fetch/universal-web-scraper.md` (75 lines)
- **Engine Enhancement**: `Engine.php` checks processed items history before marking empty fetch as failure
- **Jobs Operations**: Refactored from match statements to prepared queries with column validation

### Notes
This release marks a new era of Data Machine with systematic flow monitoring and troubleshooting capabilities. The problem flow system enables production-grade reliability by automatically identifying and flagging flows that need attention, while the universal web scraper provides enterprise-grade event extraction from virtually any website.

## [0.8.18] - 2026-01-04

### Added
- **Delete File Tool** - New `delete_file` chat tool for removing uploaded files by filename
- **Delete Flow Tool** - New `delete_flow` chat tool for deleting flows by ID
- **Delete Pipeline Tool** - New `delete_pipeline` chat tool for deleting pipelines and all associated flows
- **Delete Pipeline Step Tool** - New `delete_pipeline_step` chat tool for removing steps from pipelines (affects all flows)
- **Reorder Pipeline Steps Tool** - New `reorder_pipeline_steps` chat tool for reordering steps within pipelines

### Improved
- **ApiQuery Tool** - Refined documentation to focus on read-only operations (discovery and monitoring)
- **Tool Architecture** - Added specialized deletion and reordering tools for better AI agent workflow management

### Technical Details
- **Chat Tools**: Added 5 new tools following ToolRegistrationTrait pattern
- **API Integration**: All new tools wrap existing REST API endpoints
- **Code Addition**: +467 lines across 5 new tool files
- **Consistency**: Aligned ApiQuery documentation with new specialized deletion tools

## [0.8.17] - 2026-01-04

### Improved
- **Google Sheets Lazy Auth** - Refactored `GoogleSheets` publish handler to lazy-load its authentication provider only when an execution is actually triggered, reducing initialization overhead.
- **Auth Provider Caching** - Added `clearCache()` to `AuthProviderService` and integrated it into `CacheManager::clearHandlerCaches()` for robust synchronization during dynamic handler registration.
- **Tool Documentation Cleanup** - Simplified the `execute_workflow` chat tool documentation by removing redundant taxonomy configuration sections, improving prompt clarity.

### Technical Details
- **Core**: Added `AuthProviderService::clearCache()` to manage static cache state.
- **Core**: Updated `CacheManager::clearHandlerCaches()` to include auth provider invalidation.
- **Handlers**: Updated `GoogleSheets.php` with `get_auth()` lazy-loader.

## [0.8.16] - 2026-01-04

### Added
- **Tool Configuration UI** - Introduced a new modal-based configuration interface in the Settings -> Agent tab, allowing users to configure tool-specific settings (e.g., API keys, endpoints) directly for global AI tools.
- **Tool Configuration API** - Added `GET` and `POST` REST API endpoints for managing tool-specific configurations, including server-side secret masking.
- **Update Taxonomy Term Tool** - Formally documented and integrated the `update_taxonomy_term` global AI tool, enabling agents to modify existing terms and custom meta (e.g., venue data).

### Improved
- **ConfigureFlowSteps Tool** - Enhanced the `configure_flow_steps` chat tool to support unique handler settings per flow during bulk pipeline-scoped operations via the new `flow_configs` parameter.
- **Tool Registration** - Added `get_config_fields` support to `ToolRegistrationTrait`, allowing tools to dynamically define their configuration schemas for the new UI.
- **Universal Web Scraper** - Expanded engine documentation and handler overviews to include the unified `universal_web_scraper` architecture.

### Technical Details
- **Frontend**: Created `ToolConfigModal.jsx` and `queries/tools.js` for React-based tool management.
- **Core**: Added `handle_get_tool_config` and registered routes in `Settings.php`.
- **Core**: Updated `ToolManager` to support translation readiness and configuration state tracking.
- **Documentation**: Updated `tools-overview.md`, `fetch-handler.md`, and `handlers-overview.md`.

## [0.8.15] - 2026-01-04

### Improved
- **Tool Call Extraction** - Refactored `ConversationManager` to prioritize metadata for tool call extraction, improving reliability of tool execution tracking.
- **Chat UI Word Wrapping** - Enhanced `chat-sidebar.css` with `overflow-wrap: anywhere` for message content and code blocks, preventing layout breaks with long strings.
- **Chat Input Loading States** - Refined `ChatInput.jsx` and `ChatSidebar.jsx` to properly handle loading states, disabling buttons while maintaining input focus when appropriate.

### Fixed
- **Tool Parameter Truncation** - Removed aggressive string truncation (50 chars) for tool parameters in `ConversationManager`, ensuring complete data is preserved in conversation history.
- **OAuth Handler Status** - Standardized `OAuthPopupHandler.jsx` to use the unified `/status` endpoint instead of the redundant `/oauth-url` route.

### Technical Details
- **Core**: Removed `handle_get_oauth_url` and associated route from `Auth.php`.
- **Core**: Updated `formatToolCallMessage` and `extractToolCallFromMessage` in `ConversationManager.php` for better metadata handling.
- **Testing**: Added unit test for non-truncated tool parameters in `FlowManagerTest.php`.

## [0.8.14] - 2026-01-04

### Improved
- **ConfigureFlowSteps Chat Tool** - Added support for handler switching with automated field mapping, enabling AI agents to migrate flow step configurations between different handlers while preserving compatible settings.
- **Bulk Flow Configuration** - Enhanced the `configure_flow_steps` tool to support per-flow configuration overrides in bulk mode, allowing for granular adjustments across multiple flows in a single operation.

### Technical Details
- **Core**: Added `target_handler_slug` and `field_map` parameters to `ConfigureFlowSteps.php` for intelligent handler migration.
- **Core**: Added `flow_configs` parameter to `ConfigureFlowSteps.php` for per-flow overrides in bulk operations.
- **Core**: Standardized `flow_id` handling to integer types across configuration methods.

## [0.8.13] - 2026-01-04

### Added
- **UpdateTaxonomyTerm AI Tool** - New specialized chat tool enabling AI agents to update existing taxonomy terms, including core fields and custom meta (e.g., venue data).
- **Single Item Execution Model** - Formally introduced the reliability-first execution model where jobs process exactly one item per cycle to ensure isolation and prevent timeouts.

### Improved
- **AI Model Selection UI** - Refactored AI provider and model selection into a shared `ProviderModelSelector` component used across both Pipeline and Settings interfaces.
- **Query Optimization** - Decoupled provider queries from tool queries in the Pipeline interface for better performance.
- **Architectural Alignment** - Comprehensive updates to technical documentation aligning core components with the Single Item Execution Model.

### Technical Details
- **Frontend**: Migrated `ConfigureStepModal.jsx` and `AgentTab.jsx` to use the standardized `ProviderModelSelector`.
- **Core**: Added `inc/Api/Chat/Tools/UpdateTaxonomyTerm.php` and registered it in the main plugin file.
- **Docs**: Significant updates to `architecture.md`, `engine-execution.md`, and handler documentation.

## [0.8.12] - 2026-01-04

### Added
- **Shared Authentication Support** - Handlers can now share credentials by declaring an `auth_provider_key` during registration. This allows multiple publish destinations (e.g., Facebook and Threads) to use a single set of Meta credentials.
- **Enhanced Flow Summaries** - Added `settings_summary` to flow steps in the REST API, providing a clean string representation of configuration for UI overviews.

### Improved
- **Flow Duplication Logic** - Refined `FlowManager` to ensure only scheduling intervals are copied during flow duplication, preventing run history and status metadata from leaking into new flows.
- **Handler Configuration Switching** - The system now automatically strips legacy configuration fields when switching handlers in a flow step, preventing configuration bloat and potential conflicts.
- **Venue Field Handling** - Improved `SettingsDisplayService` to suppress manual venue fields in summaries when a primary venue taxonomy term is selected.

### Technical Details
- **Services**: Updated `AuthProviderService` to support provider key resolution via handler metadata.
- **Frontend**: Renamed the localized settings object to `dataMachineSettingsConfig` to prevent collisions with other Data Machine components.
- **Testing**: Enhanced `tests/bootstrap.php` with support for the `datamachine-events` extension and additional database table initialization for jobs and processed items.

## [0.8.11] - 2026-01-04

### Added
- **Incremental Flow Step Config** - New `PATCH /flows/steps/{id}/config` REST endpoint for updating handler slugs and configuration without full state replacement.
- **Pipeline Retrieval API** - New `GET /pipelines/{id}` REST endpoint for retrieving individual pipeline details.
- **Centralized Scheduler Intervals** - Introduced `SchedulerIntervals.php` to provide a single source of truth for all background task intervals.

### Improved
- **FlowStepManager Service** - Enhanced `updateHandler` to support optional handler slugs, allowing for configuration-only updates.
- **ConfigureFlowSteps Chat Tool** - Refined validation logic and parameter handling for more reliable AI-led configuration.

### Changed
- **Admin Page Management** - Removed the "Enabled Pages" setting; all registered admin pages (Pipelines, Jobs, Logs, Settings) are now enabled by default to simplify onboarding.

### Technical Details
- **API**: Updated `FlowSteps.php` and `Pipelines.php` with new REST routes.
- **Service**: Modified `inc/Services/FlowStepManager.php` to handle merged handler settings.
- **Settings**: Cleaned up `SettingsFilters.php`, `Settings.php`, and `GeneralTab.jsx` to remove legacy page toggling logic.

## [0.8.10] - 2026-01-04

### Added
- **Markdown Support in Chat** - Integrated `react-markdown` for rich text rendering of AI responses, including headers, lists, and formatted code blocks.
- **Enhanced Chat Styling** - Added comprehensive CSS for the chat sidebar to properly render Markdown elements with distinct styling for user and assistant messages.

### Improved
- **Scheduling Architecture** - Decoupled recurring intervals from `manual` and `one_time` scheduling logic in `SchedulerIntervals.php`, providing a cleaner single source of truth for background tasks.
- **AI Tool Reliability** - Updated `CreateFlow`, `UpdateFlow`, `CopyFlow`, and `CreatePipeline` chat tools to explicitly support the full range of scheduling options including non-recurring modes.
- **Build Process** - Cleaned up stale React build assets and updated `.gitignore` to prevent tracking of unnecessary build artifacts.

### Technical Details
- **Frontend**: Added `react-markdown` dependency to `package.json` and updated `ChatMessage.jsx`.
- **CSS**: Refactored `chat-sidebar.css` with dedicated styles for Markdown-rendered HTML elements.
- **Core**: Refined `datamachine_get_default_scheduler_intervals()` to focus on recurring tasks while maintaining tool-level support for manual/one-time execution.

## [0.8.9] - 2026-01-04

### Added
- **Ephemeral Workflows** - Support for temporary workflows that execute without database persistence, utilizing sentinel values and dynamic snapshots.
- **Expanded Scheduling Intervals** - Added high-frequency and periodic intervals: `every_5_minutes`, `every_2_hours`, `every_4_hours`, `qtrdaily`, and `twicedaily`.
- **Chat Tool UI Enhancements** - New `ToolMessage.jsx` component for better AI tool interaction feedback and expanded interval support in `CreateFlow`/`UpdateFlow` tools.

### Improved
- **Batch Pipeline Creation** - Enhanced `PipelineManager::createWithSteps()` for atomic creation of pipelines with multiple predefined steps and flows.
- **Engine Execution Cycle** - Standardized on a robust four-action cycle (`run_now` → `execute_step` → `schedule_next` → `run_later`) for both database and ephemeral workflows.
- **REST API Capabilities** - Refactored `Execute` endpoint to support complex ephemeral workflow payloads and provided better job status feedback.
- **Settings UI Reliability** - Added defensive checks for API key rendering and improved AI provider key synchronization.
- **Testing Infrastructure** - Adjusted PHPUnit and Polyfills dependencies to v9.6 and v2.0 respectively for better environment compatibility.

### Technical Details
- **Engine**: Implemented job snapshots in `EngineData` to ensure configuration consistency during long-running background jobs.
- **Scheduling**: Centralized interval definitions in `datamachine_get_default_scheduler_intervals()` with filter support.
- **Documentation**: Comprehensive updates to core system guides (engine-execution.md, ephemeral-workflows.md) to reflect the standardized execution cycle and ephemeral support.
- **API**: Enhanced `inc/Api/Execute.php` with ephemeral workflow generation logic and better error handling.

## [0.8.8] - 2026-01-04

### Changed
- **Settings UI Optimization** - Simplified AI provider API key configuration by reverting masking in the React frontend while maintaining server-side security.
- **Autoloading Refactor** - Removed manual file loading in `composer.json` in favor of standardized PSR-4 autoloading for improved performance and cleaner dependency management.
- **Composer Configuration** - Updated Composer settings and scripts for better development workflow.

### Fixed
- **Testing Bootstrap** - Enhanced `tests/bootstrap.php` with improved mock functions and environment definitions to ensure consistent unit testing across environments.

## [0.8.7] - 2026-01-04

### Added
- **AI Provider Key Masking** - Implemented server-side masking for AI provider API keys in the Settings API to improve security.
- **Key Status Feedback** - Added visual "Saved" badges and improved "Clear" functionality for API keys in the Settings UI.
- **WP-Unit Testing Suite** - Integrated `wp-phpunit/wp-phpunit` and standard WordPress test bootstrap for better automated testing.

### Improved
- **Settings UI Robustness** - Improved API key management with better visibility toggling and validation logic to prevent accidental masking overrides.
- **Dependency Management** - Upgraded `chubes4/ai-http-client` to v2.0.9 for improved HTTP handling.

### Technical Details
- **API Security**: Refactored `inc/Api/Settings.php` to mask keys before sending to frontend and handle masked inputs during updates.
- **UI Components**: Enhanced `inc/Core/Admin/Settings/assets/react/components/tabs/ApiKeysTab.jsx` with status indicators and improved interaction states.
- **Testing Infrastructure**: Added `bin/install-wp-tests.sh`, `tests/bootstrap.php`, and `phpunit.xml.dist` for WordPress-native testing.

## [0.8.6] - 2026-01-03

### Added
- **Handler Defaults System** - Implemented a global configuration system for handler settings, allowing users to define default values across all flows.
- **New AI Chat Tools** - Added `GetHandlerDefaults` and `SetHandlerDefaults` tools for agent-led configuration management.
- **Jobs React Page** - Completed migration of the Jobs management interface to React, featuring real-time job status monitoring and improved filtering.
- **Settings React Page** - Migrated the main Settings interface to React, including consolidated AI provider management and tool toggle controls.

### Improved
- **Chat Agent Decisiveness** - Refined `ChatAgentDirective` to promote a more decisive, "ACT FIRST" personality for faster workflow configuration.
- **API Metadata** - Enhanced Step Types and Settings endpoints to provide enriched metadata (handler counts, provider keys) for the new React UI.
- **Chat Message Structure** - Standardized user message objects in `Chat.php` with explicit type markers for better frontend rendering.
- **Tool UI Discovery** - Added handler count metadata to Step Types API to improve step selection guidance in the UI.

### Technical Details
- **UI Migration**: Replaced legacy vanilla JS and CSS in `inc/Core/Admin/Pages/Jobs/` and `inc/Core/Admin/Settings/` with modern React components.
- **Core Services**: Integrated `HandlerService` and `StepTypeService` more deeply into the API layer for consistent data resolution.
- **Scheduling**: Refactored `UpdateFlow` chat tool to support a unified `schedule` parameter with interval validation.

## [0.8.5] - 2026-01-04

### Added
- **Chat API Validation** - Added strict provider and model validation to the Chat REST API.
- **AI Default Fallbacks** - Implemented automatic fallbacks to system-default provider and model settings in the chat endpoint.

### Improved
- **AI Architecture Roadmap** - Updated migration documentation to reflect a temporary pause in `wp-ai-client` integration due to dynamic API key requirements.
- **Dependency Management** - Upgraded `chubes4/ai-http-client` to v2.0.8.

### Technical Details
- **Chat API**: Enhanced `inc/Api/Chat/Chat.php` with sanitized provider/model extraction and `WP_Error` feedback for missing configurations.
- **Migration**: Updated `WP-AI-CLIENT-MIGRATION.md` with critical blocker details regarding custom storage locations.

## [0.8.4] - 2026-01-03

### Added
- **New AI Chat Tools** - Expanded agent capabilities with four new specialized tools:
  - `ReadLogs` - Allows AI agent to retrieve and analyze execution logs for debugging.
  - `ManageLogs` - Enables agent to clear logs and manage log levels.
  - `SearchTaxonomyTerms` - Facilitates discovery of existing WordPress terms for better workflow configuration.
  - `CreateTaxonomyTerm` - Allows agent to create new terms on-the-fly during workflow setup.
- **Enhanced Logging System** - Centralized logging in the `Step` base class that automatically injects `job_id`, `pipeline_id`, and `flow_id` context into all step-related logs.

### Improved
- **Log Filtering API** - Added support for filtering log content by `pipeline_id` and `flow_id` in the REST API and `LogsManager`.
- **UI State Management** - Implemented optimistic updates for log clearing and a visual "Copied!" feedback state for the log copy button.
- **Agent Intelligence** - Updated `ChatAgentDirective` with improved guidance for taxonomy discovery and management.
- **Code Consistency** - Refactored `FetchStep` and `WordPress` handlers to use the improved centralized logging system.

### Technical Details
- **New Chat Tools**: `inc/Api/Chat/Tools/ReadLogs.php`, `inc/Api/Chat/Tools/ManageLogs.php`, `inc/Api/Chat/Tools/CreateTaxonomyTerm.php`, `inc/Api/Chat/Tools/SearchTaxonomyTerms.php`.
- **API Enhancements**: `LogsManager::getContent()` and `GET /logs/content` now accept `pipeline_id` and `flow_id` parameters.
- **Log Context**: Logging now automatically extracts context from `engine->getJobContext()`.

## [0.8.3] - 2026-01-03

### Added
- **New AI Chat Tools** - Expanded agent capabilities with four new specialized tools:
  - `ReadLogs` - Allows AI agent to retrieve and analyze execution logs for debugging.
  - `ManageLogs` - Enables agent to clear logs and manage log levels.
  - `SearchTaxonomyTerms` - Facilitates discovery of existing WordPress terms for better workflow configuration.
  - `CreateTaxonomyTerm` - Allows agent to create new terms on-the-fly during workflow setup.
- **Enhanced Logging System** - Centralized logging in the `Step` base class that automatically injects `job_id`, `pipeline_id`, and `flow_id` context into all step-related logs.

### Improved
- **Log Filtering API** - Added support for filtering log content by `pipeline_id` and `flow_id` in the REST API and `LogsManager`.
- **UI State Management** - Implemented optimistic updates for log clearing and a visual "Copied!" feedback state for the log copy button.
- **Agent Intelligence** - Updated `ChatAgentDirective` with improved guidance for taxonomy discovery and management.
- **Code Consistency** - Refactored `FetchStep` and `WordPress` handlers to use the improved centralized logging system.

### Technical Details
- **New Chat Tools**: `inc/Api/Chat/Tools/ReadLogs.php`, `inc/Api/Chat/Tools/ManageLogs.php`, `inc/Api/Chat/Tools/CreateTaxonomyTerm.php`, `inc/Api/Chat/Tools/SearchTaxonomyTerms.php`.
- **API Enhancements**: `LogsManager::getContent()` and `GET /logs/content` now accept `pipeline_id` and `flow_id` parameters.
- **Log Context**: Logging now automatically extracts context from `engine->getJobContext()`.

## [0.8.2] - 2026-01-03

### Added
- **Chat Persistence Architecture** - Implemented conversation retrieval logic to maintain chat state across page refreshes.
- **useChatQueryInvalidation Hook** - New React hook for automatic TanStack Query cache invalidation when AI tools modify system state.
- **REST Chat Retrieval** - New `GET /chat/{session_id}` endpoint for fetching historical conversation turns.

### Improved
- **Chat Session Management** - Removed 24-hour expiration from `ChatDatabase` to support long-running development sessions.
- **UI Synchronization** - Enhanced chat sidebar with prioritized reloading and query invalidation for better workflow continuity.

### Technical Details
- **New Core File**: `inc/Core/Admin/Pages/Pipelines/assets/react/hooks/useChatQueryInvalidation.js`
- **Session Continuity**: Chat sessions now persist indefinitely in the database until manual deletion.
- **Data Retrieval**: Integrated `retrieve_session()` method into `Chat` REST API class.

## [0.8.1] - 2026-01-03

### Added
- **Cache Management Service** - New `CacheManager` class for centralized, cross-system cache invalidation.
- **Lazy Tool Resolution** - Implemented lazy loading for AI tool definitions to prevent WordPress 6.7+ translation timing issues.
- **Dynamic Invalidation Hooks** - Added `datamachine_handler_registered` and `datamachine_step_type_registered` actions for real-time cache syncing.

### Improved
- **Service Layer Caching** - Integrated high-performance caching into `HandlerService`, `StepTypeService`, and `HandlerDocumentation` (2x faster discovery).
- **Chat Context Awareness** - Enhanced AI chat endpoint with `selected_pipeline_id` payload, allowing agents to understand UI state.
- **Tool Registration Pattern** - Updated `ToolRegistrationTrait` and all chat tools to support callable definitions for lazy evaluation.
- **Documentation Utility** - Optimized `HandlerDocumentation` with internal caching and slug-based invalidation detection.

### Technical Details
- **New Core File**: `inc/Services/CacheManager.php`
- **Lazy Loading**: Tool definitions are now resolved only when first accessed, ensuring translations are loaded.
- **Context Injection**: `selected_pipeline_id` passed to `ChatPipelinesDirective` for prioritized inventory awareness.

## [0.8.0] - 2026-01-03

### Added
- **Integrated Chat Sidebar** - New React-based AI chat interface embedded directly into the Pipeline Builder for real-time conversational workflow assistance.
- **React Logs Interface** - Complete migration of the Logs management page to a modern React architecture with improved filtering and performance.
- **Step Type Registration Trait** - Introduced `StepTypeRegistrationTrait` to standardize registration for AI, Fetch, Publish, and Update steps.
- **AI Agent Context & Types** - New `AgentContext` and `AgentType` classes for managing specialized AI personalities and execution contexts.
- **Shared Admin UI** - Consolidated React components and styles into `/inc/Core/Admin/shared/` for cross-page reusability.

### Improved
- **Service Layer Consistency** - Refined `PipelineManager` and `PipelineStepManager` with standardized patterns and error handling.
- **Webpack Configuration** - Updated to support multiple React entry points (Pipelines and Logs).
- **Log Management** - Enhanced `LogsManager` and `Logs` API for better filtering and retrieval of execution records.

### Changed
- **Step Architecture Refactoring** - Removed individual filter classes for core steps in favor of trait-based registration.
- **Migration Roadmap** - Updated `WP-AI-CLIENT-MIGRATION.md` to target v0.9.0 for native AI client integration.

### Removed
- **Legacy Logs Assets** - Deleted legacy `admin-logs.js` and associated PHP template logic.
- **Step Filter Classes** - Removed `AIStepFilters`, `FetchStepFilters`, `PublishStepFilters`, and `UpdateStepFilters`.

## [0.7.1] - 2026-01-02

### Added
- **AI Context Enrichment** - Enhanced `ChatPipelinesDirective` to include flow summaries (ID, name, and active handlers) in the pipeline inventory, enabling the agent to learn from established configuration patterns.
- **Centralized Default Application** - Added `HandlerService->applyDefaults()` to provide a single source of truth for merging handler schema defaults with configuration.

### Improved
- **Chat Agent Directive** - Refined `ChatAgentDirective` with a focus on architecture-aware guidance, emphasizing handler roles and documented configuration patterns.
- **Flow Update Consistency** - Refactored `FlowStepManager` and `Flows` API to utilize the centralized default application logic, ensuring consistent configuration state across all entry points.
- **Classmap Cleanup** - Removed legacy/unused tool entries from `composer.json` to streamline the classloader.

### Technical Details
- **New Service Method**: Added `applyDefaults(string $handler_slug, array $config)` to `inc/Services/HandlerService.php`.
- **Logic Consolidation**: Removed `merge_handler_defaults()` from `inc/Api/Flows/Flows.php` in favor of `HandlerService`.
- **Directive Updates**: Significant content revision in `inc/Api/Chat/ChatAgentDirective.php` and context expansion in `inc/Api/Chat/ChatPipelinesDirective.php`.

## [0.7.0] - 2026-01-02

### Added
- **Modular AI Directive System** - Complete overhaul of AI system prompts into a modular architecture using `DirectiveInterface`, `DirectiveRenderer`, and `DirectiveOutputValidator`
- **Service Layer Expansion** - Migrated `AuthProviderService` and `StepTypeService` to the OOP services layer for better performance and maintainability
- **ChatPipelinesDirective** - New specialized directive for handling pipeline-related context in AI conversations
- **Cross-Pipeline Flow Copying** - (v0.6.25) Implemented advanced flow duplication between different pipelines with automatic step mapping

### Improved
- **AI Architecture** - Decoupled directive logic from monolithic strings into dedicated classes, improving agent reliability and prompt consistency
- **PromptBuilder** - Updated to utilize the new modular directive rendering engine
- **Service Layer Performance** - Enhanced `HandlerService`, `PipelineManager`, and `PipelineStepManager` with standardized patterns
- **API Response Standardization** - Unified response formats across Chat, Auth, Execute, and Flows endpoints

### Technical Details
- **New Core Components**: `inc/Engine/AI/Directives/DirectiveInterface.php`, `inc/Engine/AI/Directives/DirectiveRenderer.php`, and `inc/Engine/AI/Directives/DirectiveOutputValidator.php`
- **New Directives**: Added `inc/Api/Chat/ChatPipelinesDirective.php` for conversational pipeline management
- **New Services**: Migrated `inc/Services/AuthProviderService.php` and `inc/Services/StepTypeService.php`
- **Plan Update**: Updated `WP-AI-CLIENT-MIGRATION.md` to target v0.8.0 for native AI client integration

## [0.6.25] - 2026-01-02

### Added
- **Cross-Pipeline Flow Copying** - Implemented advanced flow duplication that allows copying flows between different pipelines, with automatic step mapping and compatibility validation
- **CopyFlow Chat Tool** - New AI tool enabling the chat agent to perform complex flow copies with configuration overrides and schedule customization
- **Step Configuration Overrides** - Support for overriding handler settings and user messages during the flow copy process, enabling rapid creation of variations

### Improved
- **FlowManager Service** - Enhanced with `copyToPipeline()` method and internal mapping logic for robust flow duplication
- **Pipeline Compatibility Validation** - Added systematic checks to ensure source and target pipelines have matching step structures before allowing cross-pipeline copies

### Technical Details
- **New Tool**: Added `inc/Api/Chat/Tools/CopyFlow.php` for conversational flow duplication
- **Service Enhancement**: Refactored `inc/Services/FlowManager.php` to support cross-pipeline logic and configuration remapping
- **Validation Logic**: Implemented `validatePipelineCompatibility()` to ensure structural alignment between pipelines

## [0.6.24] - 2026-01-02

### Added
- **Strict Handler Configuration Validation** - Implemented automated schema-based validation for AI tools (`ConfigureFlowSteps`), rejecting unknown `handler_config` fields to improve agent reliability
- **Enhanced AI Tool Documentation** - Refactored `HandlerDocumentation` utility to include field types and descriptions in tool definitions, providing AI agents with better context for parameter selection

### Improved
- **AI Directives** - Updated `ChatAgentDirective` with refined guidance on pattern-based discovery, instructing agents to query existing flows before creating new workflows
- **Tool Logic** - Enhanced `ConfigureFlowSteps` and `CreateFlow` tool descriptions to emphasize pattern discovery and schema compliance

### Technical Details
- **Validation Logic**: Added `validateHandlerConfig()` method to `inc/Api/Chat/Tools/ConfigureFlowSteps.php` for real-time parameter checking
- **Documentation Refactoring**: Updated `inc/Api/Chat/Tools/HandlerDocumentation.php` to parse and format field types and truncated descriptions
- **Directive Updates**: Refined "Discovery" and "Configuration" sections in `inc/Api/Chat/ChatAgentDirective.php`

## [0.6.23] - 2025-12-30

### Added
- **HandlerService** - New centralized service for handler discovery, validation, and lookup across the plugin, improving architectural consistency and reliability
- **Atomic CreateFlow Configurations** - Enhanced the `CreateFlow` AI tool to support optional `step_configs` during flow creation, enabling the AI to create and fully configure a flow in a single step

### Improved
- **AI Tool Performance** - Optimized `CreateFlow` tool to utilize `FlowStepManager` and `HandlerService` for more robust step-level configuration and validation

### Technical Details
- **New Service Layer Component**: Added `inc/Services/HandlerService.php` to centralize handler-related business logic
- **Tool Logic Refactoring**: Updated `inc/Api/Chat/Tools/CreateFlow.php` to support complex object parameters for atomic flow setup
- **Atomic Configuration**: AI agents can now provide `handler_slug`, `handler_config`, and `user_message` for multiple steps during `create_flow` tool calls

## [0.6.22] - 2025-12-26

### Changed
- **TaxonomyHandler Logging Simplification** - Removed unnecessary `logTaxonomyOperation()` method and replaced with direct `do_action('datamachine_log', ...)` calls for cleaner code

### Technical Details
- **Code Simplification**: Eliminated 8 lines by removing intermediate logging method abstraction
- **No Functional Changes**: Logging behavior unchanged; removed unnecessary method wrapper

## [0.6.21] - 2025-12-25

### Improved
- **WordPress Publish Schema Documentation** - Enhanced content field description in WordPress publish handler schema to clarify that source URL attribution and images are handled automatically by the system

### Technical Details
- **Schema Clarity**: Updated field description to prevent duplicate attribution or image handling in AI-generated content
- **WordPress Publish Handler**: Single line modified in schema definition for improved user/developer understanding

## [0.6.20] - 2025-12-25

### Fixed
- **Pipeline Step Configuration** - Fixed AI provider property names from `ai_provider`/`ai_model` to `provider`/`model` in PipelineStepCard inline editing
- **Mutation Pattern** - Migrated ConfigureStepModal from direct API call to useUpdateSystemPrompt mutation for proper React Query integration
- **Modal Success Callback** - Added onSuccess prop propagation to ModalManager baseProps for consistent modal callback handling

### Improved
- **Tool Selection Logic** - Removed unnecessary `configured` check from AIToolsSelector, now only filters by `globally_enabled`
- **Code Cleanliness** - Removed unused `useRef` import from ConfigureStepModal
- **Success Callback Handling** - Simplified ConfigureStepModal success callback to remove redundant onSuccess check

### Technical Details
- **React Query Pattern**: ConfigureStepModal now uses mutation with automatic cache invalidation
- **Data Contract Consistency**: Fixed property name mismatch in PipelineStepCard inline editing
- **Code Changes**: 3 React components modified with improved mutation patterns and cleaner code

## [0.6.19] - 2025-12-24

### Changed
- **WordPress Publishing Simplification** - Removed content sanitization methods for duplicate prevention (reverts v0.6.18 approach)
  - Removed `stripDuplicateSourceAttribution()` and `stripFeaturedImageFromContent()` from WordPressPublishHelper
  - Simplified WordPress publish handler to rely on standard WordPress content sanitization
  - Fixed indentation in WordPress publish handler

### Fixed
- **PHP Requirement Documentation** - Corrected PHP requirement from 8.0 to 8.2 across all documentation files
- **Version Inconsistencies** - Updated composer.json version from 0.6.13 to current 0.6.19

### Technical Details
- **Code Reduction**: -101 lines removed from WordPressPublishHelper.php
- **Simplification**: Content attribution and image handling now managed through standard WordPress sanitization only

## [0.6.18] - 2025-12-23

### Improved
- **WordPress Publishing Duplication Prevention** - Added content sanitization to prevent duplicate source attribution and featured image references when AI-generated content is published
  - Removes AI-generated source links before system adds its own attribution
  - Removes featured image references from content (figure blocks, img tags, markdown images)
  - Cleaner published content without redundant elements

### Fixed
- **AI Step Configuration Loading State** - Fixed race condition where AI step form could initialize before tools data finished loading
  - Added `isLoadingTools` state tracking to ConfigureStepModal
  - Form now waits for both providers and tools data before resetting
  - Dropdowns properly disabled during async data loading

### Technical Details
- **WordPressPublishHelper**: Added `stripDuplicateSourceAttribution()` with comprehensive regex patterns for duplicate detection
- **WordPressPublishHelper**: Added `stripFeaturedImageFromContent()` to prevent image duplication in published content
- **ConfigureStepModal**: Enhanced useEffect with loading state checks to prevent premature form initialization

## [0.6.17] - 2025-12-23

### Improved
- **OAuth2 Token Exchange Headers** - Enhanced OAuth2Handler to support custom HTTP headers during token exchange (required for Reddit OAuth authentication which uses Basic Auth instead of standard Authorization header)

### Technical Details
- **OAuth2Handler::exchange_token()**: Added custom header extraction and merging logic, allowing providers to override default Accept/Content-Type headers via `params['headers']` parameter
- **Reddit OAuth Support**: Enables proper token exchange for Reddit OAuth provider which requires Basic Authentication header
- **Backward Compatibility**: Default headers maintained for existing OAuth2 providers; custom headers only used when explicitly provided

## [0.6.16] - 2025-12-23

### Improved
- **OAuth Authentication Security** - OAuth authorization URL now fetched from REST API before opening popup to ensure proper server-side state parameter generation
- **OAuth Authentication UX** - Added loading state with "Connecting..." indicator during OAuth URL fetch
- **File Cleanup Scheduling Reliability** - Changed from `init` hook to `action_scheduler_init` hook for more consistent scheduling

### Fixed
- **AI Providers Tab** - Fixed variable name in label `for` attribute causing incorrect HTML ID references

### Technical Details
- **OAuthPopupHandler Component**: Now calls `/auth/{handler_slug}/oauth-url` endpoint before opening popup, ensuring state parameter is created server-side
- **Security Enhancement**: Server-side OAuth URL generation improves CSRF protection consistency
- **Error Handling**: Added try/catch block for OAuth URL fetch with proper error callback

## [0.6.15] - 2025-12-23

### Improved
- **OAuth State Generation** - Replaced WordPress nonces with cryptographically secure random bytes for OAuth 2.0 state parameter generation
  - Changed `create_state()` from `wp_create_nonce()` to `bin2hex(random_bytes(32))` for stronger cryptographic security
  - Updated `verify_state()` to remove nonce verification, now validates directly against transient storage with hash_equals()
  - Improved protection against CSRF attacks in OAuth callback flows across all OAuth2 providers

### Technical Details
- **Security Enhancement**: cryptographically secure 64-character hexadecimal state values (256-bit entropy) vs WordPress nonce limitations
- **OAuth Providers**: Applies to Google Sheets, Reddit, Facebook, and Threads OAuth authentication flows
- **Validation**: Simplified state verification logic with direct transient comparison and timing-attack safe hash_equals()
- **Code Change**: 2 lines modified in OAuth2Handler.php for cleaner, more secure OAuth state management

## [0.6.14] - 2025-12-23

### Improved
- **OAuth Handler Refactoring** - Removed duplicate OAuth state validation code from individual handler callback methods
  - Centralized state verification now handled entirely by OAuth2Handler::handle_callback()
  - Eliminated redundant state parameter validation across GoogleSheets, Reddit, Facebook, and Threads handlers
  - Added proper PHPCS suppression comments explaining OAuth state parameter provides CSRF protection

### Technical Details
- **Code Reduction**: Removed ~30 lines of duplicate validation code from 4 OAuth handler files
- **Security**: CSRF protection maintained through centralized OAuth2Handler state verification
- **Architecture**: Cleaner separation of concerns with OAuth2Handler managing state validation logic
- **Handler Updates**: GoogleSheetsAuth, RedditAuth, FacebookAuth, ThreadsAuth simplified to delegate callback handling

## [0.6.13] - 2025-12-23

### Added
- **Plugin Activation Redirect** - Users are automatically redirected to the Data Machine admin page after plugin activation for improved onboarding experience
- Smart redirect handling that skips bulk/network activation to prevent disruption

### Technical Details
- **New Function**: `datamachine_activation_redirect()` handles activation redirect with proper transient management
- **UX Enhancement**: 30-second transient timeout ensures redirect only happens immediately after activation
- **Safety Checks**: Bulk/network activation detection prevents unwanted redirects

## [0.6.12] - 2025-12-20

### Improved
- **Scheduler Group Migration**: Updated Action Scheduler group from `'datamachine'` to `'data-machine'` across all scheduled actions for WordPress.org compliance
- **Flow Re-scheduling on Activation**: Added comprehensive flow re-scheduling logic in plugin activation to automatically resume scheduled flows after plugin reactivation or updates
- **Plugin Activation Enhancements**: Enhanced `datamachine_activate_plugin()` with improved documentation and safety checks for flow re-scheduling operations
- **Code Cleanup**: Removed redundant HTTP filter logic and streamlined code in DataMachineFilters.php

### Technical Details
- **New Functions**: `datamachine_activate_scheduled_flows()` handles re-scheduling of recurring and one-time flows with error handling for fresh installs
- **Action Scheduler**: Updated hook group references to `'data-machine'` for proper scheduled action management
- **Logging**: Added info-level logging for flow re-scheduling operations
- **Safety Checks**: Added checks to prevent re-scheduling on fresh installs without existing flows

## [0.6.11] - 2025-12-20

### Added
- **Flow Re-scheduling on Activation** - Automatically re-schedules all flows with non-manual scheduling intervals when plugin is activated, ensuring workflows resume after plugin reactivation or updates

### Improved
- **Plugin Activation** - Enhanced `datamachine_activate_plugin()` with comprehensive documentation and flow re-scheduling logic
- **Scheduler Reliability** - Added safety checks and logging for flow re-scheduling operations

### Technical Details
- **New Functions**: `datamachine_activate_scheduled_flows()` handles re-scheduling of recurring and one-time flows on activation
- **Interval Support**: Handles all scheduling intervals (every_5_minutes, hourly, every_2_hours, every_4_hours, qtrdaily, twicedaily, daily, weekly)
- **Logging**: Added logging for re-scheduling operations
- **Backward Compatibility**: Gracefully handles fresh installs and deployments without existing flows

## [0.6.10] - 2025-12-20

### Improved
- **React Query Cache Consistency**: Fixed systematic query invalidation issues across pipeline step mutations (add, delete, reorder) to maintain cache coherency when pipelines and flows are modified
- **Pipeline Creation UX**: Enhanced `useCreatePipeline` hook with callback support, enabling automatic pipeline selection after creation without manual state management
- **Code Cleanliness**: Removed unused React hooks and API calls (`useFlow`, `usePipeline`, `fetchFlow`) that were no longer serving any purpose in the application
- **Plugin Uninstallation**: Added comprehensive cleanup of plugin-generated directories during uninstall (datamachine-files/ and datamachine-logs/ in uploads folder)
- **Mutation Architecture**: Improved separation of concerns by moving state updates from component callbacks to mutation `onSuccess` handlers

### Changed
- **Query Invalidation Patterns**: Consolidated single-pipeline queries to full pipeline list invalidation for more predictable cache behavior across all step-related mutations
- **Callback Handling**: Migrated from manual result handling in components to mutation option callbacks for cleaner, more maintainable code patterns
- **Plugin Cleanup**: Enhanced uninstall.php with recursive directory deletion helper and proper file cleanup sequence

### Fixed
- **Cache Invalidation**: Fixed `useAddPipelineStep`, `useDeletePipelineStep`, and `useReorderPipelineSteps` mutations to properly invalidate both pipelines and flows queries
- **Flow Execution**: Removed unnecessary queryClient invalidation in `useRunFlow` that was invalidating non-existent single flow query
- **Plugin Hygiene**: Added proper cleanup of temporary files and logs directory when plugin is uninstalled

### Technical Details
- **React Query**: Query invalidation now uses consistent patterns across all pipeline-related mutations
- **Mutations**: Enhanced `useCreatePipeline` to accept options object for better extensibility and callback support
- **Cleanup**: New `datamachine_recursive_delete()` function in uninstall.php handles nested directory removal safely
- **Code Reduction**: Removed ~30 lines of unused hook and query code
- **Architecture**: Improved component callback patterns following React best practices for mutation handling

## [0.6.9] - 2025-12-20

### Improved
- **WordPress.org Plugin Repository Compliance**: Standardized text domain from `'datamachine'` to `'data-machine'` across entire codebase (~80 files, 200+ occurrences) to align with plugin slug requirement
- **Database Security Hardening**: Refactored `JobsOperations::get_jobs_for_list_table()` to use WordPress prepared statement parameters (`%i` for table names) instead of manual validation logic
- **Plugin Architecture**: Updated plugin filename from `datamachine.php` to `data-machine.php` for proper WordPress directory compatibility
- **Source Code Documentation**: Added comprehensive "Source Code" section in readme.txt documenting React admin interface location, build instructions, and dependencies

### Changed
- **Plugin Filename**: Main plugin file renamed from `datamachine.php` to `data-machine.php` for WordPress.org compliance (existing installations should verify plugin activation after update)
- **Build Process**: Updated build.sh to reference new plugin filename and produce `data-machine.zip` output
- **Action Scheduler**: Updated hook group references from `'datamachine'` to `'data-machine'` for proper scheduled action cleanup

### Technical Details
- **Text Domain Migration**: Updated 80+ PHP files including API classes, admin pages, settings UI, fetch/publish/update handlers, OAuth providers, and service managers
- **Database Query Security**: Eliminated manual orderby/order validation in favor of explicit prepared queries for each allowed column (j.pipeline_id, j.flow_id, j.status, j.completed_at, p.pipeline_name, f.flow_name)
- **Code Quality**: Added proper phpcs directives (WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL) for standards compliance in database operations
- **Build Validation**: Enhanced build.sh to validate essential files exist and development files are properly excluded from production package

## [0.6.8] - 2025-12-19

### Improved
- **AI Tool Response Architecture**: Refactored success message handling from filter-based system to direct tool-provided messages for better performance and structure
- **Conversation Manager**: Enhanced response formatting with key identifier extraction (flow IDs, pipeline IDs, post IDs) for clearer AI agent feedback
- **CreateFlow Tool**: Added duplicate flow detection to prevent creation of flows with identical names in the same pipeline

### Technical Details
- **Code Changes**: Removed filter-based message handlers across 4 global AI tools, consolidated message logic in ConversationManager
- **Performance**: Eliminated WordPress filter overhead in tool success message processing
- **Architecture**: Simplified tool response handling while maintaining backward compatibility

## [0.6.7] - 2025-12-17

### Improved
- **Fetch Handler Consistency**: Standardized empty response handling across all fetch handlers to return consistent empty arrays
- **API Documentation**: Updated processed-items API documentation to use "deduplication tracking" terminology for better clarity
- **React Component Reliability**: Added type-safe ID comparison utilities to prevent type coercion bugs in React components
- **Version Number Consistency**: Fixed inconsistent version numbers across plugin files and documentation

### Fixed
- **Type Safety**: Implemented ID utility functions (`isSameId`, `includesId`, `normalizeId`) to handle string/number ID comparisons reliably
- **Component Updates**: Updated FlowCard, FlowSteps, PipelineCheckboxTable, and flows query components to use type-safe ID utilities

### Technical Details
- **Code Cleanup**: Standardized empty array returns in GoogleSheetsFetch and WordPressAPI handlers
- **Documentation**: Comprehensive API documentation updates for deduplication tracking endpoints
- **React Improvements**: New utils/ids.js utility file with type-safe ID comparison functions

## [0.6.6] - 2025-12-16

### Improved
- **Deduplication Tracking**: Renamed "ProcessedItems" endpoints to "Deduplication Tracking" throughout documentation for better clarity
- **Fetch Handler Consistency**: Standardized empty response handling across GoogleSheets and WordPressAPI fetch handlers
- **API Settings Display**: Updated FlowSteps API to use improved handler settings display method for better configuration management

### Technical Details
- **Documentation**: Updated API documentation to use consistent "deduplication tracking" terminology
- **Code Cleanup**: Removed inconsistent empty array returns in fetch handler methods
- **API Enhancement**: Improved handler settings retrieval in flow step configuration endpoints

## [0.6.5] - 2025-12-16

### Changed
- **License Update**: Changed project license from proprietary to GPL-2.0-or-later for open-source compatibility
- **PHP Version Requirement**: Increased minimum PHP version from 8.0 to 8.2 for improved performance and features
- **Dependency Updates**: Updated composer dependencies including TwitterOAuth (^7.0 → ^8.1), PHPUnit (^10.0 → ^12.0), and WordPress stubs (^6.8 → ^6.9)
- **Node.js Dependencies**: Minor updates to @wordpress/scripts (^31.0.0 → ^31.1.0) and React Query (5.90.10 → 5.90.12)
- **File Repository Architecture**: Enhanced file cleanup directory naming to include flow ID for better isolation
- **Fetch Handler Refactoring**: Simplified base FetchHandler by removing unused successResponse() and emptyResponse() helper methods
- **Handler Compatibility**: Updated GoogleSheets and Reddit fetch handlers to use direct array returns instead of removed helper methods

### Technical Details
- **Code Reduction**: -19 lines from FetchHandler refactoring
- **Compatibility**: PHP 8.2+ now required; existing PHP 8.0/8.1 installations must upgrade
- **Security**: Updated dependencies address potential vulnerabilities in older versions

## [0.6.4] - 2025-12-15

### Added
- **Optimistic UI Updates**: Added optimistic updates and reconciliation logic for flow execution in FlowCard component

### Improved
- **React Query Integration**: Refactored flow components to use query hooks instead of direct API calls
- **Flow Execution UX**: Enhanced flow execution with queued status display and automatic result reconciliation
- **API Documentation**: Synchronized auth.md and handlers.md with actual response structures and authentication metadata
- **Code Quality**: Cleaned up excessive comments and improved prop handling across flow components

### Fixed
- **Stable Tag**: Updated README.md stable tag to reflect current version 0.6.3

## [0.6.3] - 2025-12-15

### Improved
- **React Components**: Code cleanup and refactoring in flow-related components (FlowCard, FlowStepHandler, FlowsSection)
- **Code Quality**: Removed excessive comments, improved prop handling, and fixed linting issues

## [0.6.2] - 2025-12-14

### Fixed
- **Build Process**: Fixed version extraction in build.sh to automatically parse from plugin file headers instead of requiring manual setting
- **Pipeline UI**: Fixed type consistency issues in React components where pipeline ID comparisons failed due to string/number mismatches

### Added
- **Optimistic Updates**: Added optimistic UI updates for pipeline creation providing immediate feedback while API requests process
- **Debug Logging**: Added debug logging to AI conversation loop for tool call execution and results tracking

### Improved
- **AI Chat Agent**: Enhanced chat agent directive with action bias guidance, configuration section, and improved context understanding
- **UI State Management**: Improved pipeline selection state handling with better null checking and string normalization

## [0.6.1] - 2025-12-10

### Added
- **AuthenticateHandler Chat Tool** - New conversational tool for managing handler authentication via natural language
  - Actions: list (all statuses), status (specific handler), configure (save credentials), get_oauth_url (OAuth providers), disconnect
  - Security-aware design with warnings about credential visibility in chat logs
- **OAuth URL REST Endpoint** - New `GET /auth/{handler_slug}/oauth-url` for programmatic OAuth URL retrieval
- **Config Status in Auth Responses** - Auth status now returns masked credential indicators for better UX

### Enhanced
- **OAuth Authentication Modal** - Added "Change API Configuration" button for connected handlers, improved form visibility management
- **Handler Registration** - Added `auth_provider_key` metadata for flexible handler/auth provider mapping
- **Social Media Handlers** - Fixed auth provider key registration for Twitter, Facebook, Threads, and Bluesky

### Improved
- **FileCleanup Architecture** - Replaced `wp_delete_directory()` with native PHP recursive deletion for better cross-platform reliability

### Removed
- **Redundant OAuth Filter** - Removed `datamachine_oauth_url` filter (functionality moved to REST API)

### Documentation
- Added AuthenticateHandler tool documentation
- Updated Auth API documentation with OAuth URL endpoint

## [0.6.0] - 2025-12-08

### Milestone Release
- **WordPress Plugin Check Compliance**: Complete code cleanup and modifications to pass WordPress plugin directory standards
- **Testing Phase**: Version prepared for comprehensive testing prior to WordPress.org release

### Enhanced
- **Core Architecture**: Services layer refinements and function name standardization for better code organization
- **API Improvements**: Enhanced Files API with expanded functionality, Jobs API updates, and improved endpoint consistency
- **Chat Tools**: Updated ConfigureFlowSteps tool with improved bulk operations, ExecuteWorkflowTool enhancements, and ApiQuery refinements
- **Database Operations**: Chat database improvements, job operations enhancements, and pipeline data handling updates
- **OAuth System**: Authentication handler updates across Google Sheets, Reddit, Facebook, Threads, and Twitter providers
- **File Management**: Enhanced FileCleanup and RemoteFileDownloader with improved error handling and validation

### Changed
- **Function Naming**: Standardized function names in main plugin file (datamachine_run_datamachine_plugin, datamachine_activate_plugin_defaults)
- **Documentation**: Updated CLAUDE.md to reflect ConfigureFlowSteps tool improvements
- **Admin Interface**: Settings page refinements and UI component updates for better user experience

### Technical Details
- **Code Changes**: 54 files modified with 707 insertions and 691 deletions
- **Architecture**: Maintained backward compatibility while improving code quality and WordPress standards compliance
- **Performance**: Optimized database queries and API operations for better reliability

## [0.5.8] - 2025-12-05

### Security
- Additional package security updates and dependency fixes

### Enhanced
- **RunFlow Tool**: Improved timestamp validation logic for more reliable flow execution scheduling
- **React UI Components**: Added Zustand hydration checks to prevent initialization race conditions
- **State Persistence**: Enhanced UI store with better persistence configuration for cross-session memory

### Fixed
- **Package Version Mismatch**: Corrected package-lock.json version to match current release

## [0.5.7] - 2025-12-05

### Security
- Updated ai-http-client dependency from v2.0.3 to v2.0.7 for security fixes
- Updated node-forge from 1.3.1 to 1.3.3 in package dependencies

### Fixed
- React hydration timing issue in PipelinesApp component that could cause race conditions during initialization

### Documentation
- Added comprehensive AI Directives System documentation
- Added Import/Export System documentation
- Updated version references in documentation

## [0.5.6] - 2025-12-03

### Enhanced
- **ConfigureFlowSteps Tool**: Renamed from ConfigureFlowStep (singular) and enhanced with bulk pipeline-scoped operations for configuring multiple flow steps across all flows in a pipeline
- **FlowStepManager**: Handler configuration updates now merge into existing config instead of replacing, enabling incremental configuration changes
- **AIStep Image Handling**: Improved vision image processing to use engine data as single source of truth for file paths and metadata
- **UI State Persistence**: Added localStorage persistence for selected pipeline in React UI store for cross-session memory

### Added
- **HttpClient Documentation**: Comprehensive documentation for the centralized HTTP client architecture at `/docs/core-system/http-client.md`

### Technical Details
- **Bulk Operations**: ConfigureFlowSteps tool now supports both single-step and pipeline-wide bulk configuration modes
- **Merge Behavior**: Handler config updates use array_merge for incremental changes rather than full replacement
- **UI Enhancement**: Pipeline selection persists across browser sessions using Zustand persist middleware

## [0.5.5] - 2025-12-02

### Fixed
- **Timezone Handling**: Standardized UTC timezone usage across DateFormatter, Chat database, Flows scheduling, and file operations for better cross-timezone compatibility
- **API Parameter Bug**: Fixed pipeline title update endpoint to use correct `pipeline_id` parameter instead of `id`
- **Date Consistency**: Improved GMT timestamp handling using `current_time('mysql', true)` for consistent timezone-aware operations

### Removed
- **DynamicToolProvider.php**: Removed unused abstract base class that was relocated to datamachine-events extension

### Technical Details
- **Timezone Standardization**: All database date operations now use UTC timezone for consistent storage and retrieval
- **API Reliability**: Fixed parameter handling bug in pipeline management endpoints

## [0.5.4] - 2025-12-02

### Removed
- **DynamicToolProvider Base Class** - Removed unused abstract base class from core
  - Pattern relocated to datamachine-events where it's actually used
  - See datamachine-events `DynamicToolParametersTrait` for the active implementation

### Enhanced
- **OAuth Authentication UX**: Added redirect URL display in authentication modals for OAuth providers
- **Handlers API**: Enhanced OAuth provider metadata with callback URL information
- **WordPress Settings**: Improved taxonomy field label formatting in settings handlers

## [0.5.3] - 2025-12-02

### Fixed
- **Date Handling**: Improved timezone-aware date parsing across DateFormatter, Chat database, and Flows scheduling using DateTime instead of strtotime
- **DateFormatter**: Removed inconsistent relative time functionality for consistent absolute date display
- **Session Management**: Enhanced chat session expiration checking with proper error handling for invalid dates
- **Flow Scheduling**: Better timestamp calculations for scheduling intervals with timezone support

### Technical Details
- **Date Consistency**: Standardized DateTime usage with wp_timezone() across core database operations
- **Error Handling**: Added try/catch blocks for invalid date formats in critical paths

## [0.5.2] - 2025-12-02

### Fixed
- **ExecuteWorkflowTool**: Improved REST request error handling for better reliability
- **Flow Scheduling**: Preserve last_run_at and last_run_status when updating schedule configuration
- **Flow Data**: Include last_run_status in flow API responses for better status tracking
- **Date Formatting**: Enhanced display formatting to show status indicators for failed or no-items runs
- **Handler Settings Modal**: Prevent duplicate settings enrichment when handler details load asynchronously
- **Job Status Updates**: Properly update flow last_run_status when jobs complete
- **Pipeline Configuration**: Use array format instead of JSON strings for pipeline_config consistency

### Technical Details
- **Data Contract Consistency**: Standardized pipeline_config as arrays across service layer
- **Error Handling**: Improved REST API error response handling in chat tools
- **UI Reliability**: Fixed race conditions in React modal settings enrichment

## [0.5.1] - 2025-12-01

### Changed
- **ExecuteWorkflow Tool Architecture** - Consolidated modular directory structure into streamlined single-file architecture
  - Removed `ExecuteWorkflow/` subdirectory with 4 separate files (DefaultsInjector, DocumentationBuilder, ExecuteWorkflowTool, WorkflowValidator)
  - Added consolidated `ExecuteWorkflowTool.php` that delegates execution to the Execute API
  - Added shared `HandlerDocumentation.php` utility for dynamic handler documentation generation
- **Data Contract Standardization** - Centralized JSON encoding at database layer
  - Service managers now pass arrays to database operations
  - Database layer exclusively handles JSON encoding via `wp_json_encode()`
  - Eliminated dual-support fallbacks for string vs array input across 6 files
- **ConfigureFlowStep Tool** - Enhanced with dynamic handler documentation in tool description

### Fixed
- **Ephemeral Workflow Execution** - Fixed parameter key from `config` to `handler_config` in Execute API step processing

### Technical Details
- **Code Reduction**: -612 lines from ExecuteWorkflow modular structure
- **Code Addition**: +300 lines for consolidated architecture
- **Net Change**: -645 lines for cleaner, more maintainable codebase
- **Architecture**: Single source of truth for JSON encoding eliminates type ambiguity

## [0.5.0] - 2025-12-01

### Added
- **HttpClient Class** - New centralized HTTP client (`/inc/Core/HttpClient.php`) providing standardized request handling, error management, and logging across all handlers
- **@since 0.5.0** - Added version annotation to HttpClient class documentation

### Changed
- **HTTP Request Architecture** - Complete migration from filter-based HTTP requests to centralized HttpClient usage across all fetch and publish handlers
- **Handler Base Classes** - Updated `FetchHandler` and `PublishHandler` to integrate HttpClient for consistent HTTP operations
- **OAuth and Authentication** - Enhanced OAuth2Handler and provider classes with improved HTTP client integration

### Improved
- **Error Handling** - Standardized HTTP error responses and logging across all external API interactions
- **Code Consistency** - Unified HTTP request patterns eliminating duplication across 16+ handler files
- **Performance** - Optimized HTTP operations with consistent timeout handling and browser simulation capabilities

### Technical Details
- **New File**: +251 lines in HttpClient.php
- **Refactored Files**: 18 handlers updated to use HttpClient
- **Code Reduction**: -114 lines removed from DataMachineFilters.php HTTP filter logic
- **Compatibility**: No breaking changes, fully backward compatible

## [0.4.9] - 2025-11-30

### Enhanced
- **UI Layout Improvements** - Restructured flow header and footer components for better information hierarchy
  - Moved Flow ID display from header title area to footer for cleaner header layout
  - Improved flow card header structure with better action button organization
  - Enhanced FlowHeader and FlowFooter component layouts

### Improved
- **Chat Tool Documentation** - Enhanced RunFlow tool parameter descriptions for clearer immediate vs scheduled execution
  - Improved timestamp parameter documentation to prevent confusion
  - Better tool description clarity for AI agent usage

### Added
- **Comprehensive Chat Tool Documentation** - Created complete documentation for all 8 specialized chat tools
  - AddPipelineStep - Pipeline step management with automatic flow synchronization
  - ApiQuery - REST API discovery and query tool with comprehensive endpoint documentation
  - ConfigureFlowStep - Flow step configuration for handlers and AI messages
  - ConfigurePipelineStep - Pipeline-level AI settings configuration
  - CreateFlow - Flow creation from existing pipelines
  - CreatePipeline - Pipeline creation with optional predefined steps
  - RunFlow - Flow execution and scheduling tool
  - UpdateFlow - Flow property update tool

### Fixed
- **Modal Navigation** - Added "Back to Settings" button option in OAuth authentication modal
- **CSS Layout** - Improved flow header alignment and modal width handling for better responsive design
- **Documentation Version Synchronization** - Updated all version references from v0.4.6 to v0.4.9 across documentation

### Technical Details
- **Documentation**: Added 8 new comprehensive tool documentation files
- **Code Changes**: +15 lines added, -25 lines removed across 10 modified files
- **UI Components**: Enhanced FlowHeader, FlowFooter, and OAuth modal components
- **Compatibility**: No breaking changes, fully backward compatible
- **Components**: Improved React component structure and CSS styling

## [0.4.8] - 2025-11-30

### Enhanced
- **Chat Tools**: Improved CreatePipeline tool with AI step parameter support (provider, model, system_prompt) and clearer flow creation messaging to prevent duplicate flow creation
- **UI Improvements**: Added flow ID display in flow headers for better identification and enhanced flow card layouts with improved header structure
- **API Messaging**: Updated execution success messages for better async operation clarity and job status tracking

### Technical Details
- **Code Changes**: +47 lines for UI enhancements and chat tool improvements
- **Compatibility**: No breaking changes, fully backward compatible
- **Components**: Enhanced FlowHeader and FlowCard React components with better layout and information display

## [0.4.7] - 2025-11-30

### Enhanced
- **AI System Extensibility** - Improved dynamic provider and step type validation across chat and execution APIs
  - Chat API now validates AI providers dynamically using `chubes_ai_providers` filter instead of hardcoded enum
  - Execute API validates step types dynamically using `datamachine_step_types` filter instead of hardcoded array
  - Flow scheduling intervals now use dynamic validation via `datamachine_scheduler_intervals` filter

### Improved
- **PromptBuilder Architecture** - Streamlined directive application process with simplified build method
  - Removed verbose directive tracking and logging for cleaner code execution
  - Maintained core functionality while reducing code complexity
  - Improved performance through reduced overhead in directive processing

### Technical Details
- **Code Reduction**: -31 lines net change in PromptBuilder.php for improved maintainability
- **API Flexibility**: Dynamic validation enables easier extension of providers, step types, and scheduling intervals
- **Architecture**: Enhanced filter-based extensibility for core system components

## [0.4.6] - 2025-11-30

### Removed
- **Centralized Cache System** - Eliminated `inc/Engine/Actions/Cache.php` (329 lines) and `docs/core-system/cache-management.md` (398 lines)
  - Removed Cache action class with granular invalidation patterns
  - Removed cache management documentation and architectural patterns
  - Simplified DataMachineActions.php by removing cache registration

### Changed
- **Architecture Simplification** - Streamlined codebase through distributed caching approach
  - Maintained essential caching in PluginSettings, SiteContext, and EngineData
  - Eliminated centralized cache management in favor of component-level caching
  - Reduced code complexity and maintenance overhead

### Improved
- **Codebase Efficiency** - Net reduction of 1,602 lines across 27 files
  - Simplified database operation files and service managers
  - Consolidated documentation and removed redundant content
  - Enhanced maintainability through architectural simplification

### Technical Details
- **Code Reduction**: -1,602 lines net change across 27 modified files
- **Architecture**: Transition from centralized to distributed caching model
- **Performance**: Maintained caching benefits while reducing system complexity
- **Compatibility**: No breaking changes to APIs or user-facing functionality

## [0.4.5] - 2025-11-30

### Changed
- **ChatAgentDirective** - Simplified system prompt by removing verbose API documentation (-219 lines)
- **Chat Agent UX** - Streamlined directive from detailed handler tables to focused workflow guidance
- **System Prompt Architecture** - Shifted from comprehensive API reference to high-level workflow assistance

### Improved
- **Chat Agent Performance** - Reduced system prompt complexity for better AI agent focus
- **Documentation Separation** - API discovery now handled via `api_query` tool instead of system prompt
- **Maintainability** - Simplified directive structure easier to maintain and update

### Technical Details
- **Code Reduction**: -212 lines net change in ChatAgentDirective
- **Architecture**: Cleaner separation between system guidance and API discovery
- **Focus**: Chat agent now emphasizes workflow configuration over API documentation

## [0.4.4] - 2025-11-29

### Added
- **ConfigurePipelineStep Chat Tool** - Specialized tool for configuring pipeline-level AI step settings including system prompt, provider, model, and enabled tools
- **RunFlow Chat Tool** - Dedicated tool for executing existing flows immediately or scheduling delayed execution with proper validation
- **UpdateFlow Chat Tool** - Focused tool for updating flow-level properties including title and scheduling configuration

### Enhanced
- **ChatAgentDirective** - Updated system prompt documentation to include new specialized tools and improved workflow patterns
- **ApiQuery Tool** - Enhanced REST API query tool with comprehensive endpoint documentation for better discovery
- **ExecuteWorkflowTool** - Improved workflow execution with better error handling and response formatting
- **DocumentationBuilder** - Enhanced dynamic documentation generation from registered handlers

### Improved
- **Chat Tool Architecture** - Expanded specialized tool ecosystem for better AI agent performance and task separation
- **Tool Validation** - Added comprehensive parameter validation across all new chat tools
- **Error Handling** - Standardized error responses and success messages across new tools
- **Documentation** - Updated tool descriptions and parameter documentation for clarity

### Technical Details
- **Tool Specialization**: 3 new specialized tools added to chat agent toolkit
- **Code Addition**: +447 lines of new specialized chat tool functionality
- **Enhanced Capabilities**: Better AI agent performance through focused, operation-specific tools

## [0.4.3] - 2025-11-29

### Added
- **Specialized Chat Tools System** - Complete refactoring replacing generic MakeAPIRequest with focused, operation-specific tools:
  - **AddPipelineStep Tool** - Adds steps to existing pipelines with automatic flow synchronization
  - **ApiQuery Tool** - Dedicated REST API query tool with comprehensive endpoint documentation
  - **CreatePipeline Tool** - Enhanced pipeline creation with optional predefined steps
  - **CreateFlow Tool** - Streamlined flow creation from existing pipelines
  - **ConfigureFlowStep Tool** - Focused tool for configuring handlers and AI messages

### Changed
- **Chat Agent Architecture** - Migrated from generic API tool to specialized tools for improved AI agent performance
- **Tool Separation of Concerns** - Clear division between workflow operations and API management
- **Composer Autoload Configuration** - Updated to include new specialized tools

### Removed
- **MakeAPIRequest Tool** - Eliminated generic tool in favor of specialized, focused tools

### Technical Details
- **Tool Specialization**: 5 specialized tools replace 1 generic tool for better operation accuracy
- **Code Optimization**: Net +400 lines for dramatically improved AI agent capabilities

## [0.4.2] - 2025-11-29

### Added
- **CreateFlow Chat Tool** - Specialized tool for creating flow instances from existing pipelines with automatic step synchronization
  - Validates pipeline_id and scheduling configuration
  - Returns flow_step_ids for subsequent configuration
  - Supports all scheduling intervals (manual, hourly, daily, weekly, monthly, one_time)
- **ConfigureFlowStep Chat Tool** - Focused tool for configuring flow step handlers and AI user messages
  - Supports handler configuration for fetch/publish/update steps
  - Supports user_message configuration for AI steps
  - Uses flow_step_ids returned from create_flow tool

### Enhanced
- **Chat API Response Structure** - Added completion status and warning system
  - Added `completed` field to indicate conversation completion status
  - Added `warning` field for conversation turn limit notifications
  - Improved response data organization for better client handling

### Changed
- **MakeAPIRequest Tool Documentation** - Updated to reference specialized tools for cleaner separation of concerns
  - Removed flow creation and step configuration endpoints from tool scope
  - Added clear references to create_flow and configure_flow_step tools
  - Simplified endpoint documentation to focus on monitoring and management operations

### Improved
- **Chat Tool Architecture** - Better specialization with dedicated tools for specific workflow operations
- **Conversation Management** - Enhanced warning system when maximum conversation turns are reached
- **Tool Separation** - Clearer division between workflow creation/configuration (specialized tools) and API management (MakeAPIRequest)

## [0.4.1] - 2025-11-29

### Added
- **DynamicToolProvider Base Class** - New abstract base class for engine-aware tool parameter providers
  - Prevents AI from requesting parameters that already exist in engine data
  - Centralized pattern for dynamic tool parameter filtering

### Enhanced
- **ToolExecutor** - Added engine_data parameter to getAvailableTools() for dynamic tool generation
- **HandlerRegistrationTrait** - Updated AI tools filter registration to pass 4 parameters (added engine_data)
- **AIStep** - Enhanced to pass engine data to tool executor for better tool availability determination

### Technical Details
- **AI Tool Intelligence**: Tools now dynamically adjust parameters based on existing engine data values
- **Parameter Filtering**: Prevents redundant parameter requests in AI conversations
- **Engine Awareness**: Tool system now considers workflow context for parameter requirements

## [0.4.0] - 2025-11-29

### BREAKING CHANGES
- **Complete Service Layer Migration** - Replaced filter-based action system with direct OOP service manager architecture
  - Removed `inc/Engine/Actions/Delete.php` (581 lines) - Use `DataMachine\Services\*Manager` classes instead
  - Removed `inc/Engine/Actions/Update.php` (396 lines) - Use dedicated service managers
  - Removed `inc/Engine/Actions/FailJob.php` (94 lines) - Integrated into service managers
  - Removed `inc/Engine/Filters/Create.php` (540 lines) - Use service manager create methods
  - **Note**: All REST API endpoints maintain identical signatures - no breaking changes for frontend consumers

### Added
- **Services Layer Architecture** (`/inc/Services/`) - New centralized business logic with 7 dedicated manager classes:
  - **FlowManager** - Complete flow CRUD operations with validation and error handling
  - **FlowStepManager** - Flow step management with pipeline synchronization
  - **PipelineManager** - Pipeline operations with cascade handling
  - **PipelineStepManager** - Pipeline step management and ordering
  - **JobManager** - Job lifecycle management and status tracking
  - **LogsManager** - Centralized logging operations with filtering
  - **ProcessedItemsManager** - Processed items tracking and cleanup operations
- **Enhanced React Components** - Improved form handling and modal management:
  - **HandlerSettingField.jsx** - Better form validation and state management
  - **HandlerSettingsModal.jsx** - Enhanced modal architecture with cleaner state handling
  - **FlowCard.jsx & FlowHeader.jsx** - Improved UI consistency and interaction patterns

### Changed
- **API Layer Refactoring** - All REST endpoints now use service managers instead of filter indirection:
  - Direct service instantiation: `$manager = new FlowManager()`
  - Eliminated filter-based action calls: `do_action('datamachine_delete_flow', $flow_id)`
  - Improved error handling with proper WP_Error objects
  - Enhanced input validation and sanitization
- **Performance Optimization** - 3x faster execution paths through direct method calls vs filter resolution
- **Code Organization** - Centralized business logic in dedicated service classes following single responsibility principle
- **Enhanced FetchHandler** - Improved base class with better error handling and logging
- **Streamlined Engine Actions** - Removed redundant cache management and simplified action handling

### Improved
- **Type Safety** - Properly typed method signatures with return type declarations
- **Debugging** - Direct method calls provide clearer stack traces vs filter chain execution
- **Testing** - Service classes enable easier unit testing and mocking
- **Documentation** - Self-documenting service methods with comprehensive PHPDoc blocks
- **Memory Efficiency** - Service instances instantiated only when needed vs persistent filter hooks
- **Error Handling** - Consistent error patterns across all service managers

### Technical Details
- **Code Reduction**: Eliminated 1,611 lines of legacy filter-based code
- **Code Addition**: Added 1,901 lines of clean, maintainable service layer code
- **Net Change**: +290 lines for dramatically better architecture
- **Performance**: Direct method invocation replaces WordPress filter indirection
- **Compatibility**: All REST API endpoints maintain identical signatures for backward compatibility
- **Architecture**: Migrated from WordPress hook-based patterns to clean OOP service architecture where appropriate

### Documentation
- Updated 15+ documentation files to reflect service layer architecture
- Enhanced API documentation for auth and chat endpoints
- Improved fetch-handler and taxonomy-handler documentation
- Updated React component documentation for new patterns

## [0.3.1] - 2025-11-26

### Added
- **Auth API** - Added `requires_auth` check in `inc/Api/Auth.php` to bypass authentication validation for handlers that don't require it (e.g., public scrapers).
- **FetchHandler** - Added `applyExcludeKeywords()` method to base `FetchHandler` class for negative keyword filtering.

### Changed
- **Job Data Handling** - Changed `JobsOperations::get_job()` to return `ARRAY_A` (associative array) instead of object, and updated `retrieve_engine_data()` to match.
- **WordPress Publishing** - Simplified `WordPressPublishHelper::applySourceAttribution()` to use standard HTML paragraph tags instead of Gutenberg separator blocks.
- **Source Attribution** - Removed `generateSourceBlock()` method in favor of cleaner HTML output.

## [0.3.0] - 2025-11-26

### Added
- **ExecuteWorkflow Tool** (`/inc/Api/Chat/Tools/ExecuteWorkflow/`) - New specialized chat tool for workflow execution with modular architecture:
  - **ExecuteWorkflowTool.php** - Main tool class, registers as `execute_workflow` chat tool with simplified step parameter structure
  - **DocumentationBuilder.php** - Dynamically builds tool description from registered handlers via `datamachine_handlers` filter
  - **WorkflowValidator.php** - Validates step structure, handler existence, and step type correctness before execution
  - **DefaultsInjector.php** - Injects provider/model/post_author defaults from plugin settings automatically
- **Dynamic Handler Documentation** - Tool descriptions now auto-generate from registered handlers, ensuring documentation stays in sync with actual capabilities

### Changed
- **ChatAgentDirective Refactoring** - Slimmed from ~450 lines to ~255 lines with pattern-based approach:
  - Handler selection tables replace verbose endpoint documentation
  - Taxonomy configuration pattern with clear three-mode options (skip, pre-selected, ai_decides)
  - Strategic guidance for ephemeral vs persistent workflow decisions
- **MakeAPIRequest Tool** - Enhanced with comprehensive API documentation for pipeline/flow management, monitoring, and troubleshooting (excludes `/execute` endpoint now handled by `execute_workflow`)
- **TaxonomyHandler Backend** - `processPreSelectedTaxonomy()` now accepts term name/slug instead of requiring numeric ID, enabling direct use of term names from site context

### Improved
- **Tool Separation of Concerns** - Clear division between workflow execution (`execute_workflow`) and API management (`make_api_request`)
- **Chat Agent Architecture** - Extensible pattern for adding new specialized tools without bloating the system directive

## [0.2.10] - 2025-11-25

### Added
- **PluginSettings Class** (`/inc/Core/PluginSettings.php`) - Centralized settings accessor with request-level caching
  - `PluginSettings::all()` - Retrieve all plugin settings with automatic caching
  - `PluginSettings::get($key, $default)` - Type-safe getter for individual settings
  - `PluginSettings::clearCache()` - Manual cache invalidation (auto-clears on option update)
- **EngineData::getPipelineStepConfig()** - New method to retrieve configuration for specific pipeline steps, distinguishing between pipeline-level AI settings and flow-level overrides
- **Cache Management Documentation** (`/docs/core-system/cache-management.md`) - Comprehensive documentation for the cache system including action-based invalidation patterns
- **Logger Documentation** (`/docs/core-system/logger.md`) - Comprehensive documentation for the Monolog-based logging system

### Changed
- **Settings Access Architecture** - Migrated from scattered `get_option('datamachine_settings')` calls to centralized `PluginSettings::get()` pattern across 15+ files:
  - API layer: Chat.php, Providers.php, Settings.php
  - Engine layer: AIStep.php, ToolManager.php, FailJob.php
  - Directives: GlobalSystemPromptDirective.php, SiteContextDirective.php
  - Core services: WordPressSettingsResolver.php, FileCleanup.php
- **Social Media Handler Defaults** - Changed `include_images` default from `true` to `false` for Twitter, Bluesky, and Threads publish handlers (aligns with Facebook handler behavior)
- **FlowSteps API** - Simplified settings extraction logic to handle empty/null settings gracefully without unnecessary conditional branches
- **AIStep Provider Resolution** - Now falls back to default provider from PluginSettings when pipeline step provider is not configured, with improved error messaging

### Fixed
- **Settings Option Key Typo** - Corrected `job_data_cleanup_on_failure` to `cleanup_job_data_on_failure` in activation defaults

### Removed
- **Redundant Debug Logging** - Removed verbose handler configuration logging from Bluesky and Threads publish handlers that provided no diagnostic value

### Documentation
- Updated 45+ documentation files to reflect v0.2.10 changes
- Added comprehensive EngineData documentation for `getPipelineStepConfig()` method
- Enhanced engine-data.md with pipeline vs flow configuration distinction

## [0.2.9] - 2025-11-25

### Removed
- **Legacy AutoSave Hook** - Completely removed the redundant `datamachine_auto_save` action hook and AutoSave.php class. The REST API architecture now handles all persistence directly, eliminating duplicate database writes and improving performance.

### Changed
- **Delete Step Execution Order Sync** - Fixed execution_order synchronization gap in pipeline step deletion by implementing inline sync logic, ensuring flow steps maintain correct execution order after step removal.
- **Code Cleanup** - Removed all AutoSave references from core action registrations and documentation.
- **UI Form Validation** - Simplified form validation logic in ConfigureStepModal and FlowScheduleModal by removing unnecessary hasChanged checks, improving user experience and reducing code complexity.

### Technical
- **Performance Improvement** - Eliminated redundant database operations that were causing unnecessary writes during pipeline modifications.
- **Architecture Simplification** - Streamlined persistence logic by removing legacy hook-based approach in favor of direct REST API handling.

## [0.2.8] - 2025-11-25

### Added
- **Standardized Publish Fields** - Added `WordPressSettingsHandler::get_standard_publish_fields()` to centralize common WordPress publishing settings configuration.

### Changed
- **WordPress Settings Refactoring** - Updated `WordPressSettings` to use the new centralized standard fields method, reducing code duplication.
- **OAuth Modal UX** - Improved authentication modal to actively fetch and sync connection status, ensuring the UI always reflects the true backend state.
- **Date Formatting** - Moved date formatting logic from frontend (JS) to backend (PHP) for Jobs and Flows, ensuring consistency and respecting WordPress settings.

### Documentation
- **Architecture Updates** - Comprehensive updates to documentation reflecting v0.2.7 architectural changes (EngineData, WordPressPublishHelper).
- **Link Fixes** - Resolved broken internal links across handler documentation.

## [0.2.7] - 2025-11-24

### BREAKING CHANGES
- **EngineData API** - Removed WordPress-specific methods violating platform-agnostic architecture
  - Removed `EngineData::attachImageToPost()` (use `WordPressPublishHelper::attachImageToPost()` instead)
  - Removed `EngineData::applySourceAttribution()` (use `WordPressPublishHelper::applySourceAttribution()` instead)
  - Removed private `EngineData::generateSourceBlock()` method
- **Removed WordPressSharedTrait** - Extensions using this trait must migrate to direct EngineData usage and WordPressSettingsResolver
  - Affected: datamachine-events v0.3.0 and earlier
  - Fixed in: datamachine-events v0.3.1+

### Added
- **WordPressPublishHelper** - New centralized WordPress publishing utilities (`/inc/Core/WordPress/WordPressPublishHelper.php`)
  - `WordPressPublishHelper::attachImageToPost($post_id, $image_path, $config)` - Attach images to WordPress posts
  - `WordPressPublishHelper::applySourceAttribution($content, $source_url, $config)` - Apply source URL attribution
  - `WordPressPublishHelper::generateSourceBlock($url)` - Generate Gutenberg source blocks
- **WordPressSettingsResolver** (`/inc/Core/WordPress/WordPressSettingsResolver.php`) - Centralized utility for WordPress settings resolution with system defaults override
  - `getPostStatus()` - Single source of truth for post status resolution
  - `getPostAuthor()` - Single source of truth for post author resolution
  - Eliminates ~120 lines of duplicated code across handlers

### Removed
- **WordPressSharedTrait** (`/inc/Core/WordPress/WordPressSharedTrait.php`) - Eliminated architectural bloat by removing trait wrapper layer

### Changed
- **WordPress Publish Handler** - Migrated to use `WordPressPublishHelper` for image and source attribution operations
- **WordPress Update Handler** - Refactored to use direct EngineData instantiation
- **EngineData Architecture** - Restored platform-agnostic design, now provides only data access methods (`getImagePath()`, `getSourceUrl()`)
- **Single Source of Truth Architecture** - All handlers now use direct EngineData pattern for consistent, predictable data access
- **EngineData Configuration Keys** - Updated parameter names for consistency
  - `include_source` → `link_handling` (values: 'none', 'append')
  - `enable_images` → `include_images`
- **Handlers API** (`/inc/Api/Handlers.php`) - Enhanced with authentication status
  - Added `is_authenticated` boolean to handler metadata
  - Added `account_details` when authenticated
- **TaxonomyHandler** - Enhanced taxonomy retrieval with post type filtering
  - `getPublicTaxonomies()` now accepts optional `$post_type` parameter
- **WordPressSettingsHandler** - Added post type and exclude taxonomies support
  - Taxonomy fields now support `post_type` and `exclude_taxonomies` configuration options

### Improved
- **Architectural Consistency** - EngineData now matches pattern used by all social media handlers (Twitter, Threads, Bluesky, Facebook)
- **Single Responsibility** - WordPress-specific operations centralized in dedicated helper class
- **KISS Principle** - Eliminated WordPress dependencies from core data container class
- **UI/UX** - Pipeline Builder horizontal scrolling layout for better step navigation
- **CSS Architecture** - Moved common UI components to centralized `/inc/Core/Admin/assets/css/root.css`
  - Status badge styles centralized
  - Common admin notice component styles added
  - Hidden utility class added
- **Empty State Actions** - Added action buttons to empty state displays

### Documentation
- **WordPressSharedTrait Documentation** - Updated to mark as removed with migration guide
- **EngineData Documentation** - Removed dual-path "or via trait" references, established single direct usage pattern
- **Architecture Documentation** - Updated to reflect removal of WordPressSharedTrait and direct EngineData integration
- **WordPress Components Documentation** - Updated integration patterns to show direct EngineData usage
- **Featured Image Handler Documentation** - Removed trait wrapper references
- **Source URL Handler Documentation** - Removed trait wrapper references

## [0.2.6] - 2025-11-23

### Added
- **Base Authentication Provider Architecture** (`/inc/Core/OAuth/`) - Complete authentication provider inheritance system
  - **BaseAuthProvider** - Abstract base for all auth providers with option storage/retrieval (@since 0.2.6)
  - **BaseOAuth1Provider** - Base class for OAuth 1.0a providers extending BaseAuthProvider (@since 0.2.6)
  - **BaseOAuth2Provider** - Base class for OAuth 2.0 providers extending BaseAuthProvider (@since 0.2.6)

- **React Architecture Enhancements** (`/datamachine/src/`) - Advanced state management patterns
  - **HandlerModel.js** - Abstract model layer for handler data operations
  - **HandlerFactory.js** - Factory pattern for handler model instantiation
  - **useHandlerModel.js** - Custom hook for handler model integration
  - **ModalSwitch.jsx** - Centralized modal routing component
  - **HandlerProvider.jsx** - React context for handler state management

### Changed
- **OAuth Provider Migration** - All authentication providers now extend base classes
  - TwitterAuth extends BaseOAuth1Provider (migrated from custom implementation)
  - RedditAuth extends BaseOAuth2Provider (migrated from custom implementation)
  - FacebookAuth extends BaseOAuth2Provider (migrated from custom implementation)
  - ThreadsAuth extends BaseOAuth2Provider (migrated from custom implementation)
  - BlueskyAuth extends BaseAuthProvider (migrated from custom implementation)
  - GoogleSheetsAuth extends BaseOAuth2Provider and moved to `/inc/Core/OAuth/Providers/` directory
- **Flow Ordering** - Changed default flow sorting from newest-first to oldest-first (ASC) to ensure new flows appear at the bottom of the list
- **Pipeline Builder React Architecture** - Modernized state management and component patterns
  - Implemented model-view separation pattern for handler state management
  - Added service layer abstraction for handler-related API operations
  - Centralized modal rendering through ModalSwitch component
  - Enhanced component directory structure with models/, services/, context/ directories

### Improved
- **Code Consistency** - Unified authentication patterns across all providers through base class inheritance
- **Maintainability** - Centralized option storage logic eliminates duplication across providers
- **Extensibility** - New authentication providers integrate easily via base class extension

### Fixed
- **Taxonomy Handling** - Resolved bug in taxonomy processing and removed redundant filter registrations
- **WordPressAPI Type Safety** - Fixed TypeError by ensuring fetch_from_endpoint returns array not null
- **Engine Data Architecture** - Implemented single source of truth for execution context, removed redundant methods

### Documentation
- **EngineData Documentation** - Created comprehensive documentation for EngineData class consolidating featured image and source URL operations
- **Deprecated Handler Documentation** - Updated FeaturedImageHandler and SourceUrlHandler documentation to reflect deprecation and migration to EngineData
- **WordPress Components Documentation** - Updated to reflect v0.2.6 architecture with EngineData consolidation
- **OAuth Handlers Documentation** - Removed BaseSimpleAuthProvider references (class was never implemented), updated to reflect 3-class base architecture
- **Architecture Documentation** - Updated core architecture documentation to reflect EngineData consolidation and component evolution
- **Internal Link Cleanup** - Removed internal .md links throughout documentation per WordPress navigation handling requirements

## [0.2.5] - 2025-11-20

### Added
- **PromptBuilder.php**: New unified directive management system for AI requests
  - Centralized directive injection with priority-based ordering
  - Replaces scattered filter applications with structured builder pattern
  - Ensures consistent prompt structure across all AI agent types

### Changed
- **RequestBuilder.php**: Updated to integrate PromptBuilder for directive application
  - Streamlined AI request construction with centralized directive management
  - Improved consistency between Chat and Pipeline agent request building

## [0.2.4] - 2025-11-20

### Added
- **FlowScheduling.php**: New dedicated API endpoint for advanced flow scheduling operations
- **ModalManager.jsx**: Centralized modal rendering system for improved UI consistency
- **useFormState.js**: Generic form state management hook for React components
- **FailJob.php**: Dedicated action class for handling job failure scenarios
- **WordPressSharedTrait** (`/inc/Core/WordPress/WordPressSharedTrait.php`) - Shared functionality trait for WordPress handlers with content updates, taxonomy processing, and image handling (removed in v0.2.7)

### Changed
- **Handler Architecture Refactoring**: Consolidated handler registration by removing individual filter files
  - Eliminated 14 separate filter files (FilesFilters.php, GoogleSheetsFetchFilters.php, RedditFilters.php, etc.)
  - Integrated filter logic directly into handler classes for cleaner architecture
  - Reduced code duplication and improved maintainability
- **Schedule API Consolidation**: Removed standalone Schedule.php endpoint, integrated scheduling into Flows API
- **React Component Updates**: Enhanced modal components with improved state management and error handling
- **OAuth System Cleanup**: Removed OAuthFilters.php, consolidated OAuth functionality
- **Engine Actions Optimization**: Streamlined Engine.php and improved job execution flow

### Removed
- **Schedule.php API Endpoint**: Eliminated redundant scheduling endpoint (292 lines removed)
- **Handler Filter Files**: Removed 14 individual filter files (~800 lines) in favor of direct integration
- **OAuthFilters.php**: Consolidated OAuth filter logic into core handlers
- **Redundant API Methods**: Cleaned up duplicate functionality in various API endpoints

### Fixed
- **React Component Bugs**: Fixed various issues in modal components and form handling
- **API Consistency**: Improved endpoint standardization and error handling
- **State Management**: Enhanced React component state synchronization

### Technical Details
- **Architecture Simplification**: Reduced codebase by ~500 lines through consolidation
- **Performance Improvements**: Streamlined API calls and reduced handler registration overhead
- **Code Quality**: Improved maintainability through centralized functionality

## [0.2.3] - 2025-11-20

### Added
- **TanStack Query + Zustand Architecture** - Complete modernization of React state management
  - Replaced context-based state management with TanStack Query for server state
  - Implemented Zustand for client-side UI state management
  - Eliminated global refresh patterns for granular component updates
  - Added intelligent caching with automatic background refetching
  - Optimistic UI updates for improved user experience

### Improved
- **Performance Enhancements** - No more global component re-renders on data changes
  - Granular updates: only affected components re-render when their data changes
  - Intelligent caching prevents unnecessary API calls
  - Better error handling and loading states throughout the UI
  - Cleaner separation of server state (TanStack Query) and UI state (Zustand)

### Removed
- **Legacy Context System** - Complete removal of PipelineContext and FlowContext
  - Eliminated context brittleness and complex provider hierarchies
  - Removed old hook files that are no longer needed
  - Streamlined component architecture for better maintainability

## [0.2.2] - 2025-11-19

### Added
- **HandlerRegistrationTrait** (`/inc/Core/Steps/HandlerRegistrationTrait.php`) - Eliminates ~70% of boilerplate code across all handler registration files
  - Standardized `registerHandler()` method for consistent registration patterns
  - Automatic filter registration for handlers, auth providers, settings, and AI tools
  - Refactored all 14 handler filter files to use the trait
- **ToolRegistrationTrait** (`/inc/Engine/AI/Tools/ToolRegistrationTrait.php`) - Standardized AI tool registration functionality
  - Agent-agnostic tool registration with dynamic filter creation
  - Helper methods for global tools, chat tools, and configuration handlers
  - Extensible architecture for future agent types

### Improved
- **Server-Side Single Source of Truth** - Enhanced API as access layer for pipeline builder operations
- **Centralized ToolManager** - Consolidated tool management to reduce execution errors
- **Simplified WordPress Settings** - Removed overengineered global defaults logic from WordPress handlers
- **Directive System Cleanup** - Removed legacy compatibility code for cleaner architecture

### Removed
- **Overengineered WordPress Settings Tab** - Eliminated confusing global default functionality
- **Legacy Directive Compatibility** - Cleaned up deprecated directive system code

### Technical Details
- **Handler Registration Standardization**: All handler filter files now use HandlerRegistrationTrait, reducing code duplication by ~70%
- **Tool Registration Extensibility**: ToolRegistrationTrait enables unlimited agent specialization while maintaining consistent patterns
- **Architecture Simplification**: Removed complex WordPress settings logic in favor of programmatic workflow creation via chat endpoint

## [0.2.1] - 2025-11-18

### Added
- **Complete Base Class Architecture**: Major OOP refactoring with standardized inheritance patterns
  - **Step** (`/inc/Core/Steps/Step.php`) - Abstract base for all step types with unified payload handling, validation, logging
  - **FetchHandler** (`/inc/Core/Steps/Fetch/Handlers/FetchHandler.php`) - Base for fetch handlers with deduplication, engine data storage, filtering, logging
  - **PublishHandler** (`/inc/Core/Steps/Publish/Handlers/PublishHandler.php`) - Base for publish handlers with engine data retrieval, image validation, response formatting
  - **SettingsHandler** (`/inc/Core/Steps/Settings/SettingsHandler.php`) - Base for all handler settings with auto-sanitization based on field schema
  - **SettingsDisplayService** (`/inc/Core/Steps/Settings/SettingsDisplayService.php`) - Settings display logic with smart formatting
  - **PublishHandlerSettings** (`/inc/Core/Steps/Publish/Handlers/PublishHandlerSettings.php`) - Base settings for publish handlers with common fields
  - **FetchHandlerSettings** (`/inc/Core/Steps/Fetch/Handlers/FetchHandlerSettings.php`) - Base settings for fetch handlers with common fields
  - **DataPacket** (`/inc/Core/DataPacket.php`) - Standardized data packet creation replacing scattered array construction
- **FilesRepository Architecture**: Modular component structure at `/inc/Core/FilesRepository/`
  - **DirectoryManager** - Directory creation and path management
  - **FileStorage** - File operations and flow-isolated storage
  - **FileCleanup** - Retention policy enforcement and cleanup
  - **ImageValidator** - Image validation and metadata extraction
  - **RemoteFileDownloader** - Remote file downloading with validation
- **WordPress Shared Components**: Centralized WordPress functionality at `/inc/Core/WordPress/`
  - **FeaturedImageHandler** - Image processing and media library integration
  - **TaxonomyHandler** - Taxonomy selection and term creation
  - **SourceUrlHandler** - URL attribution with Gutenberg blocks
  - **WordPressSettingsHandler** - Shared WordPress settings fields
  - **WordPressFilters** - Service discovery registration
- **Enhanced Universal Engine**: Additional conversation management utilities
  - **ConversationManager** - Message formatting and conversation utilities
  - **ToolResultFinder** - Universal tool result search utility for data packet interpretation

### Improved
- **Code Consistency**: Standardized execution method across all step type extension files
- **Architectural Clarity**: Eliminated code duplication through inheritance patterns
- **Maintainability**: Centralized common functionality in reusable base classes

## [0.2.0] - 2025-11-14

### Added
- **Complete REST API Implementation**: Brand new REST API architecture with 10+ endpoints (Auth, Execute, Files, Flows, Jobs, Logs, Pipelines, ProcessedItems, Settings, Users) - did not exist in 0.1.2
- Complete Chat API implementation with session management, conversation persistence, and tool integration
- New REST API endpoints: Handlers, Providers, StepTypes, Tools for enhanced frontend integration
- **Universal Engine Architecture**: Shared AI infrastructure layer at `/inc/Engine/AI/` for Pipeline and Chat agents
- **AIConversationLoop**: Multi-turn conversation execution with automatic tool execution and completion detection
- **RequestBuilder**: Centralized AI request construction with hierarchical directive application
- **ToolExecutor**: Universal tool discovery, enablement validation, and execution with error handling
- **ToolParameters**: Unified parameter building for standard tools and handler tools with engine data integration
- **ConversationManager**: Message formatting and validation utilities for standardized conversation management
- **Filter-Based Directive System**: `datamachine_global_directives` and `datamachine_agent_directives` filters for extensible AI behavior
- AdminRootFilters.php for centralized admin functionality
- Standardized execution method across all step type extension files

### Changed
- **Complete migration from jQuery/AJAX to React**: Full removal of jQuery dependencies and AJAX handlers, replaced with modern React components and REST API integration
- **Massive prefix standardization**: Complete migration from `dm_` to `datamachine_` across ALL filters, actions, functions, CSS classes, database operations, and API endpoints
- **Major cache system overhaul**: Implemented granular WordPress action-based clearing system with targeted invalidation methods
- **Settings Architecture Refactoring**: Moved `SettingsHandler` to `/inc/Core/Steps/Settings/` and extracted complex display logic into `SettingsDisplayService` for better OOP organization
- **AI HTTP Client updates**: Refined and updated existing AI HTTP client integration with improved execution context handling
- **Security updates**: Latest stable package versions with vulnerability resolutions and package overrides
- **Enhanced modal system**: Resolved legacy modal conflicts and improved modal architecture
- **Directory restructuring**: Plugin directory renamed from `data-machine` to `datamachine` for ecosystem consistency

### Improved
- **Performance optimizations**: 50% reduction in modal load queries and database operations
- **Filter system enhancements**: Reduced database load and query overhead through optimized patterns
- **Codebase streamlining**: Removal of dead PHP template files, unused CSS, and outdated documentation
- **Documentation updates**: Comprehensive API reference and architectural documentation alignment

### Removed
- **Complete jQuery removal**: All jQuery dependencies, AJAX handlers, and legacy JavaScript patterns
- **Legacy prefix cleanup**: All remaining `dm_` prefixed code components
- **Dead code elimination**: Unused PHP templates, CSS files, and development artifacts
- **Outdated files**: Removed next-steps.md and other obsolete documentation

### Deprecated
- **AIStepConversationManager**: Replaced by Universal Engine components (AIConversationLoop + ConversationManager)
- **AIStepToolParameters**: Replaced by ToolParameters class in Universal Engine
- **Direct ai-http-client calls**: Use RequestBuilder::build() instead for consistent directive application

### Fixed
- **Chat endpoint refinements**: Improved chat API functionality and error handling
- **Security updates**: Fixed webpack-dev-server vulnerabilities and upgraded js-yaml to address Dependabot alerts
- **Package updates**: Updated all packages to latest stable versions for security and compatibility

### Improved
- **Code consistency**: Standardized execution method across all step type extension files
- **Performance optimizations**: Removed dead pipeline status CSS file and cleaned up unused assets
- **Documentation accuracy**: Corrected changelog entries and updated @since comments in Chat API files

## [0.1.2] - 2025-10-10

### Security
- Updated all packages to latest stable versions to address security vulnerabilities
- Added package overrides and force resolutions for remaining moderate vulnerabilities

### Fixed
- Bluesky authentication issues preventing successful OAuth flow
- Flow step card CSS styling inconsistencies in pipeline interface
- Vision/file info handling in fetch step data packets for proper image processing
- Type bug in AIStepConversationManager affecting conversation state

### Changed
- Renamed plugin directory from `data-machine` to `datamachine` for consistency with function prefixes
- Completed migration from `dm_` to `datamachine_` prefix across all remaining code components
- Updated gitignore patterns for production React assets
- Continued React migration and jQuery removal across admin interfaces
- Refined WordPress publishing system with improved component integration

### Improved
- WordPress Update handler now performs granular content updates instead of full post replacement
- AI directive system enhanced with pipeline context visualization and "YOU ARE HERE" marker
- Filter system database load optimizations reducing query overhead
- Comprehensive AI error logging and optimized fetch handler logging
- Bluesky settings interface aligned with ecosystem standards

### Removed
- Removed outdated `next-steps.md` file

## [0.1.1] - 2025-09-24

### Added
- Admin notice for optional database cleanup of legacy display_order column
- User-controlled migration path instead of automatic database modifications
- Proper logging for database cleanup operations

### Removed
- Flow reordering system including drag & drop arrows, database display_order column, and complex position management
- PipelineReorderAjax class and associated JavaScript reordering methods
- CSS styles for flow reordering arrows and drag interactions
- Database methods: increment_existing_flow_orders, update_flow_display_orders, move_flow_up, move_flow_down

### Improved
- Flow creation performance: reduced from 21 database operations to 1 operation per new flow
- Simplified flow ordering using natural newest-first behavior (ORDER BY flow_id DESC)
- Eliminated unnecessary cache clearing operations during flow management
- Streamlined database schema by removing unused display_order column and index



### Technical Details
- Maintained newest-at-top flow display behavior without complex reordering UI
- Followed WordPress native patterns for admin notices and AJAX security
- Implemented KISS principle by eliminating over-engineered cosmetic feature

## [0.1.0] - 2025-09-20

### Added
- Initial release of Data Machine
- Pipeline+Flow architecture for AI-powered content workflows
- Multi-provider AI integration (OpenAI, Anthropic, Google, Grok, OpenRouter)
- Visual pipeline builder with drag & drop functionality
- Fetch handlers: RSS, Reddit, Google Sheets, WordPress Local/API/Media, Files
- Publish handlers: Twitter, Bluesky, Threads, Facebook, WordPress, Google Sheets
- Update handlers: WordPress Update with source URL matching
- AI tools: Google Search, Local Search, WebFetch, WordPress Post Reader
- OAuth integration for social media and external services
- Job scheduling and execution engine
- Admin interface with settings, logging, and status monitoring
- Import/export functionality for pipelines
- WordPress plugin architecture with PSR-4 autoloading
