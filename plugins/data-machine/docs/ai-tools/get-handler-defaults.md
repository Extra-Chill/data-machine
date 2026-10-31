# Get Handler Defaults

`get_handler_defaults` reads site-wide handler defaults.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Read-only |
| Registered in | `ToolServiceProvider.php` via `GetHandlerDefaults` |
| Backing abilities | `datamachine/get-handler-site-defaults`, `datamachine/get-handlers`, `datamachine/get-handler-config-fields` |

## Inputs

- `handler_slug`: optional handler slug. Omit to read defaults for all handlers.

Use before configuring flows to follow established site defaults.
