# RequestBuilder Pattern

**File**: `/inc/Engine/AI/RequestBuilder.php`
**Since**: 0.2.0

`RequestBuilder` is Data Machine's request assembly and provider dispatch adapter. It prepares Data Machine messages, tools, directives, request metadata, and transport options, then calls WordPress core's `wp-ai-client` directly.

## Boundary

Data Machine has two separate runtime layers:

- **Agents API** owns durable multi-turn runtime semantics: conversation loops, transcripts, locks, event sinks, budgets, sessions, memory contracts, and normalized result/message envelopes.
- **wp-ai-client** owns provider/model prompt execution.
- **RequestBuilder** is the Data Machine adapter from product payloads to `wp-ai-client` provider requests.

Pipeline AI steps and Data Machine chat turns use `RequestBuilder::build()` so directives, metadata, request inspection, tool declarations, and timeout behavior stay consistent. Plugins that only need one-shot AI operations may call `wp-ai-client` directly; they do not need to route through Data Machine or Agents API.

## Flow

```
RequestBuilder::build()
  |
  |-- WpAiClientCache::install()
  |-- assemble()
  |     |-- withDirectiveContext()
  |     |-- apply_filters( 'datamachine_directives', [] )
  |     |-- DirectivePolicyResolver::resolve()
  |     `-- ProviderRequestAssembler::assemble()
  |-- ProviderRequestAssembler::toProviderRequest()
  |-- wpAiClientPromptContext()
  |-- RequestMetadata::build()
  |-- RequestMetadata::warn_if_oversized()
  |-- wpAiClientUnavailableReason()
  |-- apply_filters( 'datamachine_wp_ai_client_text_result', null, ... )
  |-- wpAiClientTransportProfile()
  |-- AiClient::defaultRegistry()
  |-- wp_ai_client_prompt()
  `-- generate_text_result()
```

## Usage

```php
use DataMachine\Engine\AI\RequestBuilder;

$request_metadata = array();

$ai_response = RequestBuilder::build(
    $messages,          // Canonical Agents API message envelopes.
    $provider,          // wp-ai-client provider identifier.
    $model,             // wp-ai-client model identifier.
    $tools,             // Data Machine tool definitions keyed by name.
    $mode,              // 'chat', 'pipeline', 'system', or extension mode.
    $payload,           // Runtime payload: session, job, flow step, agent, etc.
    $request_metadata   // Output parameter populated before dispatch.
);

if ( $ai_response instanceof \WP_Error ) {
    $message = $ai_response->get_error_message();
} else {
    $content    = RequestBuilder::resultText( $ai_response );
    $tool_calls = datamachine_extract_tool_calls( $ai_response );
}
```

## Return Types

`RequestBuilder::build()` returns native provider-layer objects, not the older `['success' => true, 'data' => ...]` array shape.

Success:

```php
\WordPress\AiClient\Results\DTO\GenerativeAiResult
```

Failure:

```php
\WP_Error
```

The Data Machine conversation turn runner converts `WP_Error` into a structured conversation error. Tests may still provide compact arrays through `datamachine_wp_ai_client_text_result`; RequestBuilder converts those arrays into `GenerativeAiResult` DTOs when the current wp-ai-client API supports it.

## Request Metadata

The optional seventh parameter is an output parameter populated before dispatch:

```php
$request_metadata = RequestMetadata::build(
    $provider_request,
    $structured_tools,
    $directive_metadata,
    $provider,
    $model,
    $mode
);
```

Metadata is used by logs, tests, loop events, transcripts, and final conversation results. It captures fields such as provider, model, mode, request JSON sizes, message/tool sizes, applied directive metadata, and the resolved transport profile.

The conversation loop emits this metadata in the `request_built` event and returns the latest metadata on the normalized result under `request_metadata`.

## Directive Context

`RequestBuilder::assemble()` maps Data Machine runtime payloads into neutral directive context before prompt assembly:

```php
[
    'directive_context' => [
        'job_id'       => $payload['job_id'] ?? null,
        'flow_step_id' => $payload['flow_step_id'] ?? null,
        'agent_slug'   => $payload['agent_slug'] ?? null,
    ],
]
```

Directive ordering and suppression are resolved by `DirectivePolicyResolver` from the `datamachine_directives` filter.

Current directive priorities:

- **20**: Registered memory files.
- **22**: Runtime agent-mode guidance.
- **25**: Authenticated caller context.
- **35**: Daily memory and client-reported context.
- **40-50**: Pipeline, flow, chat inventory, and workflow-specific directives.

## Tool Declarations

Provider tool declarations are assembled from Data Machine tool definitions. Each tool is normalized into a name, description, JSON Schema parameters object, handler metadata, and runtime metadata before conversion to wp-ai-client `FunctionDeclaration` DTOs.

Empty tool schemas are normalized to an object schema with an empty `properties` object so providers receive a valid JSON Schema object:

```php
[
    'type'       => 'object',
    'properties' => (object) array(),
]
```

Tool execution does not happen in RequestBuilder. The Data Machine conversation turn runner executes tool calls later through `ToolExecutor::executeTool()`.

## wp-ai-client Runtime Gates

`RequestBuilder::wpAiClientUnavailableReason()` returns `null` when dispatch is available or a human-readable reason when blocked.

The gate checks:

