# AI Conversation Loop

**File**: `/inc/Engine/AI/conversation-loop.php`
**Since**: 0.2.0

Data Machine runs multi-turn agent work through the Agents API conversation substrate. The canonical Data Machine entry point is `DataMachine\Engine\AI\datamachine_run_conversation()`; the former `AIConversationLoop` class has been removed.

## Current Ownership

```
Data Machine caller
  |
  v
datamachine_run_conversation()
  |  owns Data Machine runtime policy and builds the turn runner
  v
AgentsAPI\AI\WP_Agent_Conversation_Loop::run()
  |  owns generic turn sequencing, budgets, transcripts, locks, events
  v
Data Machine turn runner closure
  |  owns request assembly, wp-ai-client dispatch, tool execution
  v
RequestBuilder::build() -> wp_ai_client_prompt()
```

`WP_Agent_Conversation_Loop` is the generic runtime loop. It should not know about Data Machine jobs, flow steps, handlers, tool policies, or completion assertions. Data Machine adapts those product concepts into the generic loop with options and a turn runner.

## Canonical Entry Point

```php
use function DataMachine\Engine\AI\datamachine_run_conversation;

$result = datamachine_run_conversation(
    $messages,        // Initial messages; normalized to Agents API envelopes.
    $tools,           // Data Machine tool definitions keyed by tool name.
    $provider,        // wp-ai-client provider identifier.
    $model,           // wp-ai-client model identifier.
    $mode,            // 'pipeline', 'chat', 'system', or extension mode.
    $payload,         // Data Machine runtime payload.
    $max_turns,       // Turn ceiling; resolved through IterationBudgetRegistry.
    $single_turn      // Stop after exactly one provider turn.
);
```

Current callers, including `AIStep` and `ChatOrchestrator`, call this function directly. New code should not introduce `AIConversationLoop::run()` examples; references to that class are historical only.

## What Data Machine Owns

`datamachine_run_conversation()` owns the Data Machine adapter layer around the generic loop:

- Message normalization through `AgentsAPI\AI\WP_Agent_Message::normalize_many()`.
- Runtime object resolution from `$payload`, including event sinks, transcript persisters, transcript locks, completion assertions, and tool runtime rules.
- Runtime object stripping before passing payload data into tools and requests.
- Turn budget construction through `IterationBudgetRegistry::create( 'conversation_turns', 0, $max_turns )`.
- Base log context for `mode`, `job_id`, `flow_step_id`, and `agent_slug`.
- Completion assertion preflight for required tools that are unavailable to the model.
- Data Machine turn runner creation through `datamachine_build_turn_runner()`.
- Mapping the Agents API `budget_exceeded` status to Data Machine's `metadata.datamachine.max_turns_reached` UI diagnostic.
- Adding Data Machine-only fields such as `completed`, `last_tool_calls`, completion nudge diagnostics, and completion assertion diagnostics under `metadata.datamachine`.

## What Agents API Owns

`AgentsAPI\AI\WP_Agent_Conversation_Loop::run()` owns generic runtime mechanics:

- Turn sequencing and turn count.
- Budget enforcement through the `budgets` option.
- Final result normalization fields such as `messages`, `final_content`, `turn_count`, `status`, `usage`, and `request_metadata`.
- Transcript persistence via `transcript_persister`.
- Transcript locking via `transcript_lock`, `transcript_session_id`, and `transcript_lock_ttl`.
- Runtime events through the `on_event` callback.
- Generic continuation decisions through the `should_continue` callback.

Data Machine passes a `WP_Agent_Conversation_Request` into the loop options. Its metadata includes the selected provider, model, and `WordPressWorkspaceScope::metadata()` so hosts can inspect the WordPress runtime associated with the run.

## Turn Runner Responsibilities

The Data Machine turn runner handles one provider turn:

1. Build and dispatch a provider request with `RequestBuilder::build()`.
2. Emit a `request_built` event with turn count, provider, model, success status, and request metadata.
3. Convert `WP_Error` request failures into a structured runtime error by throwing `RuntimeException`; `datamachine_run_conversation()` catches it and returns an error result.
4. Extract tool calls from the `GenerativeAiResult`.
5. Extract text content with `RequestBuilder::resultText()`.
6. Accumulate token usage for the substrate to total across turns.
7. Append assistant text messages with `ConversationManager::buildConversationMessage()`.
8. Validate duplicate tool calls with `ConversationManager::validateToolCall()`.
9. Enforce Data Machine tool runtime rules before execution.
10. Execute tools through `ToolExecutor::executeTool()` with mode, agent, and client context.
11. Record Data Machine completion policy progress after each tool result.
12. Append tool call and tool result envelope messages through `ConversationManager`.
13. Add completion nudges when assertions are still missing and another turn is useful.

The turn runner returns per-turn `messages`, `tool_execution_results`, `request_metadata`, `usage`, `conversation_complete`, and continuation hints. The Agents API loop merges those into the final result.

## Continuation Rules

Data Machine's `should_continue` callback is intentionally small because Agents API owns the loop mechanics:

