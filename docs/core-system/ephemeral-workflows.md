# Direct Execution (Ephemeral Workflows)

Direct execution (@since v0.8.0) allows you to execute a series of steps without first saving them as a Pipeline or Flow in the database. This is ideal for one-off tasks, programmatic triggers, or AI-generated workflows.

## How It Works

When a direct execution workflow is triggered via the `/execute` REST endpoint, the system:

1.  **Validates the Payload**: Ensures the `workflow` object contains a valid `steps` array with supported step types and handlers.
2.  **Dynamic Config Generation**: The `Execute::build_configs_from_workflow()` method transforms the JSON request into internal `flow_config` and `pipeline_config` formats.
3.  **Direct Execution Mode**: Sets `flow_id = 'direct'` and `pipeline_id = 'direct'` within these configurations. The string `'direct'` explicitly indicates direct execution mode, bypassing normal flow/pipeline lookup.
4.  **Job Initialization**: Creates a standard Job record. Even though the workflow is ephemeral, the **Job** and its **Logs** are still persisted for monitoring and debugging.
5.  **Snapshotting**: The dynamically generated configurations are stored in the Job's `engine_data` snapshot. This ensures the workflow definition remains consistent even if it doesn't exist in the `wp_datamachine_flows` table.
6.  **Standard Execution**: The job enters the standard execution cycle: `datamachine_run_flow_now` → `datamachine_execute_step` → `datamachine_schedule_next_step`.

## Use Cases

- **AI Chatbot Execution**: When the Data Machine chat agent suggests a sequence of actions, it can trigger them immediately as a direct execution workflow.
- **External Triggers**: Programmatically trigger a specific sequence of steps from an external script without cluttering the WordPress database with temporary pipelines.
- **Testing**: Quickly test a new combination of handlers and prompts without going through the Pipeline Builder UI.
- **CLI Tools**: Use the `wp datamachine chat` WP-CLI command to run the chat agent directly for debugging and validation.

## Limitations

- **No Persistence**: You cannot "edit" a direct execution workflow once it has started.
- **No Automatic Retries**: If a direct execution workflow fails, it must be re-submitted via the API.
- **Direct Mode**: Because `flow_id` is `'direct'`, certain features that rely on flow-specific settings (like flow-level overrides) must be included directly in the `handler_config` within the request.

## Example Payload

See the [Execute Endpoint Documentation](../api/endpoints/execute.md) for detailed payload examples.
