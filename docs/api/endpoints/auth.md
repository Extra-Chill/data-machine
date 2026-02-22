# Auth Endpoints

**Implementation**: `inc/Api/Auth.php`

**Base URL**: `/wp-json/datamachine/v1/auth`

## Overview

Auth endpoints manage OAuth accounts and handler authentication for external services.

## Authentication

Requires `manage_options` capability. See Authentication Guide.

## Handler Auth Requirements (@since v0.3.1)

Handlers can declare whether they require authentication via the `requires_auth` flag. When a handler's `requires_auth` is `false`, the Auth API bypasses authentication validation for that handler, allowing handlers like public scrapers to operate without OAuth configuration.

**Auth Bypass Behavior**:
- GET `/auth/{handler_slug}/status` - Returns success with `requires_auth: false` without checking OAuth state
- PUT `/auth/{handler_slug}` - Returns success without requiring credentials
- DELETE `/auth/{handler_slug}` - Returns success without disconnection operations

## Provider Key Resolution

Auth endpoints accept `{handler_slug}` in the URL, but internally they resolve the auth provider via the handler's `auth_provider_key` (from the Handlers registry). This supports shared authentication across multiple handlers (for example, one set of Meta credentials used by multiple publish destinations).

## Endpoints

### GET /auth/{handler_slug}/status

Check OAuth authentication status for a handler.

**Permission**: `manage_options` capability required

**Parameters**:
- `handler_slug` (string, required): Handler identifier (e.g., `reddit`, `google_sheets`)

**Returns**: Authentication status, account details, and OAuth error/success states

**Example Request**:

```bash
curl https://example.com/wp-json/datamachine/v1/auth/reddit/status \
-u username:application_password
```

**Success Response - Authenticated (200 OK)**:

```json
{
  "success": true,
  "data": {
    "authenticated": true,
    "account_details": {
      "username": "exampleuser",
      "id": "1234567890"
    },
    "config_status": {
      "api_key": "••••••••",
      "api_secret": "••••••••"
    },
    "handler_slug": "reddit"
  }
}
```

**Success Response - Not Authenticated (200 OK)**:

```json
{
  "success": true,
  "data": {
    "authenticated": false,
    "error": false,
    "config_status": {
      "api_key": "",
      "api_secret": ""
    },
    "handler_slug": "reddit"
  }
}
```

**Success Response - OAuth Error (200 OK)**:

```json
{
  "success": true,
  "data": {
    "authenticated": false,
    "error": true,
    "error_code": "oauth_failed",
    "error_message": "User denied authorization",
    "config_status": {
      "api_key": "",
      "api_secret": ""
    },
    "handler_slug": "reddit"
  }
}
```

**Error Response (404 Not Found)**:

```json
{
  "code": "auth_provider_not_found",
  "message": "Authentication provider not found",
  "data": {"status": 404}
}
```

### PUT /auth/{handler_slug}

Save authentication configuration for a handler.

**Permission**: `manage_options` capability required

**Parameters**:
- `handler_slug` (string, required): Handler identifier (in URL path)
- Additional parameters vary by handler (e.g., `api_key`, `api_secret`, `client_id`, `client_secret`)

**Storage Behavior**:
- **OAuth providers** (Reddit, Google Sheets): Stored to `oauth_keys`
- **Simple auth providers**: Stored to `oauth_account`

**Example Requests**:

```bash
# Save Reddit OAuth keys
curl -X PUT https://example.com/wp-json/datamachine/v1/auth/reddit \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{
    "client_id": "your_client_id",
    "client_secret": "your_client_secret"
  }'
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "message": "Configuration saved successfully"
}
```

**Success Response - No Changes (200 OK)**:

```json
{
  "success": true,
  "message": "Configuration is already up to date - no changes detected"
}
```

**Error Response (400 Bad Request)**:

```json
{
  "code": "required_field_missing",
  "message": "API Key is required",
  "data": {"status": 400}
}
```

### DELETE /auth/{handler_slug}

Disconnect an authenticated account.

**Permission**: `manage_options` capability required

**Parameters**:
- `handler_slug` (string, required): Handler identifier (in URL path)

**Example Request**:

```bash
curl -X DELETE https://example.com/wp-json/datamachine/v1/auth/twitter \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "message": "Twitter account disconnected successfully"
}
```

**Error Response (500 Internal Server Error)**:

```json
{
  "code": "disconnect_failed",
  "message": "Failed to disconnect account",
  "data": {"status": 500}
}
```

## Authentication Methods

### OAuth 1.0a

Used by Twitter.

**Configuration Requirements**:
- `consumer_key` - Application consumer key
- `consumer_secret` - Application consumer secret

**OAuth Flow**:
1. Save consumer key/secret via PUT endpoint
2. User initiates OAuth via `/datamachine-auth/twitter/` URL
3. Popup window handles OAuth callback
4. Access tokens stored automatically
5. Check status via GET endpoint

### OAuth 2.0

Used by Reddit, Facebook, Threads, Google Sheets.

**Configuration Requirements**:
- `client_id` - OAuth application client ID
- `client_secret` - OAuth application client secret

**OAuth Flow**:
1. Save client ID/secret via PUT endpoint
2. User initiates OAuth via `/datamachine-auth/{handler}/` URL
3. Popup window handles OAuth callback
4. Access tokens stored automatically
5. Check status via GET endpoint

### App Password

Used by Bluesky.

