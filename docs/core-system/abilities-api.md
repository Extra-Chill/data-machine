# Abilities API

WordPress 6.9 Abilities API provides standardized capability discovery and execution for Data Machine operations. All REST API, CLI, and Chat tool operations delegate to registered abilities.

## Overview

The Abilities API in `inc/Abilities/` provides a unified interface for Data Machine operations. Each ability implements `execute_callback` with `permission_callback` for consistent access control across REST API, CLI commands, and Chat tools.

**Total registered abilities**: 193

The tables below document the core ability groups most commonly used by REST, CLI, and chat integrations. For a generated live inventory, run `wp abilities list --category=datamachine-*` or inspect the current `wp_register_ability()` callsites under `inc/Abilities/`.

## Multi-Agent Scoping

All abilities support `agent_id` and `user_id` parameters for multi-agent scoping. The `PermissionHelper` class resolves scoped agent and user IDs, enforces ownership checks via `owns_resource()` and `owns_agent_resource()`, and controls access grants via `can_access_agent()`.

## Registered Abilities

### Pipeline Management (7 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-pipelines` | List pipelines with pagination, or get single by ID | `Pipeline/GetPipelinesAbility.php` |
| `datamachine/create-pipeline` | Create new pipeline | `Pipeline/CreatePipelineAbility.php` |
| `datamachine/update-pipeline` | Update pipeline properties | `Pipeline/UpdatePipelineAbility.php` |
| `datamachine/delete-pipeline` | Delete pipeline and associated flows | `Pipeline/DeletePipelineAbility.php` |
| `datamachine/duplicate-pipeline` | Duplicate pipeline with flows | `Pipeline/DuplicatePipelineAbility.php` |
| `datamachine/import-pipelines` | Import pipelines from CSV | `Pipeline/ImportExportAbility.php` |
| `datamachine/export-pipelines` | Export pipelines to CSV | `Pipeline/ImportExportAbility.php` |

### Pipeline Steps (5 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-pipeline-steps` | List steps for a pipeline, or get single by ID | `PipelineStepAbilities.php` |
| `datamachine/add-pipeline-step` | Add step to pipeline (auto-syncs to all flows) | `PipelineStepAbilities.php` |
| `datamachine/update-pipeline-step` | Update pipeline step config (system prompt, provider, model, tools) | `PipelineStepAbilities.php` |
| `datamachine/delete-pipeline-step` | Remove step from pipeline (removes from all flows) | `PipelineStepAbilities.php` |
| `datamachine/reorder-pipeline-steps` | Reorder pipeline steps | `PipelineStepAbilities.php` |

### Flow Management (5 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-flows` | List flows with filtering, or get single by ID | `Flow/GetFlowsAbility.php` |
| `datamachine/create-flow` | Create new flow from pipeline | `Flow/CreateFlowAbility.php` |
| `datamachine/update-flow` | Update flow properties | `Flow/UpdateFlowAbility.php` |
| `datamachine/delete-flow` | Delete flow and associated jobs | `Flow/DeleteFlowAbility.php` |
| `datamachine/duplicate-flow` | Duplicate flow within pipeline | `Flow/DuplicateFlowAbility.php` |

### Flow Steps (4 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-flow-steps` | List steps for a flow, or get single by ID | `FlowStep/GetFlowStepsAbility.php` |
| `datamachine/update-flow-step` | Update flow step config | `FlowStep/UpdateFlowStepAbility.php` |
| `datamachine/configure-flow-steps` | Bulk configure flow steps | `FlowStep/ConfigureFlowStepsAbility.php` |
| `datamachine/validate-flow-steps-config` | Validate flow steps configuration | `FlowStep/ValidateFlowStepsConfigAbility.php` |

