# Step Types Abilities

**Implementation**: `inc/Abilities/StepTypeAbilities.php`

The `datamachine/v1/step-types` REST routes were retired in #3456. Step types are discovered through the REST-visible Data Machine abilities, executed through WordPress core's ability runner:

```
POST /wp-json/wp-abilities/v1/abilities/datamachine/<slug>/run
Content-Type: application/json

{ "input": { ... } }
```

The admin Pipeline Builder calls these through the shared `executeAbility()` client (`inc/Core/Admin/shared/utils/api.js`).

## Authentication

Each ability's permission callback enforces the Data Machine management surface (`PermissionHelper::can_manage()`). The retired public wrapper routes required no permission; step-type discovery is now admin-scoped. The core ability runner also requires `show_in_rest` and a valid REST nonce for cookie-authenticated callers.

## Response Envelope

The ability runner returns the ability's output directly. Errors return standard REST error objects (`code`, `message`, `data.status`).

## Step Type Abilities

| Ability slug | Purpose |
| --- | --- |
| `datamachine/get-step-types` | All registered step types keyed by slug (labels, descriptions, positions, handler requirements), or one via `step_type_slug`. |
| `datamachine/validate-step-type` | Validate a `step_type` slug exists. |
