# Chat Endpoint

**Implementation**: `inc/Api/Chat/Chat.php`

**Base URL**: `/wp-json/datamachine/v1/chat`

## Overview

The Chat endpoint provides a conversational AI interface for building and executing Data Machine workflows through natural language interaction with multi-turn conversation support.

In addition to sending messages, it also supports listing, retrieving, and deleting persisted chat sessions.

## Authentication

Requires `manage_options` capability. See Authentication Guide.

## Endpoints

### POST /chat

Send a message to the AI assistant.

**Permission**: `manage_options` capability required

**Purpose**: Natural language workflow building and Data Machine administration via conversational AI

**Parameters**:
- `message` (string, required): User message to send to AI
- `session_id` (string, optional): Session ID for conversation continuity (omit to create new session)
- `selected_pipeline_id` (int, optional): Active pipeline context for prioritized information awareness (@since v0.8.0)
- `provider` (string, optional): AI provider (`openai`, `anthropic`, `google`, `grok`, `openrouter`) - uses default from settings if not provided
- `model` (string, optional): Model identifier (e.g., `gpt-4`, `claude-sonnet-4`) - uses default from settings if not provided

**Example Requests**:

```bash
# Start new conversation
curl -X POST https://example.com/wp-json/datamachine/v1/chat \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{
    "message": "Create a pipeline that fetches from RSS and publishes to Twitter",
    "provider": "anthropic",
    "model": "claude-sonnet-4"
  }'

# Continue existing conversation
curl -X POST https://example.com/wp-json/datamachine/v1/chat \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{
    "message": "Now add a flow for that pipeline",
    "session_id": "session_abc123",
    "provider": "anthropic",
    "model": "claude-sonnet-4"
  }'
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "session_id": "session_abc123",
  "response": "I'll help you create a pipeline with RSS fetch and Twitter publish steps...",
  "tool_calls": [
    {
      "id": "call_abc123",
      "type": "function",
      "function": {
        "name": "create_pipeline",
        "arguments": "{\"name\":\"RSS to Twitter\",\"steps\":[{\"type\":\"fetch\",\"handler\":\"rss\"},{\"type\":\"ai\"},{\"type\":\"publish\",\"handler\":\"twitter\"}]}"
      }
    }
  ],
  "conversation": [
    {"role": "user", "content": "Create a pipeline..."},
    {"role": "assistant", "content": "I'll help you...", "tool_calls": [...]}
  ],
  "metadata": {
    "last_activity": "2024-01-02 14:30:00",
    "message_count": 2
  }
}
```

**Response Fields**:
- `success` (boolean): Request success status
- `session_id` (string): Session identifier for conversation continuity
- `response` (string): AI assistant response message
- `tool_calls` (array, optional): Array of tool calls made by AI
- `conversation` (array): Full conversation history
- `metadata` (object): Session metadata
  - `last_activity` (string): Last activity timestamp
  - `message_count` (integer): Number of messages in conversation

## Session Management

### Session Creation

First message automatically creates a new session:

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/chat \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"message": "Hello"}'
```

Returns `session_id` for subsequent messages.

### Session Continuity

Use `session_id` to continue existing conversation:

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/chat \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"message": "Continue previous task", "session_id": "session_abc123"}'
```

### Session Storage

Chat sessions are stored in the `wp_datamachine_chat_sessions` table.

**Implementation**: `inc/Core/Database/Chat/Chat.php`

**Database Table**: `wp_datamachine_chat_sessions`

**Features**:
- Persistent conversation history
- User-scoped sessions (users can only access their own)
- Message count tracking
- Provider and model tracking
- Agent type tracking (`agent_type`: `chat`, `cli`)

### Session Security

**User Isolation**:
- Sessions are user-scoped
- Users can only access their own sessions
- Invalid session returns 404 error
- Access to another user's session returns 403 error

**Error Response (404 Not Found)** - Invalid session:

```json
{
  "code": "session_not_found",
  "message": "Session not found",
  "data": {"status": 404}
}
```

**Error Response (403 Forbidden)** - Access denied:

```json
{
  "code": "session_access_denied",
  "message": "Access denied to this session",
  "data": {"status": 403}
}
```

