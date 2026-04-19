# StepTypes Endpoint

**Implementation**: `inc/Api/StepTypes.php`

**Base URL**: `/wp-json/datamachine/v1/step-types`

## Overview

The StepTypes endpoint provides information about available step types for pipeline building.

## Authentication

Requires `manage_options` capability. See Authentication Guide.

## Endpoints

### GET /step-types

Retrieve available step types for pipeline configuration.

**Permission**: `manage_options` capability required

**Purpose**: Discover available step types for pipeline builder UI

**Parameters**: None

**Example Request**:

```bash
curl https://example.com/wp-json/datamachine/v1/step-types \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "step_types": [
    {
      "type": "fetch",
      "label": "Fetch",
      "description": "Retrieve content from external sources"
    },
    {
      "type": "ai",
      "label": "AI Processing",
      "description": "Process content with AI providers"
    },
    {
      "type": "publish",
      "label": "Publish",
      "description": "Publish content to destinations"
    },
    {
      "type": "upsert",
      "label": "Upsert",
      "description": "Create or update content with identity-aware detection"
    }
  ]
}
```

**Response Fields**:
- `success` (boolean): Request success status
- `step_types` (array): Array of step type definitions

**Step Type Fields**:
- `type` (string): Step type identifier
- `label` (string): Human-readable step type name
- `description` (string): Step type description

## Available Step Types

### Fetch

**Type ID**: `fetch`

**Purpose**: Retrieve content from external sources

**Description**: Fetch steps retrieve data from various sources including RSS feeds, Reddit, WordPress sites, Google Sheets, and local files. Each fetch handler provides structured data for downstream processing.

**Available Handlers**:
- RSS Feed
- Reddit
- Google Sheets
- WordPress Local
- WordPress Media
- WordPress API
- Files

**Use Cases**:
- Import content from RSS feeds
- Fetch posts from social media
- Extract data from spreadsheets
- Read local WordPress content

### AI

**Type ID**: `ai`

**Purpose**: Process content with AI providers

**Description**: AI steps process content using language models from multiple providers (OpenAI, Anthropic, Google, Grok, OpenRouter). Supports tool calling, custom system prompts, and structured output.

**Available Providers**:
- OpenAI (GPT-4, GPT-3.5-turbo)
- Anthropic (Claude 3 Opus, Sonnet, Haiku)
- Google (Gemini Pro, Gemini 1.5)
- Grok
- OpenRouter (200+ models)

**Use Cases**:
- Summarize content
- Transform data format
- Generate metadata
- Extract information
- Create derivative content

### Publish

**Type ID**: `publish`

**Purpose**: Publish content to destinations

**Description**: Publish steps send processed content to various platforms and services. Each handler manages platform-specific requirements, character limits, and media handling.

**Available Handlers**:
- Twitter (280 char limit)
- Bluesky (300 char limit)
- Threads (500 char limit)
- Facebook (no limit)
- WordPress (no limit)
- Google Sheets

**Use Cases**:
- Post to social media
- Create WordPress content
- Share to multiple platforms
- Archive to spreadsheets

### Upsert

**Type ID**: `upsert`

**Purpose**: Create or update content with identity-aware detection

**Description**: Upsert steps perform find-or-create-or-update against a target system. Handlers can be update-only (modify existing content by source_url), full upsert (find-by-identity, update if changed, create if new), or create-only-if-new. Identity resolution uses the `datamachine_duplicate_strategies` filter per post type.

**Available Handlers**:
- WordPress Update (update-only mode)
- Event Upsert (events plugin; full upsert by title+venue+startDate)
- GitHub Update (data-machine-code; full upsert via Contents API SHA)

**Use Cases**:
- Enhance existing posts
- Update metadata
- Refresh outdated content
- Bulk content updates

## Step Type Patterns

### Common Workflow Patterns

**Single Platform Publishing**:
```
Fetch → AI → Publish
```

**Multi-Platform Publishing**:
```
Fetch → AI → Publish → AI → Publish
```

**Content Enhancement**:
```
Fetch → AI → Update
```

**Data Pipeline**:
```
Fetch → AI → Publish (to spreadsheet/database)
```

## Integration Examples

### Python Step Type Discovery

```python
import requests
from requests.auth import HTTPBasicAuth

url = "https://example.com/wp-json/datamachine/v1/step-types"
auth = HTTPBasicAuth("username", "application_password")

response = requests.get(url, auth=auth)

if response.status_code == 200:
    data = response.json()

    print("Available Step Types:")
    for step_type in data['step_types']:
        print(f"\n{step_type['label']} ({step_type['type']}):")
        print(f"  {step_type['description']}")
```

### JavaScript Pipeline Builder

```javascript
const axios = require('axios');

const stepTypesAPI = {
  baseURL: 'https://example.com/wp-json/datamachine/v1/step-types',
  auth: {
    username: 'admin',
    password: 'application_password'
  }
};

// Get all step types
async function getStepTypes() {
  const response = await axios.get(stepTypesAPI.baseURL, {
    auth: stepTypesAPI.auth
  });

  return response.data.step_types;
}

// Build step selector options
async function buildStepSelector() {
  const stepTypes = await getStepTypes();

  return stepTypes.map(step => ({
    value: step.type,
    label: step.label,
    description: step.description
  }));
}

// Usage
const stepTypes = await getStepTypes();
console.log('Step types:', stepTypes.map(s => s.type));

const selectorOptions = await buildStepSelector();
console.log('Selector options:', selectorOptions);
```

## Common Workflows

### Build Pipeline Step Selector

```bash
# Get step types for UI builder
curl https://example.com/wp-json/datamachine/v1/step-types \
  -u username:application_password
```

### Validate Pipeline Structure

```bash
# Verify step types before pipeline creation
curl https://example.com/wp-json/datamachine/v1/step-types \
  -u username:application_password | jq '.step_types | map(.type)'
```

## Use Cases

### Dynamic Pipeline Builder UI

Build step selection interface from API data:

```javascript
const stepTypes = await getStepTypes();

const stepButtons = stepTypes.map(step => ({
  type: step.type,
  label: step.label,
  icon: getIconForType(step.type),
  tooltip: step.description
}));
```

### Pipeline Validation

Validate step type before adding to pipeline:

```javascript
const validTypes = (await getStepTypes()).map(s => s.type);

function validateStepType(type) {
  if (!validTypes.includes(type)) {
    throw new Error(`Invalid step type: ${type}`);
  }
  return true;
}
```

### Documentation Generation

Generate step type documentation:

```bash
curl https://example.com/wp-json/datamachine/v1/step-types \
  -u username:application_password | jq -r '.step_types[] | "### \(.label)\n\n\(.description)\n"'
```

## Related Documentation

- Pipelines Endpoints - Pipeline management with steps
- Handlers Endpoint - Available handlers per step type
- Execute Endpoint - Workflow execution
- Authentication - Auth methods

---

**Base URL**: `/wp-json/datamachine/v1/step-types`
**Permission**: `manage_options` capability required
**Implementation**: `inc/Api/StepTypes.php`
**Available Types**: fetch, ai, publish, upsert
