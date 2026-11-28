# Jobs Abilities

**Implementation**: `inc/Abilities/Job/GetJobsAbility.php`, `inc/Abilities/Job/DeleteJobsAbility.php`

The `datamachine/v1/jobs` REST routes were retired in #3456. Jobs are managed through the REST-visible Data Machine abilities, executed through WordPress core's ability runner:

```
POST /wp-json/wp-abilities/v1/abilities/datamachine/<slug>/run
Content-Type: application/json

{ "input": { ... } }
```

The admin Jobs page calls these through the shared `executeAbility()` client (`inc/Core/Admin/shared/utils/api.js`).

## Authentication

Each ability's permission callback enforces Data Machine permissions (`PermissionHelper::can_manage()` for the jobs family). Row-level ownership is enforced inside the abilities (see `DirectJobOwnershipTest`). The core ability runner also requires `show_in_rest` and a valid REST nonce for cookie-authenticated callers.

## Response Envelope

The ability runner returns the ability's output directly. Errors return standard REST error objects (`code`, `message`, `data.status`).

## Job Abilities

| Ability slug | Purpose |
| --- | --- |
| `datamachine/get-jobs` | List jobs with filtering (`pipeline_id`, `flow_id`, `status`, `source`, `handler`, `parent_job_id`, `hide_children`, `user_id`, `agent_id`, `metadata`), sorting (`orderby`, `order`), and pagination (`per_page`, `offset`). A `job_id` input fetches a single job (empty result when missing). |
| `datamachine/delete-jobs` | Delete jobs by `type` (`all` or `failed`), optionally `cleanup_processed` to also clear processed-items tracking. |
| `datamachine/clear-processed-items` | Clear processed-items deduplication tracking by `clear_type` (`pipeline` or `flow`) and `target_id`. |