### Queue Management (13 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/queue-add` | Add item to flow queue | `Flow/QueueAbility.php` |
| `datamachine/queue-list` | List queue entries | `Flow/QueueAbility.php` |
| `datamachine/queue-clear` | Clear queue | `Flow/QueueAbility.php` |
| `datamachine/queue-remove` | Remove item from queue | `Flow/QueueAbility.php` |
| `datamachine/queue-update` | Update queue item | `Flow/QueueAbility.php` |
| `datamachine/queue-move` | Reorder queue item | `Flow/QueueAbility.php` |
| `datamachine/queue-mode` | Set queue access mode (`drain`, `loop`, or `static`) | `Flow/QueueAbility.php` |
| `datamachine/config-patch-add` | Add a fetch config patch to a flow step queue | `Flow/QueueAbility.php` |
| `datamachine/config-patch-list` | List fetch config patches | `Flow/QueueAbility.php` |
| `datamachine/config-patch-clear` | Clear fetch config patches | `Flow/QueueAbility.php` |
| `datamachine/config-patch-remove` | Remove a fetch config patch by index | `Flow/QueueAbility.php` |
| `datamachine/config-patch-update` | Update a fetch config patch by index | `Flow/QueueAbility.php` |
| `datamachine/config-patch-move` | Reorder a fetch config patch | `Flow/QueueAbility.php` |

### Webhook Triggers (8 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/webhook-trigger-enable` | Enable webhook trigger for a flow. Supports `bearer` (default) or `hmac` (template-based). | `Flow/WebhookTriggerAbility.php` |
| `datamachine/webhook-trigger-disable` | Disable webhook trigger, revoke all auth material (token, template, secrets). | `Flow/WebhookTriggerAbility.php` |
| `datamachine/webhook-trigger-regenerate` | Regenerate Bearer token (bearer mode only; old token immediately invalidated). | `Flow/WebhookTriggerAbility.php` |
| `datamachine/webhook-trigger-set-secret` | Set or replace a specific secret id on an existing HMAC flow (no grace window). | `Flow/WebhookTriggerAbility.php` |
| `datamachine/webhook-trigger-rotate-secret` | **Zero-downtime rotation** — demote current → previous with a TTL, install a fresh current. | `Flow/WebhookTriggerAbility.php` |
| `datamachine/webhook-trigger-forget-secret` | Remove a specific secret by id from the rotation list. | `Flow/WebhookTriggerAbility.php` |
| `datamachine/webhook-trigger-rate-limit` | Set rate limiting for flow webhook trigger. | `Flow/WebhookTriggerAbility.php` |
| `datamachine/webhook-trigger-status` | Get webhook trigger status — auth mode, template, secret ids. Never the secret values. | `Flow/WebhookTriggerAbility.php` |

### Job Execution (9 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-jobs` | List jobs with filtering, or get single by ID | `Job/GetJobsAbility.php` |
| `datamachine/get-jobs-summary` | Get job status summary counts | `Job/JobsSummaryAbility.php` |
| `datamachine/delete-jobs` | Delete jobs by criteria | `Job/DeleteJobsAbility.php` |
| `datamachine/execute-workflow` | Execute workflow | `Job/ExecuteWorkflowAbility.php` |
| `datamachine/get-flow-health` | Get flow health metrics | `Job/FlowHealthAbility.php` |
| `datamachine/get-problem-flows` | List flows exceeding failure threshold | `Job/ProblemFlowsAbility.php` |
| `datamachine/recover-stuck-jobs` | Recover jobs stuck in processing state | `Job/RecoverStuckJobsAbility.php` |
| `datamachine/retry-job` | Retry a failed job | `Job/RetryJobAbility.php` |
| `datamachine/fail-job` | Manually fail a processing job | `Job/FailJobAbility.php` |

### Engine (4 abilities)

Internal abilities for the pipeline execution engine.

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/run-flow` | Run a flow | `Engine/RunFlowAbility.php` |
| `datamachine/execute-step` | Execute a pipeline step | `Engine/ExecuteStepAbility.php` |
| `datamachine/schedule-next-step` | Schedule the next step in pipeline execution | `Engine/ScheduleNextStepAbility.php` |
| `datamachine/schedule-flow` | Schedule a flow for execution | `Engine/ScheduleFlowAbility.php` |

### Agent Management (6 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/list-agents` | List all registered agent identities | `AgentAbilities.php` |
| `datamachine/create-agent` | Create a new agent identity with filesystem directory and owner access | `AgentAbilities.php` |
| `datamachine/get-agent` | Retrieve a single agent by slug or ID with access grants | `AgentAbilities.php` |
| `datamachine/update-agent` | Update an agent's mutable fields (name, config, status) | `AgentAbilities.php` |
| `datamachine/delete-agent` | Delete an agent record and access grants, optionally remove filesystem | `AgentAbilities.php` |
| `datamachine/rename-agent` | Rename an agent slug — updates database and moves filesystem directory | `AgentAbilities.php` |

