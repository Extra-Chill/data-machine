# System Tasks

System tasks are background operations that run outside the normal pipeline execution cycle. They handle AI-powered content operations (alt text, meta descriptions, internal linking), media processing (image generation, optimization), and agent maintenance (daily memory, GitHub issues). All system tasks share a common base class with standardized job management, effect tracking, and undo support.

## Overview

The system tasks framework consists of:

1. **SystemTask base class** — abstract base with job completion, failure handling, rescheduling, and undo
2. **SystemAgentServiceProvider** — registers task handlers and Action Scheduler hooks
3. **TaskRegistry** — central registry of available task types
4. **TaskScheduler** — schedules and dispatches tasks via Action Scheduler
5. **SystemTaskStep** — pipeline step type that bridges system tasks into pipeline workflows
6. **Seven built-in tasks** — each implementing a specific AI or system operation

## SystemTask Base Class

**Source:** `inc/Engine/AI/System/Tasks/SystemTask.php`
**Since:** v0.22.4

All system tasks extend this abstract class:

```php
abstract class SystemTask {
    abstract public function execute(int $jobId, array $params): void;
    abstract public function getTaskType(): string;
}
```

### Job Lifecycle Methods

| Method | Description |
|--------|-------------|
| `completeJob(int $jobId, array $result)` | Store result in `engine_data` and mark job as `completed`. Logs success. |
| `failJob(int $jobId, string $reason)` | Store error in `engine_data` and mark job as `failed`. Logs error. |
| `reschedule(int $jobId, int $delaySeconds)` | Reschedule for later execution via Action Scheduler. Tracks attempt count with a default max of 24 attempts. |

### Task Metadata

Tasks declare UI metadata via the static `getTaskMeta()` method:

```php
public static function getTaskMeta(): array {
    return [
        'label'           => 'Human-readable task name',
        'description'     => 'What this task does',
        'setting_key'     => 'wp_option_key_to_enable',  // null if always available
        'default_enabled' => true,
        'trigger'         => 'How it gets triggered',
        'trigger_type'    => 'cron|event|tool|manual',
        'supports_run'    => false,                       // Can be manually triggered?
    ];
}
```

### AI Model Resolution

Tasks that need an AI provider use `resolveSystemModel()`:

```php
$system_defaults = $this->resolveSystemModel($params);
$provider = $system_defaults['provider'];
$model    = $system_defaults['model'];
```

This resolves the effective model from agent-specific configuration, falling back to the global system context defaults.

### Editable Prompts

**Since:** v0.41.0

Tasks with AI prompts can expose editable prompt templates via `getPromptDefinitions()`. Each prompt has a key, label, description, default template, and named variables for interpolation:

```php
public function getPromptDefinitions(): array {
    return [
        'generate' => [
            'label'       => 'Generation Prompt',
            'description' => 'Prompt used for generation',
            'default'     => 'Generate a {{type}} for: {{context}}',
            'variables'   => [
                'type'    => 'What to generate',
                'context' => 'Input context',
            ],
        ],
    ];
}
```

**Resolution chain:**
1. Check `datamachine_task_prompts` option for a per-task override
2. Fall back to the default template from `getPromptDefinitions()`

**Convenience methods:**

| Method | Description |
|--------|-------------|
| `resolvePrompt(string $key)` | Get the effective prompt template (override or default) |
| `interpolatePrompt(string $template, array $variables)` | Replace `{{variable}}` placeholders |
| `buildPromptFromTemplate(string $key, array $variables)` | Resolve + interpolate in one call |

**Override management:**

```php
// Set an override
SystemTask::setPromptOverride('alt_text_generation', 'generate', 'Custom prompt...');

// Clear all overrides for a task (revert to defaults)
SystemTask::resetPromptOverrides('alt_text_generation');

// Get all overrides
SystemTask::getAllPromptOverrides();
```

Overrides are stored in the `datamachine_task_prompts` WordPress option, keyed by `task_type` then `prompt_key`.

## Undo System

**Since:** v0.33.0

Tasks that modify WordPress content can opt into undo support. The system provides generic handlers for common effect types, so most tasks don't need custom reversal logic.

### Opting In

A task supports undo by:

1. Returning `true` from `supportsUndo()`
2. Recording effects in `engine_data.effects[]` during execution

