# Logs & Jobs Management Interface

Monitoring and managing pipeline execution status and historical system logs.

## Logs Interface

The Logs interface is a separate React admin page implemented under `inc/Core/Admin/Pages/Logs/assets/react/`.

**Architecture (as implemented):**
- Uses TanStack Query hooks in `inc/Core/Admin/Pages/Logs/assets/react/queries/logs.js`.
- Calls REST endpoints via `inc/Core/Admin/Pages/Logs/assets/react/api/logs.js` which uses the shared REST client (`@shared/utils/api`).

**REST endpoints used by the UI:**
- `GET /logs/agent-types` (available agent types)
- `GET /logs?agent_type=...` (metadata)
- `GET /logs/content?agent_type=...&mode=recent|full&limit=...` (log content)
- `DELETE /logs?agent_type=...` (clear logs; supports `agent_type=all`)
- `PUT /logs/level` (set log level per agent type)

Document UI-specific filtering controls and deep links only if they are present in the current React components.

## Jobs Interface

The Jobs interface is a React-based admin dashboard that lists recent job executions from the Data Machine engine.

**Architecture:**
- **TanStack Query** for server state (`useJobs` in `inc/Core/Admin/Pages/Jobs/assets/react/queries/jobs.js`).
- **Local React state** for UI-only state like pagination and admin modal open/close (`inc/Core/Admin/Pages/Jobs/assets/react/JobsApp.jsx`).
- Settings (such as `jobs_per_page`) are read via the shared settings query (`useSettings`).

**Features (implemented):**
- **Job listing** table with server-driven pagination.
- **Administrative controls** via `JobsAdminModal.jsx` (clear jobs, clear processed items).

## Job Listing

**Comprehensive Table**: The primary view displays recent jobs with:
- **Job ID**: Unique identification and tracking.
- **Pipeline & Flow**: Combined context showing the template and specific instance.
- **Status**: Reflects the canonical state (for example `processing`, `completed`, `failed`, `completed_no_items`, `agent_skipped`) and renders structured reason detail separately.
- **Timestamps**: Human-readable creation and completion times.

## Administrative Controls

**Jobs Admin Modal**: A React-based modal interface providing:
- **Clear Processed Items**: Reset deduplication tracking for specific or all flows.
- **Bulk Job Deletion**: Remove all jobs or only failed jobs from the database.
- **System Maintenance**: Database optimization and cleanup tools.

## Job Status Management

The indexed `datamachine_jobs.status` column stores only Data Machine's bounded base-state vocabulary. Human-readable detail is stored as `engine_data.job_status_reason` and composed into `status_display` by job abilities, so list/detail output stays scannable without increasing status-index cardinality.

### Legacy Status Migration

Existing compound rows are normalized only through the operator command; plugin bootstrap never scans or rewrites the jobs table.

1. Inspect without mutation: `wp datamachine jobs normalize-status --format=json`.
2. Apply one bounded batch: `wp datamachine jobs normalize-status --apply --limit=250`.
3. Repeat apply until `status` is `complete` and `remaining` is `0`.
4. Verify queue summaries and `SELECT status, COUNT(*) ... GROUP BY status` before considering the migration complete operationally.

Progress is durable and per-site, so interrupted runs resume from the last job ID. Each row is locked and writes its reason metadata and base status in one transaction. Unknown noncanonical states are counted but not rewritten; inspect and resolve their owning code before continuing. During rollout, Data Machine readers match exact base states plus bounded legacy delimiters. Once completion is recorded, readers use exact status predicates and direct grouping. Rollback is code rollback only: the normalized base states remain valid to older code, while reason detail remains available in `engine_data`.

**State Synchronization**: Leveraging TanStack Query for:
- Automatic background refetching of job statuses.
- Real-time progression tracking through pipeline steps.
- Error reporting and failure detail visibility.
- Seamless pagination and per-page control.

## Error Handling Display

**Failure Details**: Comprehensive error information for failed jobs:
- Specific error messages and exception details
- Failed step identification and context
- Stack traces and debugging information (when available)
- Suggested resolution steps and documentation links

**Debug Information**: Development and troubleshooting data:
- Job data payload inspection
- Step-by-step execution log
- Configuration validation results
- API response details and error codes

## Performance Monitoring

The Jobs UI surfaces per-job timestamps and basic duration context when provided by the API. It does not implement trend analytics or charting in the current React code.

## Database Integration

**Jobs Table Access**: Direct integration with `wp_datamachine_jobs` table:
- Efficient querying with pagination support
- Sorting by multiple columns and criteria
- Index utilization for performance optimization
- Relationship joins with pipeline and flow data

**Data Cleanup**: Automated and manual cleanup processes:
- Configurable retention policies for completed jobs
- Failed job data preservation for debugging
- Processed items tracking maintenance
- File repository cleanup for associated job files

## User Experience Features

**Empty State Handling**: User-friendly display when no jobs exist:
- Clear messaging about job creation and execution
- Links to pipeline configuration and setup
- Getting started guidance and documentation

**Responsive Design**: Interface adaptation for different screen sizes:
- Mobile-optimized table layouts
- Touch-friendly administrative controls
- Collapsible detail sections for space efficiency

## Integration Points

The Jobs UI is focused on listing and cleanup operations. Any deep-linking to related Pipelines/Flows and any log streaming behavior should be considered implementation-dependent and verified against the current React components before documenting.

## Administrative Features

**Bulk Operations**: Mass job management capabilities:
- Multi-select job operations
- Bulk deletion with confirmation prompts
- Status change operations across multiple jobs
- Export selected jobs for analysis

**System Maintenance**: Database and system health tools:
- Job table optimization and maintenance
- Orphaned record cleanup and validation
- System performance impact analysis
- Capacity monitoring and alerting

**Audit Trail**: Comprehensive activity logging:
- Job creation and modification tracking
- Administrative action logging
- User activity monitoring for job operations
- System event correlation with job execution
