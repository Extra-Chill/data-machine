# Abilities API

WordPress 6.9 Abilities API provides standardized capability discovery and execution for Data Machine operations. All REST API, CLI, and Chat tool operations delegate to registered abilities.

## Overview

The Abilities API in `inc/Abilities/` provides a unified interface for Data Machine operations. Each ability implements `execute_callback` with `permission_callback` for consistent access control across REST API, CLI commands, and Chat tools.

**Total registered abilities**: 167

## Multi-Agent Scoping

All abilities support `agent_id` and `user_id` parameters for multi-agent scoping. The `PermissionHelper` class resolves scoped agent and user IDs, enforces ownership checks via `owns_resource()` and `owns_agent_resource()`, and controls access grants via `can_access_agent()`.

## Registered Abilities

### Pipeline Management (7 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-pipelines` | List pipelines with pagination, or get single by ID | `inc/Abilities/Pipeline/GetPipelinesAbility.php` |
| `datamachine/create-pipeline` | Create new pipeline | `inc/Abilities/Pipeline/CreatePipelineAbility.php` |
| `datamachine/update-pipeline` | Update pipeline properties | `inc/Abilities/Pipeline/UpdatePipelineAbility.php` |
| `datamachine/delete-pipeline` | Delete pipeline and associated flows | `inc/Abilities/Pipeline/DeletePipelineAbility.php` |
| `datamachine/duplicate-pipeline` | Duplicate pipeline with flows | `inc/Abilities/Pipeline/DuplicatePipelineAbility.php` |
| `datamachine/import-pipelines` | Import pipelines from JSON | `inc/Abilities/Pipeline/ImportExportAbility.php` |
| `datamachine/export-pipelines` | Export pipelines to JSON | `inc/Abilities/Pipeline/ImportExportAbility.php` |

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
| `datamachine/get-flows` | List flows with filtering, or get single by ID | `inc/Abilities/Flow/GetFlowsAbility.php` |
| `datamachine/create-flow` | Create new flow from pipeline | `inc/Abilities/Flow/CreateFlowAbility.php` |
| `datamachine/update-flow` | Update flow properties | `inc/Abilities/Flow/UpdateFlowAbility.php` |
| `datamachine/delete-flow` | Delete flow and associated jobs | `inc/Abilities/Flow/DeleteFlowAbility.php` |
| `datamachine/duplicate-flow` | Duplicate flow within pipeline | `inc/Abilities/Flow/DuplicateFlowAbility.php` |

### Flow Steps (4 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-flow-steps` | List steps for a flow, or get single by ID | `inc/Abilities/FlowStep/GetFlowStepsAbility.php` |
| `datamachine/update-flow-step` | Update flow step config | `inc/Abilities/FlowStep/UpdateFlowStepAbility.php` |
| `datamachine/configure-flow-steps` | Bulk configure flow steps | `inc/Abilities/FlowStep/ConfigureFlowStepsAbility.php` |
| `datamachine/validate-flow-steps-config` | Validate flow steps configuration | `inc/Abilities/FlowStep/ValidateFlowStepsConfigAbility.php` |

### Queue Management (7 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/queue-add` | Add item to flow queue | `inc/Abilities/Flow/QueueAbility.php` |
| `datamachine/queue-list` | List queue entries | `inc/Abilities/Flow/QueueAbility.php` |
| `datamachine/queue-clear` | Clear queue | `inc/Abilities/Flow/QueueAbility.php` |
| `datamachine/queue-remove` | Remove item from queue | `inc/Abilities/Flow/QueueAbility.php` |
| `datamachine/queue-update` | Update queue item | `inc/Abilities/Flow/QueueAbility.php` |
| `datamachine/queue-move` | Reorder queue item | `inc/Abilities/Flow/QueueAbility.php` |
| `datamachine/queue-settings` | Get/set queue settings | `inc/Abilities/Flow/QueueAbility.php` |

