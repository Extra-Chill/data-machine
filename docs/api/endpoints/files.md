# Files Endpoint

**Implementation**: `inc/Core/Steps/Fetch/Handlers/Files/Files.php`, `inc/Api/AgentFiles.php`

**Base URL**: `/wp-json/datamachine/v1/files`

## Overview

The Files endpoint handles two distinct scopes:

1. **Flow files** — File uploads for pipeline processing with flow-isolated storage, security validation, and automatic URL generation (`inc/Core/Steps/Fetch/Handlers/Files/Files.php`)
2. **Agent files** — Agent memory file management with 3-layer directory resolution for SOUL.md, MEMORY.md, USER.md, and daily memory journals (`inc/Api/AgentFiles.php`)

## Authentication

Requires authenticated user. Agent file endpoints use scoped permissions — users can access their own agent files, and users with `manage_agents` capability can access any agent's files.

## Flow File Endpoints

### POST /files

Upload a file for flow processing (`flow_step_id`).

**Permission**: `manage_options` capability required

**Parameters**:
- `flow_step_id` (string, optional): Flow step ID for flow-level files
- `file` (file, required): File to upload (multipart/form-data)

**File Restrictions**:
- **Maximum size**: Determined by WordPress `wp_max_upload_size()` setting (typically 2MB-128MB)
- **Blocked extensions**: php, exe, bat, js, sh, and other executable types
- **Security**: Path traversal protection and MIME type validation

**Example Request**:

```bash
# Flow scope
curl -X POST https://example.com/wp-json/datamachine/v1/files \
  -u username:application_password \
  -F "flow_step_id=abc-123_42" \
  -F "file=@/path/to/document.pdf"

```

**Success Response (201 Created)**:

```json
{
  "success": true,
  "data": {
    "filename": "document_1234567890.pdf",
    "size": 1048576,
    "modified": 1704153600,
    "url": "https://example.com/wp-content/uploads/datamachine-files/5/My%20Pipeline/42/document_1234567890.pdf"
  },
  "message": "File \"document.pdf\" uploaded successfully."
}
```

**Response Fields**:
- `success` (boolean): Request success status
- `data` (object): Uploaded file information
  - `filename` (string): Timestamped filename
  - `size` (integer): File size in bytes
  - `modified` (integer): Unix timestamp of upload
  - `url` (string): Public URL to access file
- `message` (string): Success confirmation

### GET /files

List files in a flow scope (`flow_step_id`).

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": [
    {
      "name": "document_1234567890.pdf",
      "size": 1048576,
      "modified": 1704153600,
      "url": "https://example.com/wp-content/uploads/datamachine-files/.../document_1234567890.pdf"
    }
  ]
}
```

### GET /files/{filename}

Download a file by filename.

**Permission**: `manage_options` capability required

**Parameters**:
- `filename` (string, required): File to retrieve
- `pipeline_id` (integer, optional): Filter by pipeline
- `flow_id` (integer, optional): Filter by flow
- `flow_step_id` (string, optional): Filter by flow step

**Response**: Returns the file content directly.

### DELETE /files/{filename}

Delete a file by filename.

**Permission**: `manage_options` capability required

**Parameters**:
- `filename` (string, required): File to delete
- `pipeline_id` (integer, optional): Filter by pipeline
- `flow_id` (integer, optional): Filter by flow
- `flow_step_id` (string, optional): Filter by flow step

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": {
    "deleted": true,
    "filename": "document_1234567890.pdf"
  }
}
```

## Agent File Endpoints

**Implementation**: `inc/Api/AgentFiles.php` (@since v0.38.0)

Agent files use a 3-layer directory resolution system:
1. **Shared layer** (`inc/Core/Admin/Pages/Pipelines/assets/react/components/shared/`) — Site-wide files like SITE.md
2. **Agent layer** (`agents/{slug}/`) — Agent-specific files: SOUL.md, MEMORY.md
3. **User layer** (`users/{id}/`) — User-specific files: USER.md

The `user_id` parameter controls which user context to resolve. Defaults to the current authenticated user. Users with `manage_agents` capability can access other users' files.

### GET /files/agent

List agent files for the current user context.

**Permission**: Authenticated user (own files) or `manage_agents` capability (other users)

