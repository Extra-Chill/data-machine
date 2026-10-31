# Set Handler Defaults

`set_handler_defaults` updates site-wide default configuration for a handler.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Config mutation |
| Registered in | `ToolServiceProvider.php` via `SetHandlerDefaults` |
| Backing ability | `datamachine/update-handler-defaults` |

## Inputs

- `handler_slug`: handler to update.
- `defaults`: key/value handler configuration defaults.

Use to standardize configuration for future flow setup.