### Agent Memory (4 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-agent-memory` | Read agent memory content — full file or a specific section | `AgentMemoryAbilities.php` |
| `datamachine/update-agent-memory` | Write to a specific section of agent memory — set (replace) or append | `AgentMemoryAbilities.php` |
| `datamachine/search-agent-memory` | Search across agent memory content, returns matching lines with context | `AgentMemoryAbilities.php` |
| `datamachine/list-agent-memory-sections` | List all section headers in agent memory | `AgentMemoryAbilities.php` |

### Daily Memory (5 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/daily-memory-read` | Read a daily memory file by date (defaults to today) | `DailyMemoryAbilities.php` |
| `datamachine/daily-memory-write` | Write or append to a daily memory file | `DailyMemoryAbilities.php` |
| `datamachine/daily-memory-list` | List all daily memory files grouped by month | `DailyMemoryAbilities.php` |
| `datamachine/search-daily-memory` | Search across daily memory files with optional date range | `DailyMemoryAbilities.php` |
| `datamachine/daily-memory-delete` | Delete a daily memory file by date | `DailyMemoryAbilities.php` |

### Agent Files (5 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/list-agent-files` | List memory files from agent identity and user layers | `File/AgentFileAbilities.php` |
| `datamachine/get-agent-file` | Get a single agent memory file with content | `File/AgentFileAbilities.php` |
| `datamachine/write-agent-file` | Write or update content for an agent memory file | `File/AgentFileAbilities.php` |
| `datamachine/delete-agent-file` | Delete an agent memory file (protected files cannot be deleted) | `File/AgentFileAbilities.php` |
| `datamachine/upload-agent-file` | Upload a file to the agent memory directory | `File/AgentFileAbilities.php` |

### Flow Files (5 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/list-flow-files` | List uploaded files for a flow step | `File/FlowFileAbilities.php` |
| `datamachine/get-flow-file` | Get metadata for a single flow file | `File/FlowFileAbilities.php` |
| `datamachine/delete-flow-file` | Delete an uploaded file from a flow step | `File/FlowFileAbilities.php` |
| `datamachine/upload-flow-file` | Upload a file to a flow step | `File/FlowFileAbilities.php` |
| `datamachine/cleanup-flow-files` | Cleanup data packets and temporary files for a job or flow | `File/FlowFileAbilities.php` |

### Chat Sessions (4 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/create-chat-session` | Create a new chat session for a user | `Chat/CreateChatSessionAbility.php` |
| `datamachine/list-chat-sessions` | List chat sessions with pagination and context filtering | `Chat/ListChatSessionsAbility.php` |
| `datamachine/get-chat-session` | Retrieve a chat session with conversation and metadata | `Chat/GetChatSessionAbility.php` |
| `datamachine/delete-chat-session` | Delete a chat session after verifying ownership | `Chat/DeleteChatSessionAbility.php` |

### Coding / GitHub Extension Abilities

Workspace and GitHub coding abilities live in the `data-machine-code` extension plugin. Data Machine core no longer registers the old workspace, GitHub issue, or GitHub repository ability names.

### Handler Execution (8 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/fetch-rss` | Fetch items from RSS/Atom feeds | `Fetch/FetchRssAbility.php` |
| `datamachine/fetch-files` | Process uploaded files | `Fetch/FetchFilesAbility.php` |
| `datamachine/fetch-wordpress-api` | Fetch posts from WordPress REST API | `Fetch/FetchWordPressApiAbility.php` |
| `datamachine/fetch-wordpress-media` | Query WordPress media library | `Fetch/FetchWordPressMediaAbility.php` |
| `datamachine/get-wordpress-post` | Retrieve single WordPress post by ID/URL | `Fetch/GetWordPressPostAbility.php` |
| `datamachine/query-wordpress-posts` | Query WordPress posts with filters | `Fetch/QueryWordPressPostsAbility.php` |
| `datamachine/publish-wordpress` | Create WordPress posts | `Publish/PublishWordPressAbility.php` |
| `datamachine/update-wordpress` | Update existing WordPress posts | `Update/UpdateWordPressAbility.php` |

