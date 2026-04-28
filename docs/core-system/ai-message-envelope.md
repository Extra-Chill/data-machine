# AI Message Envelope

**File**: `/inc/Engine/AI/MessageEnvelope.php`

Data Machine stores conversation messages as JSON-friendly arrays. The current
persisted shape stays stable:

```php
[
    'role'     => 'user|assistant|system|tool',
    'content'  => 'Plain text or multimodal content blocks',
    'metadata' => [ 'type' => 'text' ],
]
```

The message envelope is the typed runtime contract that adapters should target.
It makes tool calls, tool results, input-required states, final results, errors,
deltas, and multimodal parts explicit without adopting any host-specific DTO.

## Envelope Shape

```php
[
    'schema'   => 'datamachine.ai.message',
    'version'  => 1,
    'type'     => 'text',
    'role'     => 'assistant',
    'content'  => 'Message text or provider-neutral content blocks',
    'data'     => [],      // Type-specific JSON-serializable payload.
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

## Compatibility

`MessageEnvelope::normalize()` accepts either the legacy
`role/content/metadata` shape or the versioned envelope shape. The canonical
storage path still writes legacy messages by calling
`MessageEnvelope::to_legacy_message()` / `to_legacy_messages()`.

This gives runtime adapters a stable typed target while preserving existing
chat rows, pipeline transcripts, REST responses, and CLI transcript rendering.

## Type Data

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
    'data'    => [
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

For new adapters, prefer putting type-specific fields in `data` and
adapter-specific details in `metadata`. Data Machine will fold `data` back into
`metadata` when returning the persisted legacy shape.

## Adapter Guidance

Runtime adapters using `datamachine_conversation_runner` may return messages in
either shape. `AIConversationResult::normalize()` normalizes every message
through `MessageEnvelope` and returns the current persisted shape to callers.

Conversation store adapters should normalize host messages to this envelope at
their boundary, then return `role/content/metadata` arrays to Data Machine's chat
abilities and UI until the storage contract changes explicitly.
