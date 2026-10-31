# Error Handling

Complete error reference for Data Machine REST API.

## Standard Error Format

All errors follow WordPress REST API error format:

```json
{
  "code": "error_code",
  "message": "Human-readable error description",
  "data": {
    "status": 400
  }
}
```

**Error Response Fields**:
- `code` (string): Machine-readable error identifier
- `message` (string): Human-readable error description
- `data` (object): Additional error metadata
  - `status` (integer): HTTP status code
  - Additional context (optional)

## HTTP Status Codes

### 200 OK

Successful request with valid response data.

```json
{
  "success": true,
  "data": {...}
}
```

### 201 Created

Resource created successfully (file uploads).

```json
{
  "success": true,
  "file_info": {...},
  "message": "File uploaded successfully."
}
```

### 400 Bad Request

Invalid parameters or validation failure.

```json
{
  "code": "rest_invalid_param",
  "message": "Invalid parameter(s): flow_id",
  "data": {
    "status": 400,
    "params": {
      "flow_id": "flow_id must be a positive integer"
    }
  }
}
```

### 403 Forbidden

Insufficient permissions or access denied.

```json
{
  "code": "rest_forbidden",
  "message": "You do not have permission to access this endpoint.",
  "data": {"status": 403}
}
```

### 404 Not Found

Resource not found.

```json
{
  "code": "invalid_flow",
  "message": "Flow not found.",
  "data": {"status": 404}
}
```

### 500 Internal Server Error

Server-side operation failure.

```json
{
  "code": "import_failed",
  "message": "Failed to import pipelines from CSV.",
  "data": {"status": 500}
}
```

## Authentication Errors

### rest_forbidden

**Status**: 403 Forbidden

**Cause**: User lacks required capability or access denied

**Common Scenarios**:
- User lacks `manage_options` capability
- Accessing another user's session
- Invalid authentication credentials

**Example**:
```json
{
  "code": "rest_forbidden",
  "message": "You do not have permission to trigger flows.",
  "data": {"status": 403}
}
```

**Solution**:
- Verify user has `manage_options` capability
- Check application password is correct
- Ensure WordPress user is active

### session_access_denied

**Status**: 403 Forbidden

**Cause**: Attempting to access another user's chat session

**Example**:
```json
{
  "code": "session_access_denied",
  "message": "Access denied to this session",
  "data": {"status": 403}
}
```

**Solution**:
- Use correct session_id for current user
- Create new session if previous session expired

## Resource Errors

### invalid_flow

**Status**: 404 Not Found

**Cause**: Flow ID not found in database

**Example**:
```json
{
  "code": "invalid_flow",
  "message": "Flow not found.",
  "data": {"status": 404}
}
```

**Solution**:
- Verify flow ID exists in WordPress admin
- Check flow hasn't been deleted
- Confirm database connection

### pipeline_not_found

**Status**: 404 Not Found

**Cause**: Pipeline ID not found in database

**Example**:
```json
{
  "code": "pipeline_not_found",
  "message": "Pipeline not found.",
  "data": {"status": 404}
}
```

**Solution**:
- Verify pipeline ID exists
- Check pipeline hasn't been deleted

### auth_provider_not_found

**Status**: 404 Not Found

**Cause**: Authentication provider not found

**Example**:
```json
{
  "code": "auth_provider_not_found",
  "message": "Authentication provider not found",
  "data": {"status": 404}
}
```

**Solution**:
- Verify handler slug is correct
- Check handler supports authentication

### log_file_not_found

**Status**: 404 Not Found

**Cause**: Log file does not exist

**Example**:
```json
{
  "code": "log_file_not_found",
  "message": "Log file does not exist.",
  "data": {"status": 404}
}
```

**Solution**:
- Execute workflows to generate logs
- Check file permissions
- Verify upload directory is writable

### session_not_found

**Status**: 404 Not Found

**Cause**: Chat session not found or expired

**Example**:
```json
{
  "code": "session_not_found",
  "message": "Session not found or expired",
  "data": {"status": 404}
}
```

