# Manage Jobs

`manage_jobs` manages Data Machine jobs.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Destructive |
| Registered in | `ToolServiceProvider.php` via `ManageJobs` |
| Backing abilities | `datamachine/get-jobs`, `datamachine/get-jobs-summary`, `datamachine/delete-jobs`, `datamachine/fail-job`, `datamachine/retry-job`, `datamachine/recover-stuck-jobs` |

## Actions

- `list`: list jobs with status, flow, and pipeline filters.
- `summary`: count jobs by status.
- `delete`: delete all or failed jobs.
- `fail`: manually fail a job.
- `retry`: retry a failed job.
- `recover`: recover stuck processing jobs.

Use read actions first before destructive job operations.
