# Settings Endpoints

**Implementation**: `inc/Api/Settings.php`

**Base URL**: `/wp-json/datamachine/v1/settings`

## React Interface

The Settings page is a React admin interface built with `@wordpress/element` and `@wordpress/components`.

- Data fetching and mutations use TanStack Query.
- UI state is local React state (active tab persisted to `localStorage`).
- REST calls use a shared `@wordpress/api-fetch` wrapper that reads `window.dataMachineSettingsConfig` (or other page configs) for the REST namespace and nonce.

## Overview

Settings endpoints manage Data Machine core and extension tool configuration.

## Authentication

Settings endpoints require `PermissionHelper::can( 'manage_settings' )`. Administrators pass through the mapped Data Machine capability fallback.

## Endpoints

### POST /settings/tools/{tool_id}

Save configuration for a specific tool.

**Permission**: `manage_settings`

**Parameters**:
- `tool_id` (string, required): Tool identifier (in URL path) - e.g., `image_generation`
- `config_data` (object, required): Tool configuration fields as key-value pairs

**Tool Configuration Storage**:
- Delegates to `datamachine_save_tool_config` action for tool-specific handlers
- Each tool implements its own configuration storage mechanism
- Extension tools may implement their own site options and adoption paths.

**Example Request**:

```bash
# Save Image Generation configuration
curl -X POST https://example.com/wp-json/datamachine/v1/settings/tools/image_generation \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{
    "config_data": {
      "provider": "openai",
      "api_key": "sk-example"
    }
  }'
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "message": "Configuration saved successfully",
  "configured": true
}
```

**Response Fields**:
- `success` (boolean): Request success status
- `message` (string): Confirmation message
- `configured` (boolean): Tool configuration status after save

**Error Response (400 Bad Request)**:

```json
{
  "code": "invalid_config_data",
  "message": "Valid configuration data is required.",
  "data": {"status": 400}
}
```

**Error Response (500 Internal Server Error)**:

```json
{
  "code": "no_tool_handler",
  "message": "No configuration handler found for tool: invalid_tool",
  "data": {"status": 500}
}
```

### Global Settings

- `problem_flow_threshold` (integer): Number of consecutive failures or "no items" results before a flow is flagged as a problem flow. Default: `3`.
- `pipeline_ai_concurrency_limit` (integer): Site-wide maximum concurrent pipeline AI provider calls. Default: `3`.
- `pipeline_ai_provider_concurrency_limits` (object): Optional per-provider caps keyed by provider slug, for example `{ "openai": 10 }`. Provider caps apply in addition to the site-wide cap.
- `pipeline_ai_throttle_delay` (integer): Seconds before a pipeline AI job retries when the AI concurrency lane is saturated. Default: `10`.
- `queue_tuning` (object): Local queue producer/consumer tuning for Action Scheduler and batch fan-out. Defaults are conservative for self-hosted installs; operator ceilings support managed/high-throughput runtimes. These settings control job scheduling/draining, not provider-call concurrency.
  - `concurrent_batches` (integer): Parallel Action Scheduler batches. Default: `3`, maximum: `50`.
  - `batch_size` (integer): Actions claimed per scheduler batch. Default: `25`, maximum: `500`.
  - `time_limit` (integer): Seconds per scheduler batch. Default: `60`, maximum: `300`.
  - `chunk_size` (integer): Child jobs created per fan-out scheduling cycle. Default: `10`, maximum: `500`.
  - `chunk_delay` (integer): Seconds between fan-out chunks. Default: `30`, maximum: `300`.

**Example Request**:

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/settings \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{
    "problem_flow_threshold": 5,
    "queue_tuning": {
      "concurrent_batches": 20,
      "batch_size": 300,
      "time_limit": 300,
      "chunk_size": 300,
      "chunk_delay": 0
    },
    "pipeline_ai_concurrency_limit": 10,
    "pipeline_ai_provider_concurrency_limits": { "openai": 10 },
    "pipeline_ai_throttle_delay": 5
  }'
```

**Supported Tools**:
- Core tools and extension tools can register handlers via `datamachine_save_tool_config`.

## Handler Defaults

### GET /settings/handler-defaults

Retrieve all site-wide handler defaults, grouped by step type. Auto-populates from schema defaults on first access.

**Permission**: `manage_options` capability required

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": {
    "fetch": {
      "label": "Fetch Content",
      "uses_handler": true,
      "handlers": {
        "rss": {
          "label": "RSS Feed",
          "description": "Fetch content from RSS/Atom feeds",
          "defaults": {
            "max_items": 10
          },
          "fields": {
            "max_items": {
              "type": "number",
              "label": "Max Items",
              "default": 10
            }
          }
        }
      }
    }
  }
}
```

### PUT /settings/handler-defaults/{handler_slug}

Update site-wide defaults for a specific handler. These values are used for new flows when fields are not explicitly set.

**Permission**: `manage_options` capability required

**Parameters**:
- `handler_slug` (string, required): Handler identifier (in URL path)
- `defaults` (object, required): Default configuration values keyed by field ID

**Example Request**:

```bash
curl -X PUT https://example.com/wp-json/datamachine/v1/settings/handler-defaults/wordpress_publish \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{
    "defaults": {
      "post_status": "publish",
      "post_author": 1
    }
  }'
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": {
    "handler_slug": "wordpress_publish",
    "defaults": {
      "post_status": "publish",
      "post_author": 1
    },
    "message": "Defaults updated for handler \"wordpress_publish\"."
  }
}
```

## Tool Configuration

### Custom Tools

Tools can register configuration handlers via the `datamachine_save_tool_config` action:

```php
add_action('datamachine_save_tool_config', function($tool_id, $config_data) {
    if ($tool_id === 'my_custom_tool') {
        update_option('my_tool_config', $config_data);
    }
}, 10, 2);
```

## Integration Examples

### Python Tool Configuration

```python
import requests
from requests.auth import HTTPBasicAuth

url = "https://example.com/wp-json/datamachine/v1/settings/tools/my_custom_tool"
auth = HTTPBasicAuth("username", "application_password")

config = {
    "config_data": {
        "api_key": "example-api-key"
    }
}

response = requests.post(url, json=config, auth=auth)

if response.status_code == 200:
    print("Tool configured successfully")
else:
    print(f"Error: {response.json()['message']}")
```

### JavaScript Settings Update

```javascript
const axios = require('axios');

const settingsAPI = {
  baseURL: 'https://example.com/wp-json/datamachine/v1',
  auth: {
    username: 'admin',
    password: 'application_password'
  }
};

// Configure tool
async function configureTool(toolId, configData) {
  const response = await axios.post(
    `${settingsAPI.baseURL}/settings/tools/${toolId}`,
    { config_data: configData },
    { auth: settingsAPI.auth }
  );

  return response.data.configured;
}

// Usage
const configured = await configureTool('my_custom_tool', {
  api_key: 'example-api-key'
});
console.log(`Tool configured: ${configured}`);
```

## Related Documentation

- Tools Endpoint - Tool availability
- Authentication - Auth methods
- Errors - Error handling

---

**Base URL**: `/wp-json/datamachine/v1/settings`
**Permission**: `manage_options` capability required
**Implementation**: `inc/Api/Settings.php`