**Parameters**:
- `user_id` (integer, optional): WordPress user ID for layered context resolution

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": [
    {
      "filename": "SOUL.md",
      "size": 2048,
      "modified": 1704153600,
      "layer": "agent"
    },
    {
      "filename": "MEMORY.md",
      "size": 4096,
      "modified": 1704240000,
      "layer": "agent"
    }
  ]
}
```

### GET /files/agent/{filename}

Read an agent memory file (SOUL.md, MEMORY.md, USER.md, etc.).

**Permission**: Authenticated user (own files) or `manage_agents` capability

**Parameters**:
- `filename` (string, required): Agent memory file (e.g., `SOUL.md`, `MEMORY.md`)
- `user_id` (integer, optional): WordPress user ID for context

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": {
    "filename": "MEMORY.md",
    "content": "# Agent Memory\n\n## State\n...",
    "size": 4096,
    "modified": 1704240000
  }
}
```

### PUT /files/agent/{filename}

Write/update an agent memory file.

**Permission**: Authenticated user (own files) or `manage_agents` capability

**Parameters**:
- `filename` (string, required): Agent memory file
- `content` (string, required): New file content
- `user_id` (integer, optional): WordPress user ID for context

**Example**:
```bash
curl -X PUT https://example.com/wp-json/datamachine/v1/files/agent/MEMORY.md \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"content": "# Agent Memory\n\nI know how to..."}'
```

### DELETE /files/agent/{filename}

Delete an agent file.

**Permission**: Authenticated user (own files) or `manage_agents` capability

**Parameters**:
- `filename` (string, required): Agent file to delete
- `user_id` (integer, optional): WordPress user ID for context

## Daily Memory Endpoints

**Implementation**: `inc/Api/AgentFiles.php` (lines 121-195)

Daily memory files store temporal session logs at `daily/YYYY/MM/DD.md`. These endpoints manage the daily memory journal separate from persistent memory files.

### GET /files/agent/daily

List all daily memory files for the agent.

**Permission**: Authenticated user (own files) or `manage_agents` capability

**Parameters**:
- `user_id` (integer, optional): WordPress user ID for context

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": [
    {
      "date": "2026-03-15",
      "size": 1024,
      "path": "daily/2026/03/15.md"
    },
    {
      "date": "2026-03-14",
      "size": 2048,
      "path": "daily/2026/03/14.md"
    }
  ]
}
```

### GET /files/agent/daily/{year}/{month}/{day}

Read a specific daily memory file.

**Permission**: Authenticated user (own files) or `manage_agents` capability

**Parameters**:
- `year` (string, required): 4-digit year (e.g., `2026`)
- `month` (string, required): 2-digit month (`01`-`12`)
- `day` (string, required): 2-digit day (`01`-`31`)
- `user_id` (integer, optional): WordPress user ID for context

**Example Request**:
```bash
curl https://example.com/wp-json/datamachine/v1/files/agent/daily/2026/03/15 \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "data": {
    "date": "2026-03-15",
    "content": "# Daily Memory — 2026-03-15\n\n## Session Activity\n...",
    "size": 1024
  }
}
```

### PUT /files/agent/daily/{year}/{month}/{day}

Write or update a daily memory file.

**Permission**: Authenticated user (own files) or `manage_agents` capability

**Parameters**:
- `year` (string, required): 4-digit year
- `month` (string, required): 2-digit month
- `day` (string, required): 2-digit day
- `content` (string, required): File content
- `user_id` (integer, optional): WordPress user ID for context

**Example Request**:
```bash
curl -X PUT https://example.com/wp-json/datamachine/v1/files/agent/daily/2026/03/15 \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"content": "# Daily Memory — 2026-03-15\n\n## Session Activity\nUpdated docs..."}'
```

### DELETE /files/agent/daily/{year}/{month}/{day}

Delete a daily memory file.

**Permission**: Authenticated user (own files) or `manage_agents` capability

**Parameters**:
- `year` (string, required): 4-digit year
- `month` (string, required): 2-digit month
- `day` (string, required): 2-digit day
- `user_id` (integer, optional): WordPress user ID for context

## Error Responses

### 400 Bad Request - File Too Large

```json
{
  "code": "file_validation_failed",
  "message": "File too large: 50 MB. Maximum allowed size: 32 MB",
  "data": {"status": 400}
}
```

### 400 Bad Request - Invalid File Type

```json
{
  "code": "file_validation_failed",
  "message": "File type not allowed for security reasons.",
  "data": {"status": 400}
}
```

### 400 Bad Request - Missing Scope

```json
{
  "code": "missing_scope",
  "message": "Must provide either flow_step_id or scope=agent.",
  "data": {"status": 400}
}
```

### 400 Bad Request - Conflicting Scope

```json
{
  "code": "conflicting_scope",
  "message": "Invalid request.",
  "data": {"status": 400}
}
```

### 400 Bad Request - Missing File

```json
{
  "code": "missing_file",
  "message": "File upload is required.",
  "data": {"status": 400}
}
```

### 401 Unauthorized - Not Logged In

```json
{
  "code": "rest_forbidden",
  "message": "You must be logged in to manage files.",
  "data": {"status": 401}
}
```

## File Storage

### Flow File Directory Structure

Files are stored under the `datamachine-files` uploads directory.

- **Flow scope**: files are grouped by pipeline + flow.

See [FilesRepository](../../core-system/files-repository.md) for the current directory structure.

```
wp-content/uploads/datamachine-files/
└── {flow_step_id}/
    ├── document_1234567890.pdf
    ├── image_1234567891.jpg
    └── data_1234567892.csv
