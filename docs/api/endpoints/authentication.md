# Authentication

Data Machine REST API requests use normal WordPress REST authentication. Endpoint-specific callback routes add bearer or HMAC verification where a logged-in WordPress user does not fit the integration. The separate [Auth Endpoints](auth.md) page documents handler/provider credentials such as OAuth tokens.

## Authentication Methods

This page describes authentication options, but it does not replace WordPress’s own authentication documentation.

### 1. Application Password

**Best For**: External integrations, non-browser applications, API clients

**Setup**:
1. Navigate to WordPress Admin → Users → Your Profile
2. Scroll to "Application Passwords" section
3. Enter application name (e.g., "Data Machine API")
4. Click "Add New Application Password"
5. Copy the generated password (format: `xxxx xxxx xxxx xxxx`)

**Usage**:
```bash
curl -u username:application_password \
  https://example.com/wp-json/datamachine/v1/pipelines
```

**Python Example**:
```python
import requests
from requests.auth import HTTPBasicAuth

url = "https://example.com/wp-json/datamachine/v1/pipelines"
auth = HTTPBasicAuth("username", "xxxx xxxx xxxx xxxx")

response = requests.get(url, auth=auth)
```

**JavaScript/Node.js Example**:
```javascript
const axios = require('axios');

const response = await axios.get(
  'https://example.com/wp-json/datamachine/v1/pipelines',
  {
    auth: {
      username: 'admin',
      password: 'xxxx xxxx xxxx xxxx'
    }
  }
);
```

### 2. Cookie Authentication

**Best For**: WordPress admin interface, same-origin requests

**Setup**: Automatic for logged-in WordPress users

**Usage**:
```javascript
// WordPress admin context
fetch('/wp-json/datamachine/v1/pipelines', {
  credentials: 'same-origin',
  headers: {
    'X-WP-Nonce': wpApiSettings.nonce
  }
})
```

### 3. Bearer or HMAC Callback Authentication

Some routes are intentionally public at the WordPress REST layer and authenticate inside the callback:

- `/trigger/{flow_id}` validates per-flow bearer tokens or HMAC signatures before executing the flow.
- `/agent-ping/*` validates `Authorization: Bearer <token>` against the configured callback token.
- Agent bearer tokens populate `PermissionHelper` agent context for routes such as `/agents/me`.

## Permission Model

### Data Machine Permissions

Endpoints check Data Machine permissions through `PermissionHelper`, not a single hardcoded WordPress capability. `manage_options` is an administrator fallback, not the canonical permission name for most endpoints.

| Scoped action | Concrete WordPress capability | Common use |
|---------------|------------------------------|------------|
| `manage_agents` | `datamachine_manage_agents` | Agent CRUD, access grants, tokens, cross-user agent files. |
| `manage_flows` | `datamachine_manage_flows` | Flow, pipeline, jobs, analytics, internal link tools, and workflow operations. |
| `manage_settings` | `datamachine_manage_settings` | Settings and system operations. |
| `chat` | `datamachine_chat` | Chat access where enforced by the chat layer. |
| `use_tools` | `datamachine_use_tools` | Tool execution where enforced by the tool layer. |
| `view_logs` | `datamachine_view_logs` | Log access where enforced by the logs layer. |
| `create_own_agent` | `datamachine_create_own_agent` | Self-service agent creation. |

Public read routes exist for discovery-style surfaces such as providers, tools, and step types.

`PermissionHelper::can_manage()` passes when the caller has `manage_flows`, `manage_settings`, or `manage_agents`.

### Authenticated Users and Scoped Resources

Some routes accept any logged-in user and then scope data by the current user or agent context:

- `/agents` lists agents visible to the current user.
- `/agents/me` returns the active agent or user's default agent.
- `/files/agent/*` allows a user to access their own files; `manage_agents` is required to access another user's files.
- `/users/me` returns current-user preferences.

## Security Best Practices

1. **Use HTTPS**: Always use HTTPS in production
2. **Rotate Passwords**: Regularly rotate application passwords
3. **Limit Scope**: Create application-specific passwords
4. **Monitor Access**: Review application password usage in WordPress admin
5. **Revoke Unused**: Delete application passwords for deactivated integrations

## Testing Authentication

```bash
# Test WordPress REST API discovery endpoint
curl -u username:app_password \
  https://example.com/wp-json/

# Test Data Machine authentication
curl -u username:app_password \
  https://example.com/wp-json/datamachine/v1/pipelines
```

## Authentication errors

**403 Forbidden**:
```json
{
  "code": "rest_forbidden",
  "message": "You do not have permission to access this endpoint.",
  "data": {"status": 403}
}
```

**Solutions**:
- Verify the user or scoped agent has the endpoint's Data Machine permission.
- Check application password is correct
- Ensure WordPress user is active
- Confirm HTTPS is being used

## Related Documentation

- [Execute Endpoint](execute.md) - Workflow execution
- [Auth Endpoints](auth.md) - OAuth account management
- [Webhook Triggers](webhook-triggers.md) - Bearer/HMAC callback auth
- [Errors](errors.md) - Authentication error codes
- [API Overview](../index.md) - Complete API documentation

---

**Security Model**: Scoped Data Machine capabilities via `PermissionHelper`
**Supported Methods**: Application Password, Cookie Authentication, Bearer/HMAC callback auth
**WordPress Version**: 5.6+ (Application Passwords)
