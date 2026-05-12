# Agent Message Envelope

**File**: `AgentsAPI\AI\WP_Agent_Message`

The canonical agent message shape is a JSON-friendly typed envelope owned by the Agents API substrate. Runtime code, chat storage, transcript storage, and conversation results should store and return envelopes. Provider-specific `role/content/metadata` arrays are only a projection used at the wp-ai-client request boundary.

## Envelope Shape

```php
[
    'schema'   => 'agents-api.message',
    'version'  => 1,
    'type'     => 'text',
    'role'     => 'assistant',
    'content'  => 'Message text or provider-neutral content blocks',
    'payload'  => [],      // Type-specific JSON-serializable payload.
    'metadata' => [],      // Extension metadata; must stay JSON-serializable.

    // Optional fields preserved when present:
    'id'         => 'stable-message-id',
    'created_at' => '2026-04-28 12:00:00',
    'updated_at' => '2026-04-28 12:00:00',
]
```

Supported `type` values:

- `text`
- `tool_call`
- `tool_result`
- `input_required`
- `approval_required`
- `final_result`
- `error`
- `delta`
- `multimodal_part`

Use `payload` for type-specific fields that the agent runtime understands, such as tool names, tool parameters, turn numbers, and tool result data. Use `metadata` for extension, provider, transport, or UI details that should be preserved but are not part of the type contract.

## Runtime Ownership

`datamachine_run_conversation()` normalizes initial messages with `WP_Agent_Message::normalize_many()` before delegating to `AgentsAPI\AI\WP_Agent_Conversation_Loop::run()`. `ConversationManager` emits tool call, tool result, duplicate-call correction, completion-nudge, and text messages as envelopes. The Agents API result normalizer then guarantees the final `messages` list is canonical before Data Machine callers store or render it.

RequestBuilder projects envelopes into wp-ai-client DTOs only for provider dispatch. That projection is not a storage contract and should not leak back into chat/session persistence.

## Compatibility

`WP_Agent_Message::normalize()` accepts existing `role/content/metadata` rows and versioned envelopes. It also accepts the short-lived `data` envelope key from the initial envelope draft as a read-time compatibility input and rewrites it to `payload`.

New writes should store canonical envelopes. Current provider requests use `WP_Agent_Message::to_provider_message()` / `to_provider_messages()` as needed to project envelopes into Data Machine's provider-message shape. That projection folds the envelope `type` and `payload` into provider metadata, after which `RequestBuilder` maps messages to wp-ai-client prompt/history DTOs.

## Type Payload

Legacy tool-call messages:

```php
[
    'role'     => 'assistant',
    'content'  => 'AI ACTION (Turn 1): Executing Wiki Upsert',
    'metadata' => [
        'type'       => 'tool_call',
        'tool_name'  => 'wiki_upsert',
        'parameters' => [ 'title' => 'Example' ],
        'turn'       => 1,
    ],
]
```

normalize to:

```php
[
    'schema'  => 'agents-api.message',
    'version' => 1,
    'type'    => 'tool_call',
    'role'    => 'assistant',
    'content' => 'AI ACTION (Turn 1): Executing Wiki Upsert',
    'payload' => [
        'tool_name'  => 'wiki_upsert',
        'parameters' => [ 'title' => 'Example' ],
        'turn'       => 1,
    ],
    'metadata' => [
        'type'       => 'tool_call',
        'tool_name'  => 'wiki_upsert',
        'parameters' => [ 'title' => 'Example' ],
        'turn'       => 1,
    ],
]
```

For new adapters, prefer putting type-specific fields in `payload` and adapter-specific details in `metadata`. Data Machine projects `payload` back into provider metadata only when calling a provider boundary that still expects that shape.

## Result Normalization

`AgentsAPI\AI\WP_Agent_Conversation_Result::normalize()` accepts result arrays from the Agents API loop or compatible adapters and normalizes every returned message to an envelope. Data Machine calls this normalizer immediately after `WP_Agent_Conversation_Loop::run()` and only then adds Data Machine-specific diagnostics such as completion assertions and `last_tool_calls`.

Required result fields include `messages`, `final_content`, `turn_count`, `completed`, `tool_execution_results`, and `usage`; optional fields such as `status`, `request_metadata`, `error`, and `warning` are preserved.

## Adapter Guidance

Runtime adapters and tests may return messages in either legacy or envelope shape. `WP_Agent_Conversation_Result::normalize()` normalizes every returned message to the canonical envelope before callers store or render the result.

Conversation store adapters should normalize host messages to this envelope at their boundary and return envelopes to Data Machine's chat abilities and UI. Adapters that wrap a host-specific DTO should keep that DTO at the host boundary, not inside Data Machine storage.

## Historical Context

Older Data Machine code used plain `role/content/metadata` arrays everywhere. That shape remains accepted as a read-time compatibility input and provider projection, but new runtime/storage writes should be canonical Agents API envelopes.