### Effect Types

The base class provides undo handlers for four standard effect types:

| Effect Type | What It Undoes | How |
|-------------|----------------|-----|
| `post_content_modified` | Content changes to a post | Restores from WP revision (`revision_id` in effect) |
| `post_meta_set` | Post meta updates | Restores previous value or deletes the meta key |
| `attachment_created` | Media library additions | Deletes the attachment (force delete) |
| `featured_image_set` | Featured image assignments | Restores previous thumbnail or removes it |

Unknown effect types are **skipped** (not failed), so tasks with mixed reversible/irreversible effects degrade gracefully.

### Effect Recording

During execution, tasks record effects as an array in `engine_data`:

```php
$effects[] = [
    'type'           => 'post_content_modified',
    'target'         => ['post_id' => 42],
    'revision_id'    => 123,
    'previous_value' => null,  // optional, for meta/featured image
];
```

### Undo Execution

Effects are reversed in **reverse order** (last effect undone first). The undo method returns a structured result:

```php
$result = $task->undo($jobId, $engineData);
// {
//     'success'  => true,      // false if any effect failed to revert
//     'reverted' => [...],     // successfully reversed effects
//     'skipped'  => [...],     // unknown effect types
//     'failed'   => [...],     // effects that couldn't be reversed
// }
```

### CLI

```bash
# Undo a job's effects
wp datamachine jobs undo <job_id> [--task-type=<type>] [--dry-run] [--force]
```

## SystemAgentServiceProvider

**Source:** `inc/Engine/AI/System/SystemAgentServiceProvider.php`
**Since:** v0.22.4

Registers all task infrastructure on instantiation:

### Task Registration

Hooks the `datamachine_tasks` filter to register the seven built-in task types:

| Task Key | Class |
|----------|-------|
| `image_generation` | `ImageGenerationTask` |
| `image_optimization` | `ImageOptimizationTask` |
| `alt_text_generation` | `AltTextTask` |
| `github_create_issue` | `GitHubIssueTask` |
| `internal_linking` | `InternalLinkingTask` |
| `daily_memory_generation` | `DailyMemoryTask` |
| `meta_description_generation` | `MetaDescriptionTask` |

### Action Scheduler Hooks

| Hook | Handler | Description |
|------|---------|-------------|
| `datamachine_task_handle` | `handleScheduledTask` | Dispatches a scheduled task job |
| `datamachine_task_process_batch` | `handleBatchChunk` | Processes a batch chunk |
| `datamachine_system_agent_set_featured_image` | `handleDeferredFeaturedImage` | Retries featured image assignment (up to 12 × 15s = 3 minutes) |
| `datamachine_recurring_<task_type>` | closure → `TaskScheduler::schedule()` | One hook per registered recurring schedule. Fires on the cadence defined by the schedule and enqueues an ephemeral DM job for the bound task. |

### Recurring Task Schedule Management

Schedules are registered separately from task handlers via the
`datamachine_recurring_schedules` filter (see
[recurring-scheduler.md](recurring-scheduler.md)). On `action_scheduler_init`
the service provider reconciles every registered schedule with Action
Scheduler:

- If the schedule's `enabled_setting` resolves to true and no AS action is
  pending → schedule via `RecurringScheduler::ensureSchedule()`.
- If the setting resolves to false → unschedule.

The legacy `datamachine_system_agent_daily_memory` hook is kept on the
handler map for one release so in-flight AS actions from before this
refactor still fire correctly; it is unscheduled on reconciliation.

## SystemTaskStep

**Source:** `inc/Core/Steps/SystemTask/SystemTaskStep.php`

A pipeline step type that bridges system tasks into the pipeline engine. This allows any system task to be used as a step in a pipeline workflow.

**Step Type:** `system_task`
**Position:** 70

### How It Works

1. Reads `handler_config.task` to determine the task type
2. Creates a child DM job for independent tracking
3. Injects pipeline context (e.g., `post_id` from a preceding Publish step) into task params
4. Executes the task handler synchronously
5. Reads the child job result and returns it as a `DataPacket`

### Configuration

Configured via the pipeline step UI with two fields:

- **Task** (select dropdown) — chooses from registered task types via `TaskRegistry`
- **Params** (JSON editor) — task-specific parameters, defaults to `{}`

