# Settings Configuration Interface

React admin page for configuring Data Machine’s global settings, agent defaults, API keys, and handler defaults.

## Architecture

- **Frontend location**: `inc/Core/Admin/Settings/assets/react/`
- **REST endpoints**: `/wp-json/datamachine/v1/settings` (see `inc/Api/Settings.php`)
- **Data fetching**: TanStack Query (`@tanstack/react-query`) with the shared `queryClient`
- **REST client**: `@wordpress/api-fetch` wrapper at `inc/Core/Admin/shared/utils/api.js`
- **Auth**: WordPress REST nonce (`X-WP-Nonce`) and `manage_options` capability

## Tabs

The settings UI is a simple tabbed container (`SettingsApp.jsx`) that persists the active tab in `localStorage` (`datamachine_settings_active_tab`).

- **General**: Non-AI operational settings (cleanup, retention, pagination).
- **General / Queue Performance**: Action Scheduler and batch fan-out throughput (`queue_tuning.concurrent_batches`, `batch_size`, `time_limit`, `chunk_size`, and `chunk_delay`). Defaults stay local-safe, while high ceilings support managed workers and large queues.
- **General / Pipeline AI Concurrency**: Pipeline AI provider backpressure (`pipeline_ai_concurrency_limit`, optional provider limits, and retry delay) separate from Action Scheduler queue throughput.
- **Agent**: Global agent runtime defaults (global system prompt, site context toggle, max turns, enabled global tools).
- **API Keys**: Provider API keys (masked on read; only updated when the user enters a new value).
- **Handler Defaults**: Site-wide handler default settings that seed new flow step configs.

## Settings Data Contract

`GET /settings` returns a payload shaped for the React page:

- `data.settings`: primitive settings values (e.g., `flows_per_page`, `jobs_per_page`, `problem_flow_threshold`).
- `data.global_tools`: global tool catalog keyed by tool id, including `is_configured`, `requires_configuration`, and `is_enabled`.
- `data.settings.ai_provider_keys`: provider keys are masked before returning to the UI.

`PATCH /settings` performs a partial update and merges into the `datamachine_settings` option.

## Caching and Invalidation

- Settings fetches use the shared TanStack Query key `[ 'settings' ]`.
- Mutations invalidate `[ 'settings' ]` after success.

## Notes

- Tool-specific configuration endpoints live under `POST /settings/tools/{tool_id}` and are handled via the `datamachine_save_tool_config` action.
- The Settings page constants (REST namespace and nonce) are localized into `window.dataMachineSettingsConfig` via `inc/Core/Admin/Settings/SettingsFilters.php`.