**Solution**:
- Create new session (omit session_id parameter)
- Check session hasn't expired (24-hour limit)

### processed_item_not_found

**Status**: 404 Not Found

**Cause**: Processed item record not found

**Example**:
```json
{
  "code": "processed_item_not_found",
  "message": "Processed item not found.",
  "data": {"status": 404}
}
```

**Solution**:
- Verify processed item ID exists
- Check item hasn't been deleted

## Validation Errors

### rest_invalid_param

**Status**: 400 Bad Request

**Cause**: Invalid or missing required parameters

**Example**:
```json
{
  "code": "rest_invalid_param",
  "message": "Invalid parameter(s): flow_id",
  "data": {
    "status": 400,
    "params": {
      "flow_id": "flow_id must be a positive integer"
    }
  }
}
```

**Solution**:
- Verify parameter types match requirements
- Ensure all required parameters are provided
- Check parameter value constraints

### file_validation_failed

**Status**: 400 Bad Request

**Cause**: File upload validation failed (size, type)

**Example (File Too Large)**:
```json
{
  "code": "file_validation_failed",
  "message": "File too large: 50 MB. Maximum allowed size: 32 MB",
  "data": {"status": 400}
}
```

**Example (Invalid File Type)**:
```json
{
  "code": "file_validation_failed",
  "message": "File type not allowed for security reasons.",
  "data": {"status": 400}
}
```

**Solution**:
- Reduce file size below WordPress upload limit (check `wp_max_upload_size()`)
- Use allowed file types (not php, exe, bat, js, sh)
- Check MIME type is valid

### required_field_missing

**Status**: 400 Bad Request

**Cause**: Required configuration field missing

**Example**:
```json
{
  "code": "required_field_missing",
  "message": "API Key is required",
  "data": {"status": 400}
}
```

**Solution**:
- Provide all required configuration fields
- Check field names match requirements

### invalid_config_data

**Status**: 400 Bad Request

**Cause**: Configuration data format invalid

**Example**:
```json
{
  "code": "invalid_config_data",
  "message": "Valid configuration data is required.",
  "data": {"status": 400}
}
```

**Solution**:
- Verify JSON format is correct
- Ensure config_data is an object
- Provide at least one configuration field

### invalid_step_order

**Status**: 400 Bad Request

**Cause**: Invalid step order data provided

**Example**:
```json
{
  "code": "invalid_step_order",
  "message": "Invalid step order data provided.",
  "data": {"status": 400}
}
```

**Solution**:
- Ensure step_order is an array
- Verify each item has pipeline_step_id and execution_order
- Check execution_order values are sequential

### invalid_log_level

**Status**: 400 Bad Request

**Cause**: Invalid log level specified

**Example**:
```json
{
  "code": "invalid_log_level",
  "message": "Invalid log level. Must be one of: debug, info, warning, error",
  "data": {"status": 400}
}
```

**Solution**:
- Use valid log level: `debug`, `info`, `warning`, or `error`

### invalid_clear_type

**Status**: 400 Bad Request

**Cause**: Invalid clear type for processed items

**Example**:
```json
{
  "code": "invalid_clear_type",
  "message": "Invalid clear type. Must be 'pipeline' or 'flow'.",
  "data": {"status": 400}
}
```

**Solution**:
- Use `pipeline` or `flow` for clear_type
- Provide corresponding target_id

### missing_required_param

**Status**: 400 Bad Request

**Cause**: Required parameter not provided

**Example**:
```json
{
  "code": "missing_required_param",
  "message": "Missing required parameter: flow_step_id",
  "data": {"status": 400}
}
```

**Solution**:
- Include all required parameters in request
- Check parameter names are correct

### no_file_uploaded

**Status**: 400 Bad Request

**Cause**: No file provided in multipart upload

**Example**:
```json
{
  "code": "no_file_uploaded",
  "message": "No file was uploaded.",
  "data": {"status": 400}
}
```

**Solution**:
- Include file in multipart/form-data request
- Verify form field name is `file`

## Operation Errors

### import_failed

**Status**: 500 Internal Server Error

