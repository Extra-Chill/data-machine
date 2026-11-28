# Files Endpoint

**Implementation**: `inc/Api/FlowFiles.php`, `inc/Abilities/File/AgentFileAbilities.php`, `inc/Abilities/DailyMemoryAbilities.php`

**Base URL**: `/wp-json/datamachine/v1/files`

## Overview

1. **Flow files** — File uploads for pipeline processing with flow-isolated storage, security validation, and automatic URL generation (`inc/Api/FlowFiles.php`). Multipart upload is a retained transport route.
2. **Agent files** — Agent memory file management for SOUL.md, MEMORY.md, USER.md, and daily memory journals, handled entirely by REST-visible abilities since #3456.

## Authentication

The flow-file upload route requires an authenticated user plus `PermissionHelper::can_manage()`. The agent-file abilities enforce the Data Machine management surface (`PermissionHelper::can_manage()`) and resolve the acting user from the input context (`user_id`, defaulting to the current user).

## Flow File Endpoints

### POST /files

Upload a file for flow processing (`flow_step_id`).

**Permission**: Logged-in user plus `PermissionHelper::can_manage()`.

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

### Listing and Deletion (Abilities)

The flow-file listing and deletion REST routes were retired in #3456. Use the REST-visible abilities through the core ability runner (both require `flow_step_id`):

```
POST /wp-json/wp-abilities/v1/abilities/datamachine/list-flow-files/run
{ "input": { "flow_step_id": "abc-123_42" } }

POST /wp-json/wp-abilities/v1/abilities/datamachine/delete-flow-file/run
{ "input": { "filename": "document_1234567890.pdf", "flow_step_id": "abc-123_42" } }
```

`list-flow-files` returns `{ "success": true, "files": [ ... ] }`; `delete-flow-file` returns `{ "success": true, "message": "..." }`.

## Agent File Abilities

**Implementation**: `inc/Abilities/File/AgentFileAbilities.php` (`datamachine-memory` category)

The `datamachine/v1/files/agent` REST routes were retired in #3456. Agent memory files are managed through REST-visible abilities executed through WordPress core's ability runner:

```
POST /wp-json/wp-abilities/v1/abilities/datamachine/<slug>/run
Content-Type: application/json

{ "input": { ... } }
```

The admin Agent page calls these through the shared `executeAbility()` client (`inc/Core/Admin/shared/utils/api.js`). Agent files use a layered directory resolution system:

1. **Shared layer** — Site-wide files like SITE.md
2. **Agent layer** — Agent-specific files: SOUL.md, MEMORY.md
3. **User layer** — User-specific files: USER.md

The `user_id` input (default `0`) controls which user context to resolve; `agent_id` selects a specific agent's layer.

| Ability slug | Purpose |
| --- | --- |
| `datamachine/list-agent-files` | List memory files from all layers, including the daily memory summary entry (`files`). |
| `datamachine/get-agent-file` | Read one file with content (`file`: `filename`, `size`, `modified`, `content`). |
| `datamachine/write-agent-file` | Write or update a file (`filename`, `content`, optional `layer`). Registry editability is enforced. |
| `datamachine/delete-agent-file` | Delete a file; protected files are rejected. |
| `datamachine/upload-agent-file` | Upload a file into a memory layer directory (`file_data`). |
| `datamachine/daily-memory-list` | List daily memory months (`months`). |
| `datamachine/daily-memory-read` | Read one day (`date` as `YYYY-MM-DD`) → `date`, `content`. |
| `datamachine/daily-memory-write` | Write or append (`date`, `content`, `mode`). Requires `daily_memory_enabled`. |
| `datamachine/daily-memory-delete` | Delete one day (`date`). Requires `daily_memory_enabled`. |
| `datamachine/search-daily-memory` | Full-text search across daily memory (`query`, optional date bounds). |

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
const abilityRunUrl =
  'https://example.com/wp-json/wp-abilities/v1/abilities/datamachine';

async function runAbility(slug, input) {
  const response = await fetch(`${abilityRunUrl}/${slug}/run`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': wpApiSettings.nonce,
    },
    body: JSON.stringify({ input }),
  });
  return response.json();
}

// Read MEMORY.md
async function readMemory() {
  const result = await runAbility('get-agent-file', { filename: 'MEMORY.md' });
  return result.file.content;
}

// Update MEMORY.md
async function updateMemory(content) {
  const result = await runAbility('write-agent-file', {
    filename: 'MEMORY.md',
    content,
  });
  return result.success;
}

// Read one day of daily memory
async function readDailyMemory(date) {
  const result = await runAbility('daily-memory-read', { date });
  return result.content;
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
- Flows Abilities - Flow management
- Handlers Abilities - Available handlers
- Authentication - Auth methods

---

**Base URL**: `/wp-json/datamachine/v1/files` (flow-file upload transport route only)
**Implementation**: `inc/Api/FlowFiles.php` (flow files), `inc/Abilities/File/AgentFileAbilities.php` + `inc/Abilities/DailyMemoryAbilities.php` (agent files)
**Max File Size**: WordPress `wp_max_upload_size()` setting
