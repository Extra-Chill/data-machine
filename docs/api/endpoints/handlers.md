# Handlers Endpoint

**Implementation**: `inc/Api/Handlers.php`

**Base URL**: `/wp-json/datamachine/v1/handlers`

## Overview

The Handlers endpoint provides information about registered fetch, publish, and update handlers available in Data Machine.

## Authentication

Requires `manage_options` capability. See Authentication Guide.

## Endpoints

### GET /handlers

Retrieve list of available handlers with metadata.

**Permission**: `manage_options` capability required

**Purpose**: Discover available handlers for pipeline configuration

**Parameters**:
- `step_type` (string, optional): Filter by step type (`fetch`, `publish`, `update`)

**Example Requests**:

```bash
# Get all handlers
curl https://example.com/wp-json/datamachine/v1/handlers \
  -u username:application_password

# Get publish handlers only
curl https://example.com/wp-json/datamachine/v1/handlers?step_type=publish \
  -u username:application_password

# Get fetch handlers only
curl https://example.com/wp-json/datamachine/v1/handlers?step_type=fetch \
  -u username:application_password

# Get update handlers only
curl https://example.com/wp-json/datamachine/v1/handlers?step_type=update \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": {
    "rss": {
      "type": "fetch",
      "class": "DataMachine\\Core\\Steps\\Fetch\\Handlers\\Rss\\Rss",
      "label": "RSS Feed",
      "description": "Fetch content from RSS feeds",
      "requires_auth": false
    },
    "reddit": {
      "type": "fetch",
      "class": "DataMachine\\Core\\Steps\\Fetch\\Handlers\\Reddit\\Reddit",
      "label": "Reddit",
      "description": "Fetch posts and comments from Reddit",
      "requires_auth": true,
      "auth_type": "oauth2",
      "auth_fields": ["client_id", "client_secret"],
      "callback_url": "https://example.com/wp-admin/admin.php?page=datamachine",
      "is_authenticated": false
    },
    "twitter": {
      "type": "publish",
      "class": "DataMachine\\Core\\Steps\\Publish\\Handlers\\Twitter\\Twitter",
      "label": "Twitter",
      "description": "Post content to Twitter",
      "requires_auth": true,
      "auth_type": "oauth1",
      "auth_fields": ["api_key", "api_secret"],
      "callback_url": "https://example.com/wp-admin/admin.php?page=datamachine",
      "is_authenticated": true,
      "account_details": {
        "username": "exampleuser",
        "id": "1234567890"
      }
    },
    "bluesky": {
      "type": "publish",
      "class": "DataMachine\\Core\\Steps\\Publish\\Handlers\\Bluesky\\Bluesky",
      "label": "Bluesky",
      "description": "Post content to Bluesky",
      "requires_auth": true,
      "auth_type": "app_password",
      "auth_fields": ["handle", "password"],
      "is_authenticated": false
    },
    "wordpress": {
      "type": "publish",
      "class": "DataMachine\\Core\\Steps\\Publish\\Handlers\\WordPress\\WordPress",
      "label": "WordPress",
      "description": "Publish content to WordPress",
      "requires_auth": false
    },
    "wordpress-update": {
      "type": "update",
      "class": "DataMachine\\Core\\Steps\\Update\\Handlers\\WordPress\\WordPress",
      "label": "WordPress Update",
      "description": "Update existing WordPress content",
      "requires_auth": false
    }
  }
}
```

**Response Fields**:
- `success` (boolean): Request success status
- `data` (object): Object of handler definitions keyed by handler slug

**Handler Definition Fields**:
- `type` (string): Handler type (`fetch`, `publish`, `update`)
- `class` (string): PHP class implementing the handler
- `label` (string): Human-readable handler name
- `description` (string): Handler description
- `requires_auth` (boolean): Whether handler requires OAuth/authentication
- `auth_type` (string, optional): Authentication type (`oauth1`, `oauth2`, `app_password`) - only present if `requires_auth` is true
- `auth_fields` (array, optional): Required authentication field names - only present if `requires_auth` is true
- `callback_url` (string, optional): OAuth callback URL for configuration - only present for OAuth handlers
- `is_authenticated` (boolean, optional): Current authentication status - only present if `requires_auth` is true
- `account_details` (object, optional): Account information when authenticated - only present when `is_authenticated` is true

## Handler Types

### Fetch Handlers

Retrieve content from external sources.

**Available Fetch Handlers**:

| Handler | Auth | Description |
|---------|------|-------------|
| **rss** | No | RSS feed parsing with deduplication |
| **reddit** | OAuth2 | Subreddit posts and comments |
| **google-sheets** | OAuth2 | Spreadsheet data extraction |
| **wordpress-local** | No | Local WordPress posts/pages |
| **wordpress-media** | No | WordPress media library |
| **wordpress-api** | No | External WordPress via REST API |
| **files** | No | Local/remote file processing |

### Publish Handlers

Publish content to destinations.

**Available Publish Handlers**:

| Handler | Auth | Limit | Description |
|---------|------|-------|-------------|
| **twitter** | OAuth 1.0a | 280 chars | Twitter posts with media |
| **bluesky** | App Password | 300 chars | Bluesky posts with media |
| **threads** | OAuth2 | 500 chars | Instagram Threads posts |
| **facebook** | OAuth2 | No limit | Facebook posts and comments |
| **wordpress** | Config | No limit | WordPress post creation |
| **google-sheets-output** | OAuth2 | No limit | Google Sheets row insertion |