**Cause**: Pipeline import operation failed

**Example**:
```json
{
  "code": "import_failed",
  "message": "Failed to import pipelines from CSV.",
  "data": {"status": 500}
}
```

**Solution**:
- Verify CSV format is correct
- Check for malformed JSON in CSV cells
- Review server error logs

### disconnect_failed

**Status**: 500 Internal Server Error

**Cause**: Account disconnection failed

**Example**:
```json
{
  "code": "disconnect_failed",
  "message": "Failed to disconnect account",
  "data": {"status": 500}
}
```

**Solution**:
- Check database connection
- Review server error logs
- Retry operation

### no_tool_handler

**Status**: 500 Internal Server Error

**Cause**: No configuration handler for specified tool

**Example**:
```json
{
  "code": "no_tool_handler",
  "message": "No configuration handler found for tool: invalid_tool",
  "data": {"status": 500}
}
```

**Solution**:
- Verify tool ID is correct
- Ensure tool supports configuration
- Check tool is registered

### database_unavailable

**Status**: 500 Internal Server Error

**Cause**: Database service unavailable

**Example**:
```json
{
  "code": "database_unavailable",
  "message": "Database service unavailable",
  "data": {"status": 500}
}
```

**Solution**:
- Check database connection
- Verify WordPress database configuration
- Review server error logs

### log_file_read_error

**Status**: 500 Internal Server Error

**Cause**: Unable to read log file

**Example**:
```json
{
  "code": "log_file_read_error",
  "message": "Unable to read log file",
  "data": {"status": 500}
}
```

**Solution**:
- Check file permissions
- Verify upload directory is readable
- Review server error logs

## Error Handling Best Practices

### Client-Side Handling

```javascript
try {
  const response = await fetch('/wp-json/wp-abilities/v1/abilities/datamachine/create-flow/run', {
    method: 'POST',
    headers: {
      'Authorization': 'Basic ' + btoa('username:app_password'),
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({input: {pipeline_id: 5}})
  });

  const data = await response.json();

  if (!response.ok) {
    // Handle error
    console.error(`Error ${data.code}: ${data.message}`);
    return;
  }

  // Handle success
  console.log('Flow created:', data.flow_id);
} catch (error) {
  console.error('Network error:', error);
}
```

### Python Error Handling

```python
import requests
from requests.auth import HTTPBasicAuth

url = "https://example.com/wp-json/wp-abilities/v1/abilities/datamachine/create-flow/run"
auth = HTTPBasicAuth("username", "application_password")

try:
    response = requests.post(url, json={"input": {"pipeline_id": 5}}, auth=auth)
    response.raise_for_status()  # Raises HTTPError for bad status

    data = response.json()
    print(f"Flow created: {data['flow_id']}")

except requests.exceptions.HTTPError as err:
    error_data = err.response.json()
    print(f"HTTP Error: {error_data['code']} - {error_data['message']}")

except requests.exceptions.RequestException as err:
    print(f"Request failed: {err}")
```

### Error Recovery Strategies

**Retry Logic**:
```javascript
async function retryRequest(fn, maxRetries = 3) {
  for (let i = 0; i < maxRetries; i++) {
    try {
      return await fn();
    } catch (error) {
      if (i === maxRetries - 1) throw error;
      await new Promise(resolve => setTimeout(resolve, 1000 * (i + 1)));
    }
  }
}
```

**Graceful Degradation**:
```javascript
async function getFlows() {
  try {
    const response = await axios.post(
      '/wp-json/wp-abilities/v1/abilities/datamachine/get-flows/run',
      { input: {} }
    );
    return response.data.flows;
  } catch (error) {
    console.error('Failed to fetch flows:', error);
    return [];  // Return empty array as fallback
  }
}
```

## Related Documentation

- Authentication - Auth methods and security
- API Overview - Complete API documentation
- All endpoint documentation files contain specific error examples

---

**Error Format**: WordPress REST API standard
**Status Codes**: 200, 201, 400, 403, 404, 500
**Error Recovery**: Client-side retry and fallback strategies recommended