### Settings

**Source:** `inc/Core/Steps/SystemTask/SystemTaskSettings.php`

Defines the admin UI fields. The task dropdown is populated from `TaskRegistry::getHandlers()`, using each task's `getTaskMeta()['label']` for display.

## Built-In Tasks

### DailyMemoryTask

**Type:** `daily_memory_generation`
**Source:** `inc/Engine/AI/System/Tasks/DailyMemoryTask.php`
**Undo:** No

AI-generated daily summary of agent activity plus automatic MEMORY.md cleanup. Two-phase execution: Phase 1 synthesizes the day's jobs and chat sessions into a daily entry; Phase 2 uses AI to split MEMORY.md into persistent knowledge and session-specific content, archiving the latter to the daily file.

See [Daily Memory System](daily-memory-system.md) for complete documentation.

| Property | Value |
|----------|-------|
| Setting | `daily_memory_enabled` |
| Trigger | Daily at midnight UTC (cron) |
| Manual run | Yes |
| Prompts | `daily_summary`, `memory_cleanup` |

### InternalLinkingTask

**Type:** `internal_linking`
**Source:** `inc/Engine/AI/System/Tasks/InternalLinkingTask.php`
**Undo:** Yes (`post_content_modified` via WP revision, `post_meta_set`)

Semantically weaves internal links into post content. Finds related posts via shared taxonomy terms (categories and tags) with title similarity scoring. For each related post, identifies a candidate paragraph block and uses AI to insert an anchor tag naturally into the text via block-level editing (`ReplacePostBlocksAbility`).

| Property | Value |
|----------|-------|
| Setting | `internal_linking_auto_enabled` |
| Trigger | Manual (via CLI or ability) |
| Manual run | No |
| Prompts | `generate` |
| Default links per post | 3 |

**Process:**
1. Get post categories and tags
2. Parse post into `core/paragraph` blocks
3. Find related posts scored by taxonomy overlap + title similarity
4. Filter out posts already linked in the content
5. For each related post, find a candidate paragraph and send to AI with linking instructions
6. Validate the AI inserted a link (URL detection)
7. Apply block replacements and record effects for undo
8. Store link metadata in `_datamachine_internal_links` post meta

### MetaDescriptionTask

**Type:** `meta_description_generation`
**Source:** `inc/Engine/AI/System/Tasks/MetaDescriptionTask.php`
**Undo:** Yes (`post_meta_set` — restores previous excerpt)

Generates AI-powered SEO meta descriptions for WordPress posts. Gathers post title, content excerpt (up to 1500 characters), categories, and tags as context. Normalizes the AI response (strips quotes and markdown formatting, truncates at word boundary to 155 characters) and saves to `post_excerpt`.

| Property | Value |
|----------|-------|
| Setting | `meta_description_auto_generate_enabled` |
| Trigger | Manual |
| Manual run | No |
| Prompts | `generate` |
| Max length | 155 characters |

### AltTextTask

**Type:** `alt_text_generation`
**Source:** `inc/Engine/AI/System/Tasks/AltTextTask.php`
**Undo:** Yes (`post_meta_set` — restores previous alt text)

Generates AI-powered alt text for WordPress image attachments using a vision model. Sends the actual image file (with MIME type) plus contextual information (attachment title, caption, description, parent post title) to the AI. Normalizes the response (capitalizes first character, ensures trailing period) and saves to the `_wp_attachment_image_alt` post meta.

| Property | Value |
|----------|-------|
| Setting | `alt_text_auto_generate_enabled` |
| Trigger | Auto on image upload (event) |
| Manual run | Yes |
| Prompts | `generate` |

### ImageGenerationTask

**Type:** `image_generation`
**Source:** `inc/Engine/AI/System/Tasks/ImageGenerationTask.php`
**Undo:** Yes (`attachment_created`, `featured_image_set`, `post_content_modified`)

Handles async image generation through the Replicate API. Polls a prediction ID for status (`starting` → `processing` → `succeeded`/`failed`/`canceled`), rescheduling itself every 5 seconds up to 24 attempts (~120 seconds). On success, downloads the image, converts to JPEG (quality 85), and sideloads into the WordPress media library. Supports two modes:

