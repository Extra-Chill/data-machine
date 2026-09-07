# Troubleshooting Problem Flows

The Data Machine ecosystem includes automated monitoring to help identify flows that are consistently failing or returning no results. These are categorized as **Problem Flows**.

## What is a Problem Flow?

A flow is flagged as a "Problem Flow" when it exceeds the `problem_flow_threshold` (default is 3) for either:
1. **Consecutive Failures**: The flow has encountered an actual execution error (status `failed`) multiple times in a row.
2. **Consecutive No Items**: The flow has successfully checked for new items but found nothing (status `completed_no_items`) multiple times in a row.

## Monitoring Problem Flows

### Admin Interface
The Jobs dashboard provides visual indicators for flows that are experiencing issues. Look for flows with repeated "Failed" or "No Items" statuses.

### REST API
You can retrieve a list of currently flagged flows through the `datamachine/get-problem-flows` ability run route:
`POST /wp-json/wp-abilities/v1/abilities/datamachine/get-problem-flows/run` with body `{"input": {}}`

### AI Chat Agent
The chat agent can list problem flows via the `get_problem_flows` tool.

## Notes

A problem flow is a signal based on consecutive job statuses; the underlying cause depends on the specific handler/provider configuration and the upstream source.

## Adjusting the threshold

`problem_flow_threshold` is stored in plugin settings and can be updated via the Settings endpoint.

## Resetting Metrics
The `consecutive_failures` and `consecutive_no_items` counters are automatically reset to 0 as soon as the flow completes a successful execution with status `completed` (meaning at least one item was processed).
