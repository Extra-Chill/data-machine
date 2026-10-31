# Authenticate Handler Tool

**Implementation**: `inc/Api/Chat/Tools/AuthenticateHandler.php`

## Overview

The Authenticate Handler tool manages authentication flows via natural language, allowing users to list status, configure credentials, and retrieve OAuth URLs for handlers. It provides a conversational interface for managing connections to external services like Twitter, Facebook, Reddit, and Google Sheets.

## Actions

The tool supports the following actions:

### list
Lists all handlers requiring authentication and their current status.

**Parameters**:
- None

**Returns**:
- List of handlers with auth status, auth type, and account details (if authenticated).

### status
Get detailed status and configuration requirements for a specific handler.

**Parameters**:
- `handler_slug` (string, required): The handler identifier.

**Returns**:
- Detailed status including configuration state, authentication state, and required configuration fields.

### configure
Save credentials (OAuth keys or simple auth user/pass).

**Security Warning**: Credentials provided here are visible in chat logs.

**Parameters**:
- `handler_slug` (string, required): The handler identifier.
- `credentials` (object, required): Credentials object.
  - For OAuth: `{client_id, client_secret}` or `{consumer_key, consumer_secret}`
  - For simple auth: handler-specific fields (e.g., `{username, app_password}`)

### get_oauth_url
Get the authorization URL for OAuth providers.

**Parameters**:
- `handler_slug` (string, required): The handler identifier.

**Returns**:
- OAuth authorization URL and instructions.

### disconnect
Remove authentication and credentials for a handler.

**Parameters**:
- `handler_slug` (string, required): The handler identifier.

## Usage Examples

### List Authentication Status

```json
{
  "action": "list"
}
```

### Check Handler Status

```json
{
  "action": "status",
  "handler_slug": "twitter"
}
```

### Configure Credentials

```json
{
  "action": "configure",
  "handler_slug": "twitter",
  "credentials": {
    "consumer_key": "your_consumer_key",
    "consumer_secret": "your_consumer_secret"
  }
}
```

### Get OAuth URL

```json
{
  "action": "get_oauth_url",
  "handler_slug": "twitter"
}
```

### Disconnect Handler

```json
{
  "action": "disconnect",
  "handler_slug": "twitter"
}
```

## Integration

The tool integrates with:
- **Auth Providers**: Uses the `datamachine_auth_providers` filter to access authentication instances.
- **REST API**: Mirrors functionality available via the `/datamachine/v1/auth` endpoints.
- **Tool Manager**: Extends `BaseTool` for discovery by the AI engine.