- `datamachine_wp_ai_client_availability` filter override.
- `wp_ai_client_prompt()` exists.
- `wp_supports_ai()` exists.
- `wp_supports_ai()` returns true.
- `WordPress\AiClient\AiClient` is loaded.
- The requested provider is present in the default wp-ai-client provider registry.

If any check fails, `build()` logs `AI request blocked: wp-ai-client unavailable` and returns:

```php
new \WP_Error( 'wp_ai_client_unavailable', $unavailable_reason );
```

Data Machine no longer falls back to `chubes_ai_request` or `ai-http-client` for runtime provider calls.

## Provider Dispatch

After the gates pass, RequestBuilder:

1. Resolves the wp-ai-client default registry.
2. Resolves the provider ID from the configured provider alias.
3. Applies a provider API key from `WpAiClientProviderAdmin::resolveApiKey()` when present.
4. Installs a default-options HTTP transporter so provider model discovery and final generation share timeout settings.
5. Resolves the model instance.
6. Creates a prompt builder with `wp_ai_client_prompt( $prompt )` or `wp_ai_client_prompt()` when the prompt is empty.
7. Applies provider, model, model config, system instruction, history, and function declarations.
8. Calls `generate_text_result()`.

Exceptions are caught and returned as:

```php
new \WP_Error( 'wp_ai_client_text_exception', 'wp-ai-client request failed: ' . $e->getMessage() );
```

## Prompt And History Mapping

wp-ai-client expects a current prompt plus optional history. RequestBuilder maps Data Machine messages this way:

- System messages become `using_system_instruction()` text.
- The latest user message becomes the current `wp_ai_client_prompt()` prompt.
- Earlier user and assistant/model messages become `with_history()` DTOs.
- Text arrays are flattened into newline-separated text when possible.
- Unsupported or empty content is skipped.

This keeps provider dispatch working even when a Data Machine conversation contains many previous tool and assistant messages.

## Transport Behavior

RequestBuilder applies Data Machine timeout settings to wp-ai-client calls.

Request timeout:

- Setting: `wp_ai_client_request_timeout`.
- Default: `PluginSettings::DEFAULT_WP_AI_CLIENT_REQUEST_TIMEOUT`.
- Max clamp: `PluginSettings::MAX_WP_AI_CLIENT_REQUEST_TIMEOUT`.
- Filter: `datamachine_wp_ai_client_request_timeout`.

Connect timeout:

- Setting: `wp_ai_client_connect_timeout`.
- Default: `PluginSettings::DEFAULT_WP_AI_CLIENT_CONNECT_TIMEOUT`.
- Max clamp: `PluginSettings::MAX_WP_AI_CLIENT_CONNECT_TIMEOUT`.
- Filter: `datamachine_wp_ai_client_connect_timeout`.
- Never exceeds the request timeout.

The resolved profile is added to `request_metadata['transport']`:

```php
[
    'mode'                            => $mode,
    'provider'                        => $provider,
    'model'                           => $model,
    'job_id'                          => $payload['job_id'] ?? null,
    'flow_step_id'                    => $payload['flow_step_id'] ?? null,
    'request_timeout'                 => $request_timeout,
    'connect_timeout'                 => $connect_timeout,
    'request_options_class_available' => class_exists( RequestOptions::class ),
    'request_options_used'            => false,
    'curl_hook_installed'             => false,
]
```

When wp-ai-client exposes `RequestOptions`, Data Machine passes timeout and connect-timeout options to the prompt builder and wraps the registry HTTP transporter with `DefaultOptionsHttpTransporter` so provider metadata/model discovery requests use the same options.

For older or lower-level transport paths, RequestBuilder also installs temporary `wp_ai_client_default_request_timeout` and `http_api_curl` hooks. The cURL hook sets connect timeout plus low-speed timeout/limit for long provider calls. Both hooks are removed in `finally` after dispatch.

## Test Hooks

Stable test and integration hooks:

- `datamachine_wp_ai_client_text_result` may short-circuit dispatch. Return `WP_Error`, `GenerativeAiResult`, or compact array data.
- `datamachine_wp_ai_client_availability` may return `true` to force availability or a string to force an unavailable reason.
- `datamachine_wp_ai_client_request_timeout` customizes request timeout.
- `datamachine_wp_ai_client_connect_timeout` customizes connect timeout.
- `datamachine_directives` registers directive classes for prompt assembly.

Representative tests:

- `tests/agent-conversation-runner-request-smoke.php` verifies RequestBuilder dispatch through `datamachine_run_conversation()`.
- `tests/agent-conversation-runtime-policy-smoke.php` uses the provider test double to exercise completion and continuation behavior.
- Unit support under `tests/Unit/Support/WpAiClientTestDoubles.php` supplies compact wp-ai-client response data for deterministic tests.

## Best Practices

- Use `RequestBuilder::build()` for Data Machine chat, pipeline, and system runtime turns.
- Treat `GenerativeAiResult` and `WP_Error` as the public return contract.
- Use the `$request_metadata` output parameter when a caller needs request diagnostics.
- Keep tool execution outside RequestBuilder.
- Keep durable multi-turn runtime behavior in `datamachine_run_conversation()` and Agents API, not in RequestBuilder.
- Prefer wp-ai-client provider plugins over any legacy `chubes_ai_*` path.

## Historical Context

Older docs may show `RequestBuilder::build()` returning a `success/data/error` array. That was the pre-wp-ai-client adapter shape. Current code returns `GenerativeAiResult` or `WP_Error`.