### Webhook Triggers (5 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/webhook-trigger-enable` | Enable webhook trigger for a flow and generate Bearer token | `inc/Abilities/Flow/WebhookTriggerAbility.php` |
| `datamachine/webhook-trigger-disable` | Disable webhook trigger, revoke token | `inc/Abilities/Flow/WebhookTriggerAbility.php` |
| `datamachine/webhook-trigger-regenerate` | Regenerate webhook token (old token immediately invalidated) | `inc/Abilities/Flow/WebhookTriggerAbility.php` |
| `datamachine/webhook-trigger-rate-limit` | Set rate limiting for flow webhook trigger | `inc/Abilities/Flow/WebhookTriggerAbility.php` |
| `datamachine/webhook-trigger-status` | Get webhook trigger status for a flow | `inc/Abilities/Flow/WebhookTriggerAbility.php` |

### Job Execution (9 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-jobs` | List jobs with filtering, or get single by ID | `inc/Abilities/Job/GetJobsAbility.php` |
| `datamachine/get-jobs-summary` | Get job status summary counts | `inc/Abilities/Job/JobsSummaryAbility.php` |
| `datamachine/delete-jobs` | Delete jobs by criteria | `inc/Abilities/Job/DeleteJobsAbility.php` |
| `datamachine/execute-workflow` | Execute workflow | `inc/Abilities/Job/ExecuteWorkflowAbility.php` |
| `datamachine/get-flow-health` | Get flow health metrics | `inc/Abilities/Job/FlowHealthAbility.php` |
| `datamachine/get-problem-flows` | List flows exceeding failure threshold | `inc/Abilities/Job/ProblemFlowsAbility.php` |
| `datamachine/recover-stuck-jobs` | Recover jobs stuck in processing state | `inc/Abilities/Job/RecoverStuckJobsAbility.php` |
| `datamachine/retry-job` | Retry a failed job | `inc/Abilities/Job/RetryJobAbility.php` |
| `datamachine/fail-job` | Manually fail a processing job | `inc/Abilities/Job/FailJobAbility.php` |

### Engine (4 abilities)

Internal abilities for the pipeline execution engine.

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/run-flow` | Run a flow | `inc/Abilities/Engine/RunFlowAbility.php` |
| `datamachine/execute-step` | Execute a pipeline step | `inc/Abilities/Engine/ExecuteStepAbility.php` |
| `datamachine/schedule-next-step` | Schedule the next step in pipeline execution | `inc/Abilities/Engine/ScheduleNextStepAbility.php` |
| `datamachine/schedule-flow` | Schedule a flow for execution | `inc/Abilities/Engine/ScheduleFlowAbility.php` |

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
| `datamachine/list-agent-files` | List memory files from agent identity and user layers | `inc/Abilities/File/AgentFileAbilities.php` |
| `datamachine/get-agent-file` | Get a single agent memory file with content | `inc/Abilities/File/AgentFileAbilities.php` |
| `datamachine/write-agent-file` | Write or update content for an agent memory file | `inc/Abilities/File/AgentFileAbilities.php` |
| `datamachine/delete-agent-file` | Delete an agent memory file (protected files cannot be deleted) | `inc/Abilities/File/AgentFileAbilities.php` |
| `datamachine/upload-agent-file` | Upload a file to the agent memory directory | `inc/Abilities/File/AgentFileAbilities.php` |

### Flow Files (5 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/list-flow-files` | List uploaded files for a flow step | `inc/Abilities/File/FlowFileAbilities.php` |
| `datamachine/get-flow-file` | Get metadata for a single flow file | `inc/Abilities/File/FlowFileAbilities.php` |
| `datamachine/delete-flow-file` | Delete an uploaded file from a flow step | `inc/Abilities/File/FlowFileAbilities.php` |
| `datamachine/upload-flow-file` | Upload a file to a flow step | `inc/Abilities/File/FlowFileAbilities.php` |
| `datamachine/cleanup-flow-files` | Cleanup data packets and temporary files for a job or flow | `inc/Abilities/File/FlowFileAbilities.php` |

