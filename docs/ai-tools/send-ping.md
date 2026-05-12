# Send Ping

`send_ping` sends a webhook ping to one or more URLs.

| Field | Value |
| --- | --- |
| Modes | chat |
| Mutation risk | Low mutation |
| Registered in | `ToolServiceProvider.php` via `SendPing` |
| Backing ability | `datamachine/agent-call` |

## Inputs

- `url`: one URL or newline-separated URLs.
- `prompt`: optional instructions for the receiving agent.
- `flow_id`: optional flow context.
- `pipeline_id`: optional pipeline context.

Use for external agent orchestration and webhook notifications.