**Configuration Requirements**:
- `username` - Bluesky username (e.g., `user.bsky.social`)
- `app_password` - Bluesky app password

**Authentication Flow**:
1. Save credentials via PUT endpoint
2. Credentials used directly (no OAuth flow)
3. Check status via GET endpoint

## Supported Handlers

### Twitter

**Handler Slug**: `twitter`

**Auth Type**: OAuth 1.0a

**Configuration**:
```json
{
  "consumer_key": "your_consumer_key",
  "consumer_secret": "your_consumer_secret"
}
```

**OAuth URL**: `/datamachine-auth/twitter/`

### Reddit

**Handler Slug**: `reddit`

**Auth Type**: OAuth 2.0

**Configuration**:
```json
{
  "client_id": "your_client_id",
  "client_secret": "your_client_secret"
}
```

**OAuth URL**: `/datamachine-auth/reddit/`

### Facebook

**Handler Slug**: `facebook`

**Auth Type**: OAuth 2.0

**Configuration**:
```json
{
  "app_id": "your_app_id",
  "app_secret": "your_app_secret"
}
```

**OAuth URL**: `/datamachine-auth/facebook/`

### Threads

**Handler Slug**: `threads`

**Auth Type**: OAuth 2.0 (uses Facebook credentials)

**Configuration**:
```json
{
  "app_id": "your_app_id",
  "app_secret": "your_app_secret"
}
```

**OAuth URL**: `/datamachine-auth/threads/`

### Google Sheets

**Handler Slug**: `google-sheets`

**Auth Type**: OAuth 2.0

**Configuration**:
```json
{
  "client_id": "your_client_id",
  "client_secret": "your_client_secret"
}
```

**OAuth URL**: `/datamachine-auth/google-sheets/`

### Bluesky

**Handler Slug**: `bluesky`

**Auth Type**: App Password

**Configuration**:
```json
{
  "username": "user.bsky.social",
  "app_password": "your_app_password"
}
```

**No OAuth Flow**: Credentials used directly

## Integration Examples

### Python Authentication Management

```python
import requests
from requests.auth import HTTPBasicAuth

base_url = "https://example.com/wp-json/datamachine/v1/auth"
auth = HTTPBasicAuth("username", "application_password")

# Check authentication status
def check_auth_status(handler):
    url = f"{base_url}/{handler}/status"
    response = requests.get(url, auth=auth)
    return response.json()

# Save configuration
def save_auth_config(handler, config):
    url = f"{base_url}/{handler}"
    response = requests.put(url, json=config, auth=auth)
    return response.json()

# Disconnect account
def disconnect_account(handler):
    url = f"{base_url}/{handler}"
    response = requests.delete(url, auth=auth)
    return response.json()

# Usage
status = check_auth_status('twitter')
print(f"Twitter authenticated: {status['authenticated']}")

# Save Twitter config
config = {
    "consumer_key": "your_key",
    "consumer_secret": "your_secret"
}
result = save_auth_config('twitter', config)
print(f"Config saved: {result['success']}")
```

### JavaScript OAuth Management

```javascript
const axios = require('axios');

class AuthManager {
  constructor(baseURL, auth) {
    this.baseURL = `${baseURL}/auth`;
    this.auth = auth;
  }

  async checkStatus(handler) {
    const response = await axios.get(
      `${this.baseURL}/${handler}/status`,
      { auth: this.auth }
    );
    return response.data;
  }

  async saveConfig(handler, config) {
    const response = await axios.put(
      `${this.baseURL}/${handler}`,
      config,
      { auth: this.auth }
    );
    return response.data;
  }

  async disconnect(handler) {
    const response = await axios.delete(
      `${this.baseURL}/${handler}`,
      { auth: this.auth }
    );
    return response.data;
  }
}

// Usage
const authManager = new AuthManager(
  'https://example.com/wp-json/datamachine/v1',
  { username: 'admin', password: 'application_password' }
);

const status = await authManager.checkStatus('twitter');
console.log(`Authenticated: ${status.authenticated}`);

if (status.authenticated) {
  console.log(`Account: @${status.account_details.username}`);
}
```

## Common Workflows

### Setup Twitter Authentication

```bash
# 1. Save API keys
curl -X PUT https://example.com/wp-json/datamachine/v1/auth/twitter \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{
    "consumer_key": "your_consumer_key",
    "consumer_secret": "your_consumer_secret"
  }'

# 2. User completes OAuth at /datamachine-auth/twitter/

# 3. Check authentication status
curl https://example.com/wp-json/datamachine/v1/auth/twitter/status \
  -u username:application_password
```

### Disconnect and Reconnect

```bash
# Disconnect account
curl -X DELETE https://example.com/wp-json/datamachine/v1/auth/twitter \
  -u username:application_password

# Reconnect via OAuth flow
# User visits /datamachine-auth/twitter/
```

### Verify All Handler Authentication

```bash
# Check multiple handlers
for handler in twitter facebook reddit bluesky; do
  echo "Checking $handler..."
  curl -s https://example.com/wp-json/datamachine/v1/auth/$handler/status \
    -u username:application_password | jq '.authenticated'
done
```

## Related Documentation

- Handlers Endpoint - Handler information
- Settings Endpoints - Configuration management
- Authentication - API auth methods
- Errors - Error handling

---

**Base URL**: `/wp-json/datamachine/v1/auth`
**Permission**: `manage_options` capability required
**Implementation**: `inc/Api/Auth.php`
**OAuth URLs**: `/datamachine-auth/{handler}/`
