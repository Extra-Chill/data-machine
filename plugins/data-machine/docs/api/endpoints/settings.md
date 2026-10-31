# Settings Abilities

**Implementation**: `inc/Abilities/SettingsAbilities.php`

The `datamachine/v1/settings` REST routes were retired in #3456. Settings are read and updated through the REST-visible Data Machine abilities, executed through WordPress core's ability runner:

```
POST /wp-json/wp-abilities/v1/abilities/datamachine/<slug>/run
Content-Type: application/json

{ "input": { ... } }
```

The admin Settings page calls these through the shared `executeAbility()` client (`inc/Core/Admin/shared/utils/api.js`).

## Authentication

Each ability's permission callback enforces the Data Machine `manage_settings` capability surface (`PermissionHelper::can_manage()`). The core ability runner also requires `show_in_rest` and a valid REST nonce for cookie-authenticated callers.

## Response Envelope

The ability runner returns the ability's output directly. Errors return standard REST error objects (`code`, `message`, `data.status`).

## Settings Abilities

| Ability slug | Purpose |
| --- | --- |
| `datamachine/get-settings` | All plugin settings, defaults, network settings, and the global tool registry status. |
| `datamachine/update-settings` | Partial settings update; unknown keys are ignored. |
| `datamachine/get-scheduling-intervals` | Interval options for scheduling UIs (`value`/`label` list). |
| `datamachine/get-tool-config` | Configuration schema and current values for one tool (`tool_id`). |
| `datamachine/save-tool-config` | Save one tool's `config_data`. |
| `datamachine/get-handler-defaults` | Handler defaults grouped by step type, with config field schemas. |
| `datamachine/update-handler-defaults` | Replace defaults for one handler (`handler_slug`, `defaults`). |
| `datamachine/generate-ping-secret` | Generate and store a new agent ping secret; the secret is returned once. |
