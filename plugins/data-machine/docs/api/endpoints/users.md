# Users Endpoints

**Implementation**: `inc/Api/Users.php`

**Base URL**: `/wp-json/datamachine/v1/users`

## Overview

Users endpoints manage user-specific preferences for Data Machine, including selected pipeline preferences for the admin interface.

## Authentication

User-specific permissions apply. See Authentication Guide.

## Endpoints

### GET /users/{id}

Get user preferences for a specific user.

**Permission**: User must be logged in AND (has `manage_options` OR is the target user)

**Parameters**:
- `id` (integer, required): User ID (in URL path)

**Example Request**:

```bash
curl https://example.com/wp-json/datamachine/v1/users/5 \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "user_id": 5,
  "selected_pipeline_id": 42
}
```

**Response Fields**:
- `success` (boolean): Request success status
- `user_id` (integer): User ID
- `selected_pipeline_id` (integer|null): Currently selected pipeline ID, or null if none selected

**Error Response (403 Forbidden)**:

```json
{
  "code": "rest_forbidden",
  "message": "You do not have permission to view this user's preferences.",
  "data": {"status": 403}
}
```

### POST /users/{id}

Update user preferences for a specific user.

**Permission**: User must be logged in AND (has `manage_options` OR is the target user)

**Parameters**:
- `id` (integer, required): User ID (in URL path)
- `selected_pipeline_id` (integer|null, optional): Pipeline ID preference (null to clear)

**Example Requests**:

```bash
# Set preference
curl -X POST https://example.com/wp-json/datamachine/v1/users/5 \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"selected_pipeline_id": 42}'

# Clear preference
curl -X POST https://example.com/wp-json/datamachine/v1/users/5 \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"selected_pipeline_id": null}'
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "user_id": 5,
  "selected_pipeline_id": 42
}
```

**Error Response (404 Not Found)**:

```json
{
  "code": "pipeline_not_found",
  "message": "Pipeline not found.",
  "data": {"status": 404}
}
```

### GET /users/me

Get preferences for the currently logged-in user.

**Permission**: User must be logged in (any authenticated user)

**Example Request**:

```bash
curl https://example.com/wp-json/datamachine/v1/users/me \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "user_id": 5,
  "selected_pipeline_id": 42
}
```

### POST /users/me

Update preferences for the currently logged-in user.

**Permission**: User must be logged in (any authenticated user)

**Parameters**:
- `selected_pipeline_id` (integer|null, optional): Pipeline ID preference

**Example Request**:

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/users/me \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"selected_pipeline_id": 42}'
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "user_id": 5,
  "selected_pipeline_id": 42
}
```

## User Preferences

### Selected Pipeline

The `selected_pipeline_id` preference stores the user's currently active pipeline in the admin interface. This preference:

- Persists across sessions
- Automatically loads the selected pipeline in the admin UI
- Can be set to `null` to clear selection
- Validates pipeline existence before saving

**Storage**: WordPress user meta (`datamachine_selected_pipeline_id`)

## Permission Model

### Admin Users

Users with `manage_options` capability can:
- View any user's preferences
- Update any user's preferences

### Regular Users

Regular authenticated users can:
- View their own preferences
- Update their own preferences
- Cannot access other users' preferences

## Integration Examples

### Python User Preferences

```python
import requests
from requests.auth import HTTPBasicAuth

url = "https://example.com/wp-json/datamachine/v1/users/me"
auth = HTTPBasicAuth("username", "application_password")

# Get current preferences
response = requests.get(url, auth=auth)
current_pipeline = response.json()['selected_pipeline_id']

print(f"Current pipeline: {current_pipeline}")

# Update preference
update_response = requests.post(
    url,
    json={'selected_pipeline_id': 42},
    auth=auth
)

if update_response.status_code == 200:
    print(f"Pipeline preference updated to {42}")
```

### JavaScript User Preferences

```javascript
const axios = require('axios');

const userAPI = {
  baseURL: 'https://example.com/wp-json/datamachine/v1/users/me',
  auth: {
    username: 'admin',
    password: 'application_password'
  }
};

// Get current user preferences
async function getCurrentPipeline() {
  const response = await axios.get(userAPI.baseURL, {
    auth: userAPI.auth
  });

  return response.data.selected_pipeline_id;
}

// Update pipeline preference
async function setPipeline(pipelineId) {
  const response = await axios.post(
    userAPI.baseURL,
    { selected_pipeline_id: pipelineId },
    { auth: userAPI.auth }
  );

  return response.data.success;
}

// Usage
const currentPipeline = await getCurrentPipeline();
console.log(`Current: Pipeline ${currentPipeline}`);

await setPipeline(42);
console.log('Pipeline preference updated');
```

## Common Workflows

### Save Admin Interface State

```bash
# User selects pipeline in admin interface
curl -X POST https://example.com/wp-json/datamachine/v1/users/me \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"selected_pipeline_id": 42}'
```

### Restore Admin Interface State

```bash
# Load user's preferred pipeline on page load
curl https://example.com/wp-json/datamachine/v1/users/me \
  -u username:application_password
```

### Clear Pipeline Selection

```bash
# Reset to no selection
curl -X POST https://example.com/wp-json/datamachine/v1/users/me \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"selected_pipeline_id": null}'
```

## Use Cases

### Admin Interface Persistence

Store and restore user's active pipeline selection across sessions for seamless admin experience.

### Multi-User Environments

Each user maintains independent pipeline preferences without affecting other users.

### Pipeline Navigation

Track user's navigation history and preferred workflows for improved UX.

## Related Documentation

- Pipelines Endpoints - Pipeline management
- Authentication - Auth methods
- Settings Endpoints - Global configuration

---

**Base URL**: `/wp-json/datamachine/v1/users`
**Permission**: Varies by endpoint (see individual endpoints)
**Implementation**: `inc/Api/Users.php`