- **`featured`** — sets as the post's featured image. If the pipeline hasn't published the post yet, schedules a deferred retry (up to 12 × 15s = 3 minutes).
- **`insert`** — inserts a `core/image` block into the post content, using smart auto-placement to find the largest gap between existing image blocks.

| Property | Value |
|----------|-------|
| Setting | None (always available) |
| Trigger | AI tool call |
| Manual run | No |
| Prompts | None |
| Max attempts | 24 |
| JPEG quality | 85 |

### ImageOptimizationTask

**Type:** `image_optimization`
**Source:** `inc/Engine/AI/System/Tasks/ImageOptimizationTask.php`
**Undo:** Yes (`attachment_file_modified`, `file_created`)

Compresses oversized images and generates WebP variants using WordPress's native image editor (Imagick or GD). No external API dependencies. Follows a diagnose-then-fix pattern: `ImageOptimizationAbilities::diagnoseImages()` identifies issues, and this task fixes individual images. Compresses JPEG/PNG/WebP in-place at a configurable quality level, then optionally generates a `.webp` sibling file.

| Property | Value |
|----------|-------|
| Setting | None (default disabled) |
| Trigger | On-demand via CLI or ability |
| Manual run | No |
| Prompts | None (not an AI task) |

### GitHubIssueTask

**Type:** `github_create_issue`
**Source:** `inc/Engine/AI/System/Tasks/GitHubIssueTask.php`
**Undo:** No

The simplest task — creates GitHub issues by delegating entirely to `GitHubAbilities::createIssue()`. Validates the result and completes or fails the job. No prompt building, no normalization.

| Property | Value |
|----------|-------|
| Setting | None (always available) |
| Trigger | AI tool call |
| Manual run | No |
| Prompts | None |

## Task Registration

Third-party tasks can register via the `datamachine_tasks` filter:

```php
add_filter('datamachine_tasks', function(array $tasks): array {
    $tasks['my_custom_task'] = MyCustomTask::class;
    return $tasks;
});
```

The class must extend `SystemTask` and implement `execute()` and `getTaskType()`. Override `getTaskMeta()` for admin UI display, `supportsUndo()` for reversibility, and `getPromptDefinitions()` for editable prompts.

## Architecture Diagram

```
                    CLI                    Pipeline Engine            AI Tool Call
                     |                          |                         |
                     v                          v                         v
              TaskScheduler              SystemTaskStep              TaskScheduler
                     |                          |                         |
                     v                          v                         v
              TaskRegistry -----> resolve task class <----- TaskRegistry
                                        |
                                        v
                                   SystemTask
                                   .execute()
                                        |
                    +-------------------+-------------------+
                    |                   |                   |
                    v                   v                   v
             completeJob()        failJob()          reschedule()
                    |                   |                   |
                    v                   v                   v
              Jobs table          Jobs table        Action Scheduler
            (engine_data)       (failed status)     (retry in N sec)
```

## Source Files

| File | Purpose |
|------|---------|
| `inc/Engine/AI/System/Tasks/SystemTask.php` | Abstract base class with job lifecycle, undo, and prompt management |
| `inc/Engine/AI/System/SystemAgentServiceProvider.php` | Task registration, Action Scheduler hooks, schedule management |
| `inc/Engine/AI/System/Tasks/DailyMemoryTask.php` | Daily memory synthesis and MEMORY.md cleanup |
| `inc/Engine/AI/System/Tasks/InternalLinkingTask.php` | AI-powered internal link insertion |
| `inc/Engine/AI/System/Tasks/MetaDescriptionTask.php` | AI-powered SEO meta description generation |
| `inc/Engine/AI/System/Tasks/AltTextTask.php` | AI-powered image alt text generation |
| `inc/Engine/AI/System/Tasks/ImageGenerationTask.php` | Async image generation via Replicate API |
| `inc/Engine/AI/System/Tasks/ImageOptimizationTask.php` | Image compression and WebP generation |
| `inc/Engine/AI/System/Tasks/GitHubIssueTask.php` | GitHub issue creation |
| `inc/Core/Steps/SystemTask/SystemTaskStep.php` | Pipeline step type bridging system tasks |
| `inc/Core/Steps/SystemTask/SystemTaskSettings.php` | Admin UI settings for the SystemTask step |
