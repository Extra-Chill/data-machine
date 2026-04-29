# AI Message Envelope

**File**: `/inc/Engine/AI/MessageEnvelope.php`

Data Machine's canonical internal AI message shape is a JSON-friendly typed
envelope. Runtime code, chat storage, and transcript storage should store and
return envelopes. Provider-specific `role/content/metadata` arrays are a
projection used at the current `ai-http-client` boundary, not the internal
contract.

## Envelope Shape

```php
[
    'schema'   => 'datamachine.ai.message',
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

Use `payload` for type-specific fields that Data Machine understands, such as
tool names, tool parameters, turn numbers, and tool result data. Use `metadata`
for extension/provider details that should be preserved but are not part of the
type contract.

## Compatibility

`MessageEnvelope::normalize()` accepts existing `role/content/metadata` rows and
versioned envelopes. It also accepts the short-lived `data` envelope key from the
initial envelope draft as a read-time compatibility input and rewrites it to
`payload`.

New writes should store canonical envelopes. Current provider requests use
`MessageEnvelope::to_provider_message()` / `to_provider_messages()` to project
envelopes into the `role/content/metadata` shape expected by `ai-http-client`.
That projection folds the envelope `type` and `payload` into provider metadata.

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
    'schema'  => 'datamachine.ai.message',
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

For new adapters, prefer putting type-specific fields in `payload` and
adapter-specific details in `metadata`. Data Machine projects `payload` back into
provider metadata only when calling a provider boundary that still expects that
shape.

## Adapter Guidance

Runtime adapters using `datamachine_conversation_runner` may return messages in
either legacy or envelope shape. `AgentConversationResult::normalize()` normalizes
every returned message to the canonical envelope before callers store or render
the result.

Conversation store adapters should normalize host messages to this envelope at
their boundary and return envelopes to Data Machine's chat abilities and UI.
Adapters that wrap a host-specific DTO should keep that DTO at the host boundary,
not inside Data Machine storage.