### Update Handlers

Modify existing content.

**Available Update Handlers**:

| Handler | Auth | Description |
|---------|------|-------------|
| **wordpress-update** | No | Update WordPress posts/pages |

## Handler Metadata

### requires_auth Flag

Indicates whether handler requires authentication:

**true** - Handler requires OAuth or credentials:
- Twitter (OAuth 1.0a)
- Reddit (OAuth2)
- Facebook (OAuth2)
- Threads (OAuth2)
- Google Sheets (OAuth2)
- Bluesky (App Password)

**false** - Handler works without authentication:
- RSS
- WordPress Local
- WordPress API
- WordPress Media
- WordPress Publish
- WordPress Update
- Files

### Handler Registration

Handlers self-register via filter pattern:

```php
add_filter('datamachine_handlers', function($handlers) {
    $handlers['my_handler'] = [
        'type' => 'publish',
        'class' => 'MyNamespace\\MyHandler',
        'label' => __('My Handler', 'textdomain'),
        'description' => __('Handler description', 'textdomain'),
        'requires_auth' => true
    ];
    return $handlers;
});
```

## Integration Examples

### Python Handler Discovery

```python
import requests
from requests.auth import HTTPBasicAuth

url = "https://example.com/wp-json/datamachine/v1/handlers"
auth = HTTPBasicAuth("username", "application_password")

# Get all handlers
response = requests.get(url, auth=auth)

if response.status_code == 200:
    data = response.json()

    # List fetch handlers
    fetch_handlers = {k: v for k, v in data['data'].items() if v['type'] == 'fetch'}
    print("Fetch Handlers:")
    for slug, handler in fetch_handlers.items():
        auth_required = "Yes" if handler['requires_auth'] else "No"
        print(f"  {slug}: {handler['label']} (Auth: {auth_required})")

    # List publish handlers
    publish_handlers = {k: v for k, v in data['data'].items() if v['type'] == 'publish'}
    print("\nPublish Handlers:")
    for slug, handler in publish_handlers.items():
        auth_required = "Yes" if handler['requires_auth'] else "No"
        print(f"  {slug}: {handler['label']} (Auth: {auth_required})")
```

### JavaScript Handler Filtering

```javascript
const axios = require('axios');

const handlersAPI = {
  baseURL: 'https://example.com/wp-json/datamachine/v1/handlers',
  auth: {
    username: 'admin',
    password: 'application_password'
  }
};

// Get handlers by type
async function getHandlersByType(type) {
  const response = await axios.get(handlersAPI.baseURL, {
    params: { step_type: type },
    auth: handlersAPI.auth
  });

  return response.data.data;
}

// Get handlers requiring auth
async function getAuthHandlers() {
  const response = await axios.get(handlersAPI.baseURL, {
    auth: handlersAPI.auth
  });

  const handlers = response.data.data;
  return Object.entries(handlers)
    .filter(([_, handler]) => handler.requires_auth)
    .reduce((obj, [slug, handler]) => {
      obj[slug] = handler;
      return obj;
    }, {});
}

// Usage
const fetchHandlers = await getHandlersByType('fetch');
console.log('Fetch handlers:', Object.keys(fetchHandlers));

const authHandlers = await getAuthHandlers();
console.log('Handlers requiring auth:', Object.keys(authHandlers));
```

## Common Workflows

### Build Handler Selection UI

```bash
# Get all handlers for dropdown menu
curl https://example.com/wp-json/datamachine/v1/handlers \
  -u username:application_password
```

### Filter by Step Type

```bash
# Get only fetch handlers for fetch step configuration
curl https://example.com/wp-json/datamachine/v1/handlers?step_type=fetch \
  -u username:application_password
```

### Check Authentication Requirements

```bash
# Get all handlers and filter client-side for auth requirements
curl https://example.com/wp-json/datamachine/v1/handlers \
  -u username:application_password | jq '.data | to_entries | map(select(.value.requires_auth == true))'
```

## Use Cases

### Dynamic Pipeline Builder

Fetch handler list to populate step configuration UI:

```javascript
const handlers = await getHandlersByType('publish');
const handlerOptions = Object.entries(handlers).map(([slug, handler]) => ({
  value: slug,
  label: handler.label,
  requiresAuth: handler.requires_auth
}));
```

### Handler Validation

Check if handler requires authentication before allowing selection:

```javascript
const handler = handlers['twitter'];
if (handler.requires_auth && !isAuthenticated('twitter')) {
  showAuthenticationPrompt('twitter');
}
```

### Documentation Generation

Generate handler documentation from metadata:

```bash
curl https://example.com/wp-json/datamachine/v1/handlers \
  -u username:application_password | jq -r '.data | to_entries[] | "**\(.value.label)** (\(.key)): \(.value.description)"'
```

## Related Documentation

- Pipelines Endpoints - Pipeline management
- Auth Endpoints - Handler authentication
- Tools Endpoint - AI tool availability
- StepTypes Endpoint - Step type information

---

**Base URL**: `/wp-json/datamachine/v1/handlers`
**Permission**: `manage_options` capability required
**Implementation**: `inc/Api/Handlers.php`
**Handler Registration**: Via `datamachine_handlers` filter
