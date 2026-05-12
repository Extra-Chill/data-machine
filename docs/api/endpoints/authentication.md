# Authentication

Data Machine REST API requests use normal WordPress REST authentication. The separate [Auth Endpoints](auth.md) page documents handler/provider credentials such as OAuth tokens.

## Authentication Methods

This page describes authentication options, but it does not replace WordPress’s own authentication documentation.

### 1. Application Password (Recommended)

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

## Permission Model

### Data Machine Permissions

Endpoints check Data Machine permissions through `PermissionHelper`, not a single hardcoded WordPress capability:

- `manage_flows`: pipelines, flows, jobs, execution-related administration.
- `manage_settings`: settings and handler auth management.
- `chat`: chat message/session routes.
- Public read routes exist for discovery-style surfaces such as providers, tools, and step types.

### Authenticated Users

Some endpoints require authentication only (any logged-in user):
- `/users/me` - Current user preferences

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
- Verify the user or scoped agent has the endpoint's Data Machine permission
- Check application password is correct
- Ensure WordPress user is active
- Confirm HTTPS is being used

## Related Documentation

- Execute Endpoint - Workflow execution
- Auth Endpoints - OAuth account management
- Errors - Authentication error codes
- API Overview - Complete API documentation

---

**Security Model**: Endpoint-specific Data Machine permissions via `PermissionHelper`
**Supported Methods**: Application Password, Cookie Authentication
**WordPress Version**: 5.6+ (Application Passwords)