### Workspace (16 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/workspace-path` | Get the agent workspace directory path | `WorkspaceAbilities.php` |
| `datamachine/workspace-list` | List repositories in the agent workspace | `WorkspaceAbilities.php` |
| `datamachine/workspace-show` | Show detailed repo info (branch, remote, latest commit, dirty status) | `WorkspaceAbilities.php` |
| `datamachine/workspace-read` | Read a text file from a workspace repository | `WorkspaceAbilities.php` |
| `datamachine/workspace-ls` | List directory contents within a workspace repository | `WorkspaceAbilities.php` |
| `datamachine/workspace-clone` | Clone a git repository into the workspace | `WorkspaceAbilities.php` |
| `datamachine/workspace-remove` | Remove a repository from the workspace | `WorkspaceAbilities.php` |
| `datamachine/workspace-write` | Create or overwrite a file in a workspace repository | `WorkspaceAbilities.php` |
| `datamachine/workspace-edit` | Find-and-replace text in a workspace repository file | `WorkspaceAbilities.php` |
| `datamachine/workspace-git-status` | Get git status for a workspace repository | `WorkspaceAbilities.php` |
| `datamachine/workspace-git-log` | Read git log entries | `WorkspaceAbilities.php` |
| `datamachine/workspace-git-diff` | Read git diff output | `WorkspaceAbilities.php` |
| `datamachine/workspace-git-pull` | Run git pull --ff-only | `WorkspaceAbilities.php` |
| `datamachine/workspace-git-add` | Stage repository paths with git add | `WorkspaceAbilities.php` |
| `datamachine/workspace-git-commit` | Commit staged changes | `WorkspaceAbilities.php` |
| `datamachine/workspace-git-push` | Push commits | `WorkspaceAbilities.php` |

### Chat Sessions (4 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/create-chat-session` | Create a new chat session for a user | `inc/Abilities/Chat/CreateChatSessionAbility.php` |
| `datamachine/list-chat-sessions` | List chat sessions with pagination and context filtering | `inc/Abilities/Chat/ListChatSessionsAbility.php` |
| `datamachine/get-chat-session` | Retrieve a chat session with conversation and metadata | `inc/Abilities/Chat/GetChatSessionAbility.php` |
| `datamachine/delete-chat-session` | Delete a chat session after verifying ownership | `inc/Abilities/Chat/DeleteChatSessionAbility.php` |

### GitHub (6 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/list-github-issues` | List issues from a GitHub repository with optional filters | `Fetch/GitHubAbilities.php` |
| `datamachine/get-github-issue` | Get a single GitHub issue with full details | `Fetch/GitHubAbilities.php` |
| `datamachine/update-github-issue` | Update a GitHub issue (title, body, labels, assignees, state) | `Fetch/GitHubAbilities.php` |
| `datamachine/comment-github-issue` | Add a comment to a GitHub issue | `Fetch/GitHubAbilities.php` |
| `datamachine/list-github-pulls` | List pull requests from a GitHub repository | `Fetch/GitHubAbilities.php` |
| `datamachine/list-github-repos` | List repositories for a user or organization | `Fetch/GitHubAbilities.php` |

### Handler Execution (9 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/fetch-rss` | Fetch items from RSS/Atom feeds | `inc/Abilities/Fetch/FetchRssAbility.php` |
| `datamachine/fetch-files` | Process uploaded files | `inc/Abilities/Fetch/FetchFilesAbility.php` |
| `datamachine/fetch-wordpress-api` | Fetch posts from WordPress REST API | `inc/Abilities/Fetch/FetchWordPressApiAbility.php` |
| `datamachine/fetch-wordpress-media` | Query WordPress media library | `inc/Abilities/Fetch/FetchWordPressMediaAbility.php` |
| `datamachine/get-wordpress-post` | Retrieve single WordPress post by ID/URL | `inc/Abilities/Fetch/GetWordPressPostAbility.php` |
| `datamachine/query-wordpress-posts` | Query WordPress posts with filters | `inc/Abilities/Fetch/QueryWordPressPostsAbility.php` |
| `datamachine/publish-wordpress` | Create WordPress posts | `inc/Abilities/Publish/PublishWordPressAbility.php` |
| `datamachine/update-wordpress` | Update existing WordPress posts | `inc/Abilities/Update/UpdateWordPressAbility.php` |
| `datamachine/fetch-reddit` | Fetch posts from Reddit API | `Fetch/FetchRedditAbility.php` |