- Stop immediately when `$single_turn` is true.
- Stop when the turn runner marks `conversation_complete`.
- Continue when the turn executed tools, appended a completion nudge, rejected a duplicate call, or rejected a tool runtime rule.

Natural completion is still a Data Machine policy decision. If there are no tool calls, the turn runner asks the resolved completion policy whether natural completion is acceptable. Completion assertions can therefore keep the loop running even after a text-only model response.

## Completion Assertions

Pipeline and chat payloads may include `completion_assertions`. Data Machine resolves them before entering the substrate loop.

Simple required-tool assertions require named tools to run successfully:

```json
{
  "completion_assertions": {
    "required_tool_names": ["create_or_update_github_file", "create_github_pull_request"]
  }
}
```

Assertions can also require engine data keys, minimum successful tool counts, output fields, parameter matches, or any one of several named outcomes:

```json
{
  "completion_assertions": {
    "complete_when_any": [
      {
        "name": "content_proposal",
        "tools": [
          { "name": "create_or_update_github_file", "min_successful_calls": 2 },
          { "name": "create_github_pull_request", "required_output": ["html_url"] }
        ]
      },
      {
        "name": "issue_reply",
        "tools": [
          {
            "name": "manage_github_issue",
            "required_parameters": { "action": "comment" },
            "required_output": ["comment.html_url"]
          }
        ]
      }
    ]
  }
}
```

If a required tool is not available in the current tool set, `datamachine_run_conversation()` returns an error before any provider call:

```php
[
    'error_code'                      => 'completion_required_tool_unavailable',
    'status'                          => 'error',
    'metadata'                        => [
        'datamachine' => [
            'completed'                       => false,
            'completion_assertions_required'  => $assertions->required(),
            'unavailable_required_tool_names' => $unavailable_required_tools,
            'available_tool_names'            => array_keys( $tools ),
        ],
    ],
]
```

When assertions are missing after a natural completion or partial progress, Data Machine appends a nudge as a user message and keeps the loop running when useful. Final results may include these fields under `metadata.datamachine`:

- `completion_nudge_count`
- `completion_nudge`
- `completion_assertions_required`
- `completion_assertions_missing`
- `completion_assertions_satisfied`
- `completion_assertions_complete`

Job engine data and loop events receive the same diagnostics for evidence and artifact reporting.

## Result Shape

The returned array keeps the Agents API conversation result at the top level. Data Machine runtime diagnostics live under `metadata.datamachine` so callers can distinguish substrate fields from Data Machine UI/provenance hints.

Common top-level fields:

```php
[
    'messages'               => [], // canonical Agents API envelopes
    'final_content'          => '',
    'turn_count'             => 1,
    'status'                 => 'completed',
    'tool_execution_results' => [],
    'usage'                  => [],
    'request_metadata'       => [],
    'metadata'               => [
        'datamachine' => [
            'completed'       => true,
            'last_tool_calls' => [],
            'tool_calls'      => [],
        ],
    ],
]
```

Error results use the same array style and include top-level `error`; budget exhaustion keeps top-level `status => budget_exceeded` and places `max_turns_reached` plus the UI warning under `metadata.datamachine`.

## Runtime Gates And Transport

Provider availability is checked in `RequestBuilder::build()`, not in the conversation loop. The turn runner treats a `WP_Error` from RequestBuilder as a failed turn and returns a structured error result.

Transport and provider behavior are documented in [RequestBuilder Pattern](./request-builder.md). The conversation loop stores the per-turn `request_metadata` returned by RequestBuilder so callers and tests can inspect directives, request sizes, provider/model, and transport profile.

## Test Hooks

Runtime tests can control or observe execution through stable hooks and payload collaborators:

- `datamachine_wp_ai_client_text_result` short-circuits provider dispatch and may return `WP_Error`, `GenerativeAiResult`, or compact array data for test doubles.
- `datamachine_wp_ai_client_availability` overrides wp-ai-client availability checks.
- `datamachine_wp_ai_client_request_timeout` and `datamachine_wp_ai_client_connect_timeout` control timeout profiles.
- `event_sink` in the payload receives loop events through `LoopEventSinkInterface`.
- `transcript_persister`, `transcript_lock`, `transcript_session_id`, and `transcript_lock_ttl` exercise transcript persistence and locking.

Representative smoke tests:

- `tests/agent-conversation-runner-request-smoke.php` verifies that `datamachine_run_conversation()` delegates through the Agents API substrate and returns normalized content, usage, tool results, and events.
- `tests/agent-conversation-runtime-policy-smoke.php` covers completion assertions, natural completion, nudges, duplicate-tool recovery, runtime rules, and completion diagnostics.
- `tests/ai-message-envelope-smoke.php` verifies message envelope normalization and result normalization.

## Historical Context

Older docs and changelog entries may mention `AIConversationLoop::run()` or `AIConversationLoop::execute()`. Those references describe the pre-substrate compatibility class. Current runtime docs and examples should use `datamachine_run_conversation()` and `AgentsAPI\AI\WP_Agent_Conversation_Loop::run()`.