## Available Tools

### Global Tools

Available to all AI agents via `datamachine_global_tools` filter:

- **google_search** - Web search with site restriction
- **local_search** - WordPress content search
- **web_fetch** - Web page content retrieval
- **wordpress_post_reader** - Single post analysis

### Chat-Specific Tools

Available only to chat AI agents via `datamachine_chat_tools` filter (@since v0.4.3 specialized tools):

- **execute_workflow** (@since v0.3.0) - Execute complete multi-step workflows with automatic defaults injection
- **get_handler_defaults** (@since v0.6.25) - Retrieve site-wide handler defaults for reference
- **set_handler_defaults** (@since v0.6.25) - Update site-wide handler defaults
- **add_pipeline_step** (@since v0.4.3) - Add steps to existing pipelines
- **api_query** (@since v0.4.3) - REST API query tool for discovery (supports batch requests @since v0.7.0)
- **configure_flow_step** (@since v0.4.2) - Configure handler and AI messages
- **configure_pipeline_step** (@since v0.4.4) - Configure pipeline-level AI settings
- **copy_flow** (@since v0.6.25) - Copy flow to same or different pipeline with optional overrides
- **create_flow** (@since v0.4.2) - Create flow instances from pipelines
- **create_pipeline** (@since v0.4.3) - Create pipelines with optional steps
- **handler_documentation** (@since v0.7.0) - Discover available handlers and their settings schema
- **run_flow** (@since v0.4.4) - Execute or schedule flows
- **update_flow** (@since v0.4.4) - Update flow properties
- **validate_credentials** (@since v0.8.0) - Test authentication provider credentials during configuration

## Universal Engine Architecture

**Since**: v0.2.0

The Chat endpoint uses the Universal Engine architecture at `/inc/Engine/AI/` for consistent AI request handling across Pipeline and Chat agents.

### Core Components Used

**AIConversationLoop**
- Multi-turn conversation execution
- Automatic tool call detection and execution
- Completion detection (conversation ends when AI returns no tool calls)
- Turn limiting (default: 8 turns)
- Duplicate tool call prevention

**RequestBuilder**
- Centralized AI request construction
- Hierarchical directive application (global → agent → chat-specific)
- Tool restructuring for provider compatibility
- Integration with ai-http-client

**ToolExecutor**
- Universal tool discovery via filters (`datamachine_global_tools`, `datamachine_chat_tools`)
- Tool enablement validation
- Execution with comprehensive error handling

**ConversationManager**
- Message formatting utilities
- Tool call/result message generation
- Duplicate detection logic

**ToolParameters**
- Unified parameter building for tools
- Automatic content/title extraction
- Session context integration

### Chat Agent Implementation

**File**: `/inc/Api/Chat/ChatFilters.php`
**Purpose**: Registers chat-specific behavior with Universal Engine