### Duplicate Check (2 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/check-duplicate` | Check if similar content exists as published post or in queue | `DuplicateCheck/DuplicateCheckAbility.php` |
| `datamachine/titles-match` | Compare two titles for semantic equivalence using similarity engine | `DuplicateCheck/DuplicateCheckAbility.php` |

> **Extensions:** `datamachine/check-duplicate` runs extension strategies before core's generic matching via the `datamachine_duplicate_strategies` filter. See [Duplicate Detection Filters](../development/hooks/core-filters.md#duplicate-detection-filters) for the strategy contract and registration example.

### Post Query (2 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/query-posts` | Find posts created by Data Machine, filtered by handler/flow/pipeline | `PostQueryAbilities.php` |
| `datamachine/list-posts` | List Data Machine posts with combinable filters | `PostQueryAbilities.php` |

### Content / Block Editing (3 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-post-blocks` | Get Gutenberg blocks from a post | `Content/GetPostBlocksAbility.php` |
| `datamachine/edit-post-blocks` | Update Gutenberg blocks in a post | `Content/EditPostBlocksAbility.php` |
| `datamachine/replace-post-blocks` | Replace specific blocks in a post | `Content/ReplacePostBlocksAbility.php` |

### Media (7 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/generate-alt-text` | Queue system agent generation of alt text for images | `Media/AltTextAbilities.php` |
| `datamachine/diagnose-alt-text` | Report alt text coverage for image attachments | `Media/AltTextAbilities.php` |
| `datamachine/generate-image` | Generate images using AI models via Replicate API | `Media/ImageGenerationAbilities.php` |
| `datamachine/upload-media` | Upload or fetch a media file (image/video), store in repository | `Media/MediaAbilities.php` |
| `datamachine/validate-media` | Validate a media file against platform-specific constraints | `Media/MediaAbilities.php` |
| `datamachine/video-metadata` | Extract video metadata (duration, resolution, codec) via ffprobe | `Media/MediaAbilities.php` |
| `datamachine/render-image-template` | Generate branded graphics from registered GD templates | `Media/ImageTemplateAbilities.php` |

### Image Templates (1 ability)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/list-image-templates` | List all registered image generation templates | `Media/ImageTemplateAbilities.php` |

### Image Optimization (2 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/diagnose-images` | Scan media library for oversized images, missing WebP, missing thumbnails | `Media/ImageOptimizationAbilities.php` |
| `datamachine/optimize-images` | Compress oversized images and generate WebP variants | `Media/ImageOptimizationAbilities.php` |

### Internal Linking (7 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/internal-linking` | Queue system agent insertion of semantic internal links | `InternalLinkingAbilities.php` |
| `datamachine/diagnose-internal-links` | Report internal link coverage across published posts | `InternalLinkingAbilities.php` |
| `datamachine/audit-internal-links` | Scan post content for internal links, build link graph | `InternalLinkingAbilities.php` |
| `datamachine/get-orphaned-posts` | Return posts with zero inbound internal links | `InternalLinkingAbilities.php` |
| `datamachine/get-backlinks` | Return posts that link to a given post | `InternalLinkingAbilities.php` |
| `datamachine/check-broken-links` | HTTP HEAD check links to find broken URLs | `InternalLinkingAbilities.php` |
| `datamachine/link-opportunities` | Rank candidate internal links from search analytics and the cached link graph | `InternalLinkingAbilities.php` |

### SEO — Meta Descriptions (2 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/generate-meta-description` | Queue system agent generation of meta descriptions | `SEO/MetaDescriptionAbilities.php` |
| `datamachine/diagnose-meta-descriptions` | Report post excerpt (meta description) coverage | `SEO/MetaDescriptionAbilities.php` |

### SEO — IndexNow (4 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/indexnow-submit` | Submit URLs to IndexNow for instant search engine indexing | `SEO/IndexNowAbilities.php` |
| `datamachine/indexnow-status` | Get IndexNow integration status (enabled, API key, endpoint) | `SEO/IndexNowAbilities.php` |
| `datamachine/indexnow-generate-key` | Generate a new IndexNow API key | `SEO/IndexNowAbilities.php` |
| `datamachine/indexnow-verify-key` | Verify that the IndexNow key file is accessible | `SEO/IndexNowAbilities.php` |