```

### Agent File Directory Structure

Agent files use the Data Machine layered directory system:

```
wp-content/uploads/datamachine/
├── shared/
│   └── SITE.md
├── agents/
│   └── {agent-slug}/
│       ├── SOUL.md
│       ├── MEMORY.md
│       └── daily/
│           └── 2026/
│               └── 03/
│                   ├── 14.md
│                   └── 15.md
└── users/
    └── {user-id}/
        └── USER.md
```

### Filename Format

Uploaded flow files are automatically timestamped to prevent collisions:

```
{original_name}_{unix_timestamp}.{extension}
```

**Example**: `report.pdf` → `report_1704153600.pdf`

### Access Control

- **Flow files**: Stored in publicly accessible directories, organized by flow step ID for isolation
- **Agent files**: Access controlled via WordPress user permissions and scoped agent resolution

## Security Features

### Blocked File Types

The following file extensions are blocked for security:

- Executables: `exe`, `bat`, `sh`, `cmd`, `com`
- Scripts: `php`, `phtml`, `php3`, `php4`, `php5`, `phps`
- Web: `js`, `jsp`, `asp`, `aspx`
- Archives with code: `jar`
- Config: `htaccess`

### MIME Type Validation

Server validates MIME types to prevent file type spoofing:

```php
// Allowed MIME types (examples)
- application/pdf
- image/jpeg, image/png, image/gif
- text/csv
- application/json
- etc.
```

### Path Traversal Protection

File paths are sanitized to prevent directory traversal attacks:

```php
// Blocked patterns
- ../
- ..\\
- Absolute paths
```

## Integration Examples

### Python File Upload

```python
import requests
from requests.auth import HTTPBasicAuth

url = "https://example.com/wp-json/datamachine/v1/files"
auth = HTTPBasicAuth("username", "application_password")

# Upload file
with open('/path/to/document.pdf', 'rb') as f:
    files = {'file': f}
    data = {'flow_step_id': 'abc-123_42'}

    response = requests.post(url, files=files, data=data, auth=auth)

if response.status_code == 201:
    result = response.json()
    print(f"File uploaded: {result['data']['url']}")
else:
    print(f"Upload failed: {response.json()['message']}")
```

### JavaScript Agent Memory Access

```javascript
const axios = require('axios');

const agentFilesAPI = {
  baseURL: 'https://example.com/wp-json/datamachine/v1/files/agent',
  auth: { username: 'admin', password: 'application_password' }
};

// Read MEMORY.md
async function readMemory() {
  const response = await axios.get(`${agentFilesAPI.baseURL}/MEMORY.md`, {
    auth: agentFilesAPI.auth
  });
  return response.data.data.content;
}

// Update MEMORY.md
async function updateMemory(content) {
  const response = await axios.put(`${agentFilesAPI.baseURL}/MEMORY.md`, 
    { content },
    { auth: agentFilesAPI.auth }
  );
  return response.data.success;
}

// Read today's daily memory
async function readDailyMemory(year, month, day) {
  const response = await axios.get(
    `${agentFilesAPI.baseURL}/daily/${year}/${month}/${day}`,
    { auth: agentFilesAPI.auth }
  );
  return response.data.data.content;
}
```

### cURL with Form Data

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/files \
  -u username:application_password \
  -F "flow_step_id=abc-123_42" \
  -F "file=@/Users/username/Documents/report.pdf"
```

## File Lifecycle

1. **Upload**: File uploaded via REST API
2. **Validation**: Size, type, and security checks
3. **Storage**: Saved to flow-isolated directory
4. **Processing**: Fetch handler accesses file via URL
5. **Cleanup**: Manual cleanup or automated via flow deletion

## Related Documentation

- Execute Endpoint - Workflow execution
- Flows Endpoints - Flow management
- Handlers Endpoint - Available handlers
- Authentication - Auth methods

---

**Base URL**: `/wp-json/datamachine/v1/files`
**Implementation**: `inc/Core/Steps/Fetch/Handlers/Files/Files.php` (flow files), `inc/Api/AgentFiles.php` (agent files)
**Max File Size**: WordPress `wp_max_upload_size()` setting