**Chat Pipelines Inventory** (@since v0.7.0):
The chat agent receives a lightweight inventory of all pipelines, their configured steps (ID, name, type), and flow summaries (ID, name, handlers) via the [ChatPipelinesDirective](../../core-system/ai-directives.md#chatpipelinesdirective-priority-45). When `selected_pipeline_id` is provided (e.g., from the Integrated Chat Sidebar), the agent prioritizes and expands context for that specific pipeline.

**Tool Enablement** (chat-specific):
```php
add_filter('datamachine_tool_enabled', function($enabled, $tool_name, $tool_config, $context_id) {
    // Chat agent: context_id = null (use global tool enablement)
    if ($context_id === null) {
        $tool_configured = apply_filters('datamachine_tool_configured', false, $tool_name);
        $requires_config = !empty($tool_config['requires_config']);
        return !$requires_config || $tool_configured;
    }
    return $enabled;
}, 5, 4);
```

**Directive Registration** (@since v0.2.5):
```php
// Current: Unified directive registration with agent type targeting
add_filter('datamachine_directives', function($directives) {
    $directives[] = [
        'class' => ChatAgentDirective::class,
        'priority' => 10,
        'agent_types' => ['chat']
    ];
    return $directives;
});
```

### Differences from Pipeline Agent

**Chat Agent**:
- Session-based conversation persistence
- Global tool enablement (not step-specific)
- No data packets from previous steps
- Chat-specific specialized tools (create_pipeline, run_flow, etc.)
- Session context instead of job context

**Pipeline Agent**:
- Job-based execution within workflows
- Step-specific tool enablement
- Data packets flow through steps
- Handler tools for specific steps
- Job context with engine data

### Session Context Structure

```php
$context = [
    'session_id' => $session_id  // Chat session identifier
];
```

Used by:
- RequestBuilder for directive application
- ToolExecutor for tool execution
- ToolParameters for parameter building

### Tool Discovery

Chat agent discovers tools via three sources:

1. **Global Tools** (`datamachine_global_tools` filter):
   - google_search
   - local_search
   - web_fetch
   - wordpress_post_reader

2. **Chat Tools** (`datamachine_chat_tools` filter) (@since v0.4.3 specialized tools):
   - execute_workflow, add_pipeline_step, api_query, configure_flow_step
   - configure_pipeline_step, create_flow, create_pipeline, run_flow, update_flow

3. **Filtered by Enablement** (`datamachine_tool_enabled` filter):
   - Configuration validation
   - Global enablement check

### Conversation Flow

```
1. User sends message via POST /chat
   ↓
2. Chat endpoint loads or creates session
   ↓
3. AIConversationLoop executes:
   a. RequestBuilder builds AI request with chat directives
   b. AI responds with content and/or tool calls
   c. ToolExecutor executes each tool call
   d. ConversationManager formats tool results
   e. Repeat until AI returns no tool calls (max 8 turns)
   ↓
4. Session updated with conversation history
   ↓
5. Response returned to user
```

## Filter-Based Architecture

### Unified Directive System (@since v0.2.5)

Directives are registered via the `datamachine_directives` filter with priority and agent targeting:

```php
add_filter('datamachine_directives', function($directives) {
    // Global directive (applies to all agents)
    $directives[] = [
        'class' => MyDirective::class,
        'priority' => 25,
        'agent_types' => ['all']  // Applies to chat and pipeline agents
    ];

    // Chat-specific directive
    $directives[] = [
        'class' => MyChatDirective::class,
        'priority' => 15,
        'agent_types' => ['chat']  // Applies only to chat agent
    ];

    return $directives;
});
```

**Priority Guidelines**:
- **10-19**: Core agent identity and foundational instructions
- **20-29**: Global system prompts and universal behavior
- **30-39**: Agent-specific system prompts and context
- **40-49**: Workflow and execution context directives
- **50+**: Environmental and site-specific directives



## Integration Examples

### Python Chat Integration

```python
import requests
from requests.auth import HTTPBasicAuth

url = "https://example.com/wp-json/datamachine/v1/chat"
auth = HTTPBasicAuth("username", "application_password")

# Start conversation
initial_message = {
    "message": "Create a pipeline that fetches from RSS",
    "provider": "anthropic",
    "model": "claude-sonnet-4"
}

response = requests.post(url, json=initial_message, auth=auth)
data = response.json()

session_id = data['session_id']
print(f"AI: {data['response']}")

# Continue conversation
follow_up = {
    "message": "Add a Twitter publish step",
    "session_id": session_id
}

response2 = requests.post(url, json=follow_up, auth=auth)
data2 = response2.json()

print(f"AI: {data2['response']}")
```

### JavaScript Chat Interface

```javascript
const axios = require('axios');

class ChatClient {
  constructor(baseURL, auth) {
    this.baseURL = baseURL;
    this.auth = auth;
    this.sessionId = null;
  }

  async sendMessage(message, provider = 'anthropic', model = 'claude-sonnet-4') {
    const payload = {
      message,
      provider,
      model
    };

    if (this.sessionId) {
      payload.session_id = this.sessionId;
    }

    const response = await axios.post(this.baseURL, payload, {
      auth: this.auth
    });

    // Store session ID from first message
    if (!this.sessionId) {
      this.sessionId = response.data.session_id;
    }

    return response.data;
  }
}

// Usage
const chat = new ChatClient(
  'https://example.com/wp-json/datamachine/v1/chat',
  { username: 'admin', password: 'application_password' }
);

const response1 = await chat.sendMessage('Create an RSS to Twitter pipeline');
console.log(`AI: ${response1.response}`);

const response2 = await chat.sendMessage('Execute the pipeline now');
console.log(`AI: ${response2.response}`);
```

## Common Workflows

### Create Pipeline via Chat

```bash
# 1. Start conversation
curl -X POST https://example.com/wp-json/datamachine/v1/chat \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{
    "message": "Create a pipeline with these steps: fetch from RSS, process with AI, publish to Twitter"
  }'

# AI will create pipeline using create_pipeline tool
```

### Monitor Jobs via Chat

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/chat \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{
    "message": "Show me failed jobs from the last hour",
    "session_id": "session_abc123"
  }'
```

### Configure Handler via Chat

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/chat \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{
    "message": "Configure the Twitter handler for flow 42 with max length 280",
    "session_id": "session_abc123"
  }'
```

## Use Cases

### Conversational Pipeline Builder

Build complex workflows through natural language conversation:

```
User: "Create a pipeline that imports recipes from an RSS feed"
AI: [Creates pipeline with RSS fetch step]

User: "Add AI processing to extract ingredients"
AI: [Adds AI step with custom prompt]

User: "Publish to WordPress"
AI: [Adds WordPress publish step]
```

### Natural Language Debugging

Debug workflows through conversational interface:

```
User: "Why did flow 42 fail?"
AI: [Checks logs, identifies error, suggests fix]

User: "Clear the failed jobs"
AI: [Executes DELETE /jobs with type=failed]
```

### Interactive Configuration

Configure Data Machine settings through chat:

```
User: "Set up Google Search API"
AI: [Guides through configuration, saves settings]
```

## Related Documentation

- Universal Engine Architecture - Shared AI infrastructure
- AI Conversation Loop - Multi-turn conversation execution
- Tool Execution Architecture - Tool discovery and execution
- Universal Engine Filters - Directive and tool filters
- Execute Endpoint - Workflow execution
- Tools Endpoint - Available tools
- Handlers Endpoint - Handler information
- Authentication - Auth methods

---

**Base URL**: `/wp-json/datamachine/v1/chat`
**Permission**: `manage_options` capability required
**Implementation**: `inc/Api/Chat/Chat.php`
**Session Storage**: `wp_datamachine_chat_sessions` table
**Session Expiration**: Not enforced by default (table supports an `expires_at` column for optional cleanup)

## Additional Endpoints

### POST /chat/continue

Continue a chat session with additional context from external triggers (webhooks, scheduled jobs, etc.).

**Permission**: `manage_options` capability required

**Parameters**:
- `session_id` (string, required): Session ID to continue
- `message` (string, required): Message to send
- `provider` (string, optional): AI provider
- `model` (string, optional): Model identifier

**Example**:
```bash
curl -X POST https://example.com/wp-json/datamachine/v1/chat/continue \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"session_id": "abc123", "message": "Process new RSS items"}'
```

### GET /chat/{session_id}

Retrieve a chat session with its conversation history.

**Permission**: `manage_options` capability required

**Parameters**:
- `session_id` (string, required): Session ID to retrieve

**Response**:
```json
{
  "id": "abc123",
  "user_id": 1,
  "session_id": "abc123",
  "provider": "anthropic",
  "model": "claude-sonnet-4",
  "messages": [
    {"role": "user", "content": "Hello"},
    {"role": "assistant", "content": "Hi! How can I help?"}
  ],
  "message_count": 2,
  "created_at": "2024-01-01 12:00:00",
  "last_activity": "2024-01-01 12:05:00"
}
```

### POST /chat/ping

Receive external pings to trigger AI responses. Used for scheduled/recurring workflows.

**Permission**: `manage_options` capability required

**Parameters**:
- `session_id` (string, required): Session ID to ping
- `message` (string, required): Message to send
- `provider` (string, optional): AI provider
- `model` (string, optional): Model identifier

**Use case**: Set up scheduled pings to trigger flow executions or periodic reviews.

**Example**:
```bash
curl -X POST https://example.com/wp-json/datamachine/v1/chat/ping \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"session_id": "abc123", "message": "Check for new RSS items and publish"}'
```