### Analytics (4 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/bing-webmaster` | Fetch search analytics from Bing Webmaster Tools API | `Analytics/BingWebmasterAbilities.php` |
| `datamachine/google-search-console` | Fetch search analytics from Google Search Console API | `Analytics/GoogleSearchConsoleAbilities.php` |
| `datamachine/google-analytics` | Fetch visitor analytics from Google Analytics (GA4) Data API | `Analytics/GoogleAnalyticsAbilities.php` |
| `datamachine/pagespeed` | Run Lighthouse audits via PageSpeed Insights API | `Analytics/PageSpeedAbilities.php` |

### Taxonomy (5 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-taxonomy-terms` | List taxonomy terms | `Taxonomy/GetTaxonomyTermsAbility.php` |
| `datamachine/create-taxonomy-term` | Create a taxonomy term | `Taxonomy/CreateTaxonomyTermAbility.php` |
| `datamachine/update-taxonomy-term` | Update a taxonomy term | `Taxonomy/UpdateTaxonomyTermAbility.php` |
| `datamachine/delete-taxonomy-term` | Delete a taxonomy term | `Taxonomy/DeleteTaxonomyTermAbility.php` |
| `datamachine/resolve-term` | Resolve a term by name or slug | `Taxonomy/ResolveTermAbility.php` |

### Settings (7 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-settings` | Get plugin settings including AI settings and masked API keys | `SettingsAbilities.php` |
| `datamachine/update-settings` | Partial update of plugin settings | `SettingsAbilities.php` |
| `datamachine/get-scheduling-intervals` | Get available scheduling intervals | `SettingsAbilities.php` |
| `datamachine/get-tool-config` | Get AI tool configuration with fields and current values | `SettingsAbilities.php` |
| `datamachine/save-tool-config` | Save AI tool configuration | `SettingsAbilities.php` |
| `datamachine/get-handler-defaults` | Get handler default settings grouped by step type | `SettingsAbilities.php` |
| `datamachine/update-handler-defaults` | Update defaults for a specific handler | `SettingsAbilities.php` |

### Authentication (3 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-auth-status` | Get OAuth connection status | `AuthAbilities.php` |
| `datamachine/disconnect-auth` | Disconnect OAuth provider | `AuthAbilities.php` |
| `datamachine/save-auth-config` | Save OAuth API configuration | `AuthAbilities.php` |

### Logging (5 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/write-to-log` | Write log entry with level routing | `LogAbilities.php` |
| `datamachine/clear-logs` | Clear logs by agent type | `LogAbilities.php` |
| `datamachine/read-logs` | Read logs with filtering and pagination | `LogAbilities.php` |
| `datamachine/get-log-metadata` | Get log entry counts and time range | `LogAbilities.php` |
| `datamachine/read-debug-log` | Read PHP debug.log entries | `LogAbilities.php` |

### Local Search (1 ability)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/local-search` | Search WordPress site for posts by title or content | `LocalSearchAbilities.php` |

### Handler Discovery (5 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-handlers` | List available handlers, or get single by slug | `HandlerAbilities.php` |
| `datamachine/validate-handler` | Validate handler configuration | `HandlerAbilities.php` |
| `datamachine/get-handler-config-fields` | Get handler configuration fields | `HandlerAbilities.php` |
| `datamachine/apply-handler-defaults` | Apply default settings to handler | `HandlerAbilities.php` |
| `datamachine/get-handler-site-defaults` | Get site-wide handler defaults | `HandlerAbilities.php` |

### Step Types (2 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-step-types` | List available step types, or get single by slug | `StepTypeAbilities.php` |
| `datamachine/validate-step-type` | Validate step type configuration | `StepTypeAbilities.php` |

### Processed Items (6 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/clear-processed-items` | Clear processed items for flow (resets deduplication) | `ProcessedItemsAbilities.php` |
| `datamachine/check-processed-item` | Check if item was processed | `ProcessedItemsAbilities.php` |
| `datamachine/has-processed-history` | Check if flow has processed history | `ProcessedItemsAbilities.php` |
| `datamachine/processed-items-get-processed-at` | Get last-processed Unix timestamp for an item (or null) | `ProcessedItemsAbilities.php` |
| `datamachine/processed-items-find-stale` | Given candidates, return those older than N days | `ProcessedItemsAbilities.php` |
| `datamachine/processed-items-find-never-processed` | Given candidates, return those never processed | `ProcessedItemsAbilities.php` |

