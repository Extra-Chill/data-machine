# Handlers Abilities

**Implementation**: `inc/Abilities/HandlerAbilities.php`, `inc/Abilities/Handler/HandlerDetailAbility.php`

The `datamachine/v1/handlers` REST routes were retired in #3456. Handlers are discovered through the REST-visible Data Machine abilities, executed through WordPress core's ability runner:

```
POST /wp-json/wp-abilities/v1/abilities/datamachine/<slug>/run
Content-Type: application/json

{ "input": { ... } }
```

The admin Pipeline Builder calls these through the shared `executeAbility()` client (`inc/Core/Admin/shared/utils/api.js`).

## Authentication

Each ability's permission callback enforces the Data Machine management surface (`PermissionHelper::can_manage()`). The retired public wrapper routes required no permission; handler discovery is now admin-scoped. The core ability runner also requires `show_in_rest` and a valid REST nonce for cookie-authenticated callers.

## Response Envelope

The ability runner returns the ability's output directly. Errors return standard REST error objects (`code`, `message`, `data.status`).

## Handler Abilities

| Ability slug | Purpose |
| --- | --- |
| `datamachine/get-handlers` | All handlers (optionally filtered by `step_type`) or one by `handler_slug`. The list is enriched with `auth_type`, `auth_fields`, `callback_url`, `is_authenticated`, and `account_details` for auth handlers; malformed registrations are omitted and base defaults are merged. |
| `datamachine/get-handler-detail` | Complete detail for one handler: `info`, the settings-display `settings` field state with site defaults applied, and the handler's `ai_tool` definition. Falls back to step-type settings for step types that register settings without being handlers. |
| `datamachine/get-handler-config-fields` | Raw config field schema for one handler. |
| `datamachine/apply-handler-defaults` | Merge site defaults into a provided handler config. |
| `datamachine/get-handler-site-defaults` | Site-wide handler defaults (all or one handler). |
| `datamachine/validate-handler` | Validate a `handler_slug` exists (optionally within a `step_type`). |