### Duplicate Check (2 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/check-duplicate` | Check if similar content exists as published post or in queue | `inc/Abilities/DuplicateCheck/DuplicateCheckAbility.php` |
| `datamachine/titles-match` | Compare two titles for semantic equivalence using similarity engine | `inc/Abilities/DuplicateCheck/DuplicateCheckAbility.php` |

### Post Query (2 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/query-posts` | Find posts created by Data Machine, filtered by handler/flow/pipeline | `PostQueryAbilities.php` |
| `datamachine/list-posts` | List Data Machine posts with combinable filters | `PostQueryAbilities.php` |

### Content / Block Editing (3 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-post-blocks` | Get Gutenberg blocks from a post | `inc/Abilities/Content/GetPostBlocksAbility.php` |
| `datamachine/edit-post-blocks` | Update Gutenberg blocks in a post | `inc/Abilities/Content/EditPostBlocksAbility.php` |
| `datamachine/replace-post-blocks` | Replace specific blocks in a post | `inc/Abilities/Content/ReplacePostBlocksAbility.php` |

### Media (7 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/generate-alt-text` | Queue system agent generation of alt text for images | `inc/Abilities/Media/AltTextAbilities.php` |
| `datamachine/diagnose-alt-text` | Report alt text coverage for image attachments | `inc/Abilities/Media/AltTextAbilities.php` |
| `datamachine/generate-image` | Generate images using AI models via Replicate API | `inc/Abilities/Media/ImageGenerationAbilities.php` |
| `datamachine/upload-media` | Upload or fetch a media file (image/video), store in repository | `inc/Abilities/Media/MediaAbilities.php` |
| `datamachine/validate-media` | Validate a media file against platform-specific constraints | `inc/Abilities/Media/MediaAbilities.php` |
| `datamachine/video-metadata` | Extract video metadata (duration, resolution, codec) via ffprobe | `inc/Abilities/Media/MediaAbilities.php` |
| `datamachine/render-image-template` | Generate branded graphics from registered GD templates | `inc/Abilities/Media/ImageTemplateAbilities.php` |

### Image Templates (1 ability)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/list-image-templates` | List all registered image generation templates | `inc/Abilities/Media/ImageTemplateAbilities.php` |

### Image Optimization (2 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/diagnose-images` | Scan media library for oversized images, missing WebP, missing thumbnails | `inc/Abilities/Media/ImageOptimizationAbilities.php` |
| `datamachine/optimize-images` | Compress oversized images and generate WebP variants | `inc/Abilities/Media/ImageOptimizationAbilities.php` |

### Internal Linking (6 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/internal-linking` | Queue system agent insertion of semantic internal links | `InternalLinkingAbilities.php` |
| `datamachine/diagnose-internal-links` | Report internal link coverage across published posts | `InternalLinkingAbilities.php` |
| `datamachine/audit-internal-links` | Scan post content for internal links, build link graph | `InternalLinkingAbilities.php` |
| `datamachine/get-orphaned-posts` | Return posts with zero inbound internal links | `InternalLinkingAbilities.php` |
| `datamachine/check-broken-links` | HTTP HEAD check links to find broken URLs | `InternalLinkingAbilities.php` |
| `datamachine/inject-category-links` | Deterministic keyword-matching link injection (no AI) | `InternalLinkingAbilities.php` |

### SEO — Meta Descriptions (2 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/generate-meta-description` | Queue system agent generation of meta descriptions | `inc/Abilities/SEO/MetaDescriptionAbilities.php` |
| `datamachine/diagnose-meta-descriptions` | Report post excerpt (meta description) coverage | `inc/Abilities/SEO/MetaDescriptionAbilities.php` |

### SEO — IndexNow (4 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/indexnow-submit` | Submit URLs to IndexNow for instant search engine indexing | `inc/Abilities/SEO/IndexNowAbilities.php` |
| `datamachine/indexnow-status` | Get IndexNow integration status (enabled, API key, endpoint) | `inc/Abilities/SEO/IndexNowAbilities.php` |
| `datamachine/indexnow-generate-key` | Generate a new IndexNow API key | `inc/Abilities/SEO/IndexNowAbilities.php` |
| `datamachine/indexnow-verify-key` | Verify that the IndexNow key file is accessible | `inc/Abilities/SEO/IndexNowAbilities.php` |