### Agent Ping (1 ability)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/send-ping` | Send agent ping notification | `AgentPing/SendPingAbility.php` |

### System Infrastructure (3 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/generate-session-title` | Generate AI-powered title for chat session | `SystemAbilities.php` |
| `datamachine/system-health-check` | Unified health diagnostics for Data Machine and extensions | `SystemAbilities.php` |
| `datamachine/run-task` | Manually trigger a registered system task for immediate execution | `SystemAbilities.php` |

## Category Registration

Data Machine registers multiple ability categories via `wp_register_ability_category()` on the `wp_abilities_api_categories_init` hook. Category slugs use the `datamachine-{domain}` format (e.g. `datamachine-content`, `datamachine-flow`, `datamachine-pipeline`):

```php
wp_register_ability_category(
    'datamachine-flow',
    array(
        'label' => 'Flow',
        'description' => 'Flow CRUD, scheduling, queue management, and webhook triggers.',
    )
);
```

See `AbilityCategories.php` for the full list of registered categories.

## Permission Model

All abilities support both WordPress admin and WP-CLI contexts via the shared `PermissionHelper`:

```php
// Standard permission check
PermissionHelper::can_manage(); // WP-CLI always returns true; web requires manage_options

// Multi-agent scoped permission check
PermissionHelper::can_access_agent($agent_id);
PermissionHelper::owns_resource($resource_user_id);
PermissionHelper::resolve_scoped_agent_id($params);
PermissionHelper::resolve_scoped_user_id($params);
```

## Architecture

### Delegation Pattern

REST API endpoints, CLI commands, and Chat tools delegate to abilities for business logic. Abilities are the canonical, public-facing primitive; implementation classes below an ability are internal details.

```
REST API Endpoint → Ability → Database / WordPress API
CLI Command → Ability → Database / WordPress API
Chat Tool → Ability → Database / WordPress API
```

### Facade Pattern

Several top-level ability classes serve as facades that instantiate sub-ability classes from subdirectories; other domains are registered directly from their subdirectory classes:

- `ChatAbilities.php` → `Chat/CreateChatSessionAbility.php`, etc.
- `EngineAbilities.php` → `Engine/RunFlowAbility.php`, etc.
- Flow abilities are registered from `Flow/CreateFlowAbility.php`, `Flow/QueueAbility.php`, `Flow/WebhookTriggerAbility.php`, etc.

### Ability Registration

Each abilities class registers abilities on the `wp_abilities_api_init` hook:

```php
public function register(): void {
    add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
}
```

## Testing

Unit tests in `tests/Unit/Abilities/` verify ability registration, schema validation, permission checks, and execution logic:

- `AuthAbilitiesTest.php` - Authentication abilities
- `FileAbilitiesTest.php` - File management abilities
- `FlowAbilitiesTest.php` - Flow CRUD abilities
- `FlowStepAbilitiesTest.php` - Flow step abilities
- `JobAbilitiesTest.php` - Job execution abilities
- `LogAbilitiesTest.php` - Logging abilities
- `PipelineAbilitiesTest.php` - Pipeline CRUD abilities
- `PipelineStepAbilitiesTest.php` - Pipeline step abilities
- `PostQueryAbilitiesTest.php` - Post query abilities
- `ProcessedItemsAbilitiesTest.php` - Processed items abilities
- `SettingsAbilitiesTest.php` - Settings abilities

## WP-CLI Integration

CLI commands execute abilities directly. See individual command files in `inc/Cli/Commands/` for available commands.

## Post Tracking

The `PostTracking` class in `inc/Core/WordPress/PostTracking.php` provides post tracking functionality for handlers creating WordPress posts.

**Meta Keys**:
- `_datamachine_post_handler`: Handler slug that created the post
- `_datamachine_post_flow_id`: Flow ID associated with the post
- `_datamachine_post_pipeline_id`: Pipeline ID associated with the post

**Usage**:
```php
use DataMachine\Core\WordPress\PostTracking;

// After creating a post
$this->storePostTrackingMeta($post_id, $handler_config);
```
