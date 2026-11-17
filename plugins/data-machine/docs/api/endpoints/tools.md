# Tools Endpoint

**Implementation**: `inc/Api/Tools.php`

**Base URL**: `/wp-json/datamachine/v1/tools`

## Overview

The Tools endpoint provides information about registered AI tools and their configuration status.

## Authentication

This endpoint is public (`permission_callback` returns true), so it does not require authentication. It returns global tool metadata suitable for UI discovery.

## Endpoints

### GET /tools

Retrieve registered AI tools and configuration status.

**Permission**: Public

**Purpose**: Discover available AI tools and check configuration requirements

**Parameters**: None

**Example Request**:

```bash
curl https://example.com/wp-json/datamachine/v1/tools \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": {
    "local_search": {
      "label": "Local Search",
      "description": "Search WordPress content locally",
      "requires_config": false,
      "configured": true,
      "globally_enabled": true,
      "modes": ["pipeline"]
    }
  }
}
```

Notes:
- The response payload is under `data`.
- Without `context`, the endpoint returns every registered static tool. Pass `context=pipeline` or `context=system` to return only tools that resolve in that mode.

**Response Fields**:
- `success` (boolean): Request success status
- `data` (object): Object of tool definitions keyed by tool ID

**Tool Definition Fields**:
- `label` (string): Human-readable tool name
- `description` (string): Tool description
- `requires_config` (boolean): Whether the tool needs configuration before use
- `configured` (boolean): Whether tool is properly configured
- `globally_enabled` (boolean): Whether the tool is enabled site-wide
- `modes` (array): Agent modes the tool declares (`pipeline`, `system`, or extension modes)

## Available Tools

### Global Tools

Available to all AI agents via `datamachine_global_tools` filter.

#### local_search

**Tool ID**: `local_search`

**Configuration Required**: No

**Modes**: `pipeline`

**Purpose**: Search WordPress content locally

**Parameters**:
- `query` (string): Search query
- `num_results` (integer): Number of results (1-20)
- `post_type` (string, optional): Filter by post type

**Use Cases**:
- Find related WordPress content
- Discover similar posts
- Link to existing content

#### web_fetch

**Tool ID**: `web_fetch`

**Configuration Required**: No

**Modes**: `pipeline`

**Purpose**: Fetch and extract content from web pages

**Parameters**:
- `url` (string): URL to fetch
- `selector` (string, optional): CSS selector for content extraction

**Limits**:
- 50,000 character limit per fetch
- HTML processing and cleaning
- JavaScript-free content extraction

**Use Cases**:
- Extract article content
- Gather reference material
- Pull data from web pages

#### wordpress_post_reader

**Tool ID**: `wordpress_post_reader`

**Configuration Required**: No

**Modes**: `pipeline`

**Purpose**: Read and analyze single WordPress post content

**Parameters**:
- `url` (string): WordPress post URL

**Use Cases**:
- Analyze post structure
- Extract metadata
- Reference existing content

## Tool Configuration

### Configuration Status

Tools show `"configured": false` if required configuration is missing:

```json
{
  "image_generation": {
    "label": "Image Generation",
    "requires_config": true,
    "configured": false,
    "modes": ["pipeline"]
  }
}
```

## Tool Categories

### Global Tools

Registered via `datamachine_global_tools` filter:

```php
add_filter('datamachine_global_tools', function($tools) {
    $tools['my_tool'] = [
        'class' => 'MyExtension\\Tools\\MyTool',
        'method' => 'execute',
        'description' => 'Tool description',
        'parameters' => [
            'param1' => ['type' => 'string', 'required' => true]
        ]
    ];
    return $tools;
});
```

## Integration Examples

### Python Tool Discovery

```python
import requests
from requests.auth import HTTPBasicAuth

url = "https://example.com/wp-json/datamachine/v1/tools"
auth = HTTPBasicAuth("username", "application_password")

response = requests.get(url, auth=auth)

if response.status_code == 200:
    data = response.json()

    # List configured tools
    configured = {k: v for k, v in data['tools'].items() if v['configured']}
    print(f"Configured tools: {len(configured)}")

    # List pipeline tools
    pipeline_tools = {k: v for k, v in data['tools'].items() if 'pipeline' in v['modes']}
    print(f"Pipeline tools: {', '.join(pipeline_tools.keys())}")

    # List unconfigured tools
    unconfigured = [k for k, v in data['tools'].items() if not v['configured']]
    if unconfigured:
        print(f"Unconfigured tools: {', '.join(unconfigured)}")
```

### JavaScript Tool Validation

```javascript
const axios = require('axios');

const toolsAPI = {
  baseURL: 'https://example.com/wp-json/datamachine/v1/tools',
  auth: {
    username: 'admin',
    password: 'application_password'
  }
};

// Get configured tools
async function getConfiguredTools() {
  const response = await axios.get(toolsAPI.baseURL, {
    auth: toolsAPI.auth
  });

  const tools = response.data.tools;
  return Object.entries(tools)
    .filter(([_, tool]) => tool.configured)
    .reduce((obj, [id, tool]) => {
      obj[id] = tool;
      return obj;
    }, {});
}

// Get pipeline tools
async function getPipelineTools() {
  const response = await axios.get(toolsAPI.baseURL, {
    auth: toolsAPI.auth
  });

  const tools = response.data.tools;
  return Object.entries(tools)
    .filter(([_, tool]) => tool.modes.includes('pipeline'))
    .map(([id, _]) => id);
}

// Usage
const configured = await getConfiguredTools();
console.log('Configured tools:', Object.keys(configured));

const pipelineTools = await getPipelineTools();
console.log('Pipeline tools:', pipelineTools);
```

## Common Workflows

### Check Tool Availability

```bash
# Get all tools and their status
curl https://example.com/wp-json/datamachine/v1/tools \
  -u username:application_password
```

### Validate Tool Configuration

```bash
# Check which tools need configuration
curl https://example.com/wp-json/datamachine/v1/tools \
  -u username:application_password | jq '.tools | to_entries | map(select(.value.configured == false))'
```

## Use Cases

### Tool Configuration UI

Build configuration interface based on tool status:

```javascript
const tools = await getConfiguredTools();

const unconfigured = Object.entries(tools)
  .filter(([_, tool]) => !tool.configured)
  .map(([id, tool]) => ({
    id,
    label: tool.label,
    needsConfig: true
  }));
```

### Tool Availability Check

Verify tool configuration before use:

```javascript
async function canUseLocalSearch() {
  const tools = await getConfiguredTools();
  return tools['local_search']?.configured || false;
}
```

## Related Documentation

- [Settings](settings.md)
- [Providers](providers.md)

---

**Base URL**: `/wp-json/datamachine/v1/tools`
**Permission**: `manage_options` capability required
**Implementation**: `inc/Api/Tools.php`
**Tool Registration**: Via `datamachine_global_tools` and the `datamachine_tools` registry