### Analytics (4 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/bing-webmaster` | Fetch search analytics from Bing Webmaster Tools API | `inc/Abilities/Analytics/BingWebmasterAbilities.php` |
| `datamachine/google-search-console` | Fetch search analytics from Google Search Console API | `inc/Abilities/Analytics/GoogleSearchConsoleAbilities.php` |
| `datamachine/google-analytics` | Fetch visitor analytics from Google Analytics (GA4) Data API | `inc/Abilities/Analytics/GoogleAnalyticsAbilities.php` |
| `datamachine/pagespeed` | Run Lighthouse audits via PageSpeed Insights API | `inc/Abilities/Analytics/PageSpeedAbilities.php` |

### Taxonomy (5 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/get-taxonomy-terms` | List taxonomy terms | `inc/Abilities/Taxonomy/GetTaxonomyTermsAbility.php` |
| `datamachine/create-taxonomy-term` | Create a taxonomy term | `inc/Abilities/Taxonomy/CreateTaxonomyTermAbility.php` |
| `datamachine/update-taxonomy-term` | Update a taxonomy term | `inc/Abilities/Taxonomy/UpdateTaxonomyTermAbility.php` |
| `datamachine/delete-taxonomy-term` | Delete a taxonomy term | `inc/Abilities/Taxonomy/DeleteTaxonomyTermAbility.php` |
| `datamachine/resolve-term` | Resolve a term by name or slug | `inc/Abilities/Taxonomy/ResolveTermAbility.php` |

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

### Processed Items (3 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/clear-processed-items` | Clear processed items for flow (resets deduplication) | `ProcessedItemsAbilities.php` |
| `datamachine/check-processed-item` | Check if item was processed | `ProcessedItemsAbilities.php` |
| `datamachine/has-processed-history` | Check if flow has processed history | `ProcessedItemsAbilities.php` |

### Agent Ping (1 ability)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/send-ping` | Send agent ping notification | `inc/Abilities/AgentPing/SendPingAbility.php` |

### System Infrastructure (4 abilities)

| Ability | Description | Location |
|---------|-------------|----------|
| `datamachine/generate-session-title` | Generate AI-powered title for chat session | `SystemAbilities.php` |
| `datamachine/system-health-check` | Unified health diagnostics for Data Machine and extensions | `SystemAbilities.php` |
| `datamachine/create-github-issue` | Create GitHub issues programmatically | `SystemAbilities.php` |
| `datamachine/run-task` | Manually trigger a registered system task for immediate execution | `SystemAbilities.php` |

## Category Registration

The `datamachine` category is registered via `wp_register_ability_category()` on the `wp_abilities_api_categories_init` hook:

```php
wp_register_ability_category(
    'datamachine',
    array(
        'label' => 'Data Machine',
        'description' => 'Data Machine flow and pipeline operations',
    )
);
```

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

REST API endpoints, CLI commands, and Chat tools delegate to abilities for business logic. Abilities are the canonical, public-facing primitive; service classes are considered an internal implementation detail and are being phased out as abilities become fully self-contained.

```
REST API Endpoint → Ability → (Service layer used during migration) → Database
CLI Command → Ability → (Service layer used during migration) → Database
Chat Tool → Ability → (Service layer used during migration) → Database
```

Note: many ability implementations are already self-contained and do not call service managers. Where services remain, they are transitional and will be migrated into abilities per the migration plan.

### Facade Pattern

Several top-level ability classes serve as facades that instantiate sub-ability classes from subdirectories:

- `ChatAbilities.php` → `inc/Abilities/Chat/CreateChatSessionAbility.php`, etc.
- `EngineAbilities.php` → `inc/Abilities/Engine/RunFlowAbility.php`, etc.
- `FlowAbilities.php` → `inc/Abilities/Flow/QueueAbility.php`, `inc/Abilities/Flow/WebhookTriggerAbility.php`, etc.

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
