# Providers Endpoint

**Implementation**: `inc/Api/Providers.php`

**Base URL**: `/wp-json/datamachine/v1/providers`

## Overview

The Providers endpoint retrieves information about available AI providers and their configuration status.

## Authentication

Public metadata endpoint. The route uses `__return_true` because provider labels and model metadata are needed by setup/admin clients and do not expose raw API keys.

## Endpoints

### GET /providers

Retrieve available AI providers with configuration status.

**Permission**: Public metadata endpoint

**Purpose**: Discover available AI providers and check their configuration status

**Parameters**: None

**Example Request**:

```bash
curl https://example.com/wp-json/datamachine/v1/providers \
  -u username:application_password
```

**Success Response (200 OK)**:

```json
{
  "success": true,
  "providers": {
    "openai": {
      "label": "OpenAI",
      "configured": true,
      "models": ["gpt-4", "gpt-3.5-turbo", "gpt-4-turbo"]
    },
    "anthropic": {
      "label": "Anthropic",
      "configured": true,
      "models": ["claude-3-opus", "claude-3-sonnet", "claude-3-haiku", "claude-sonnet-4"]
    },
    "google": {
      "label": "Google",
      "configured": true,
      "models": ["gemini-pro", "gemini-1.5-pro", "gemini-1.5-flash"]
    },
    "grok": {
      "label": "Grok",
      "configured": false,
      "models": ["grok-beta"]
    },
    "openrouter": {
      "label": "OpenRouter",
      "configured": true,
      "models": ["meta-llama/llama-3-70b", "anthropic/claude-3-opus", "openai/gpt-4"]
    }
  }
}
```

**Response Fields**:
- `success` (boolean): Request success status
- `providers` (object): Object of provider definitions keyed by provider slug

**Provider Definition Fields**:
- `label` (string): Human-readable provider name
- `configured` (boolean): Whether provider API key is configured
- `models` (array): Available models for this provider

## Available Providers

### OpenAI

**Provider ID**: `openai`

**Configuration**: API key required

**Available Models**:
- `gpt-4` - Most capable GPT-4 model
- `gpt-4-turbo` - Faster GPT-4 variant
- `gpt-3.5-turbo` - Fast, cost-effective model

**Use Cases**:
- General content generation
- Structured output
- Function calling

### Anthropic

**Provider ID**: `anthropic`

**Configuration**: API key required

**Available Models**:
- `claude-3-opus` - Most capable Claude model
- `claude-3-sonnet` - Balanced performance
- `claude-3-haiku` - Fast, lightweight model
- `claude-sonnet-4` - Latest Sonnet model

**Use Cases**:
- Long-form content
- Complex reasoning
- Multi-step workflows

### Google

**Provider ID**: `google`

**Configuration**: API key required

**Available Models**:
- `gemini-pro` - General purpose model
- `gemini-1.5-pro` - Enhanced capabilities
- `gemini-1.5-flash` - Fast, efficient model

**Use Cases**:
- Multimodal content processing
- Large context windows
- Fast inference

### Grok

**Provider ID**: `grok`

**Configuration**: API key required

**Available Models**:
- `grok-beta` - Grok AI model

**Use Cases**:
- Conversational AI
- Real-time information processing

### OpenRouter

**Provider ID**: `openrouter`

**Configuration**: API key required

**Available Models**: 200+ models from multiple providers including:
- `meta-llama/llama-3-70b` - Meta's Llama 3
- `anthropic/claude-3-opus` - Claude via OpenRouter
- `openai/gpt-4` - GPT-4 via OpenRouter
- And 200+ more models

**Use Cases**:
- Access to multiple AI models through single API
- Model comparison and testing
- Fallback and redundancy

## Configuration Status

### Configured Providers

Providers with `"configured": true` have API keys set and are ready for use:

```json
{
  "openai": {
    "label": "OpenAI",
    "configured": true,
    "models": ["gpt-4", "gpt-3.5-turbo"]
  }
}
```

### Unconfigured Providers

Providers with `"configured": false` require API key configuration:

```json
{
  "grok": {
    "label": "Grok",
    "configured": false,
    "models": ["grok-beta"]
  }
}
```

## Integration Examples

### Python Provider Discovery

```python
import requests
from requests.auth import HTTPBasicAuth

url = "https://example.com/wp-json/datamachine/v1/providers"
auth = HTTPBasicAuth("username", "application_password")

response = requests.get(url, auth=auth)

if response.status_code == 200:
    data = response.json()

    # List configured providers
    configured = [k for k, v in data['providers'].items() if v['configured']]
    print(f"Configured providers: {', '.join(configured)}")

    # List available models per provider
    for provider, info in data['providers'].items():
        if info['configured']:
            print(f"\n{info['label']} models:")
            for model in info['models']:
                print(f"  - {model}")
```

### JavaScript Provider Selection

```javascript
const axios = require('axios');

const providersAPI = {
  baseURL: 'https://example.com/wp-json/datamachine/v1/providers',
  auth: {
    username: 'admin',
    password: 'application_password'
  }
};

// Get configured providers
async function getConfiguredProviders() {
  const response = await axios.get(providersAPI.baseURL, {
    auth: providersAPI.auth
  });

  const providers = response.data.providers;
  return Object.entries(providers)
    .filter(([_, provider]) => provider.configured)
    .reduce((obj, [slug, provider]) => {
      obj[slug] = provider;
      return obj;
    }, {});
}

// Get models for provider
async function getProviderModels(providerSlug) {
  const response = await axios.get(providersAPI.baseURL, {
    auth: providersAPI.auth
  });

  const provider = response.data.providers[providerSlug];
  return provider ? provider.models : [];
}

// Usage
const configured = await getConfiguredProviders();
console.log('Configured providers:', Object.keys(configured));

const openaiModels = await getProviderModels('openai');
console.log('OpenAI models:', openaiModels);
```

## Common Workflows

### Build Provider Selection UI

```bash
# Get all providers for dropdown menu
curl https://example.com/wp-json/datamachine/v1/providers \
  -u username:application_password
```

### Check Configuration Status

```bash
# Verify providers are configured before allowing workflow execution
curl https://example.com/wp-json/datamachine/v1/providers \
  -u username:application_password | jq '.providers | to_entries | map(select(.value.configured == false)) | map(.key)'
```

### List Available Models

```bash
# Get all available models across providers
curl https://example.com/wp-json/datamachine/v1/providers \
  -u username:application_password | jq '.providers | to_entries | map({provider: .key, models: .value.models})'
```

## Use Cases

### Dynamic Model Selection

Build model selection UI based on configured providers:

```javascript
const providers = await getConfiguredProviders();
const modelOptions = [];

for (const [slug, provider] of Object.entries(providers)) {
  for (const model of provider.models) {
    modelOptions.push({
      value: `${slug}:${model}`,
      label: `${provider.label} - ${model}`
    });
  }
}
```

### Provider Validation

Validate provider configuration before executing AI workflows:

```javascript
const providers = await getConfiguredProviders();
const requiredProvider = 'anthropic';

if (!providers[requiredProvider]) {
  throw new Error(`Provider ${requiredProvider} is not configured`);
}
```

### Fallback Provider Selection

Implement fallback logic when primary provider is unavailable:

```javascript
const preferredProviders = ['openai', 'anthropic', 'google'];
const configured = await getConfiguredProviders();

for (const provider of preferredProviders) {
  if (configured[provider]) {
    return { provider, model: configured[provider].models[0] };
  }
}
```

## Related Documentation

- Execute Endpoint - Workflow execution with AI steps
- Chat Endpoint - Conversational AI interface
- Settings Endpoints - Configuration management
- Authentication - Auth methods

---

**Base URL**: `/wp-json/datamachine/v1/providers`
**Permission**: `manage_options` capability required
**Implementation**: `inc/Api/Providers.php`
**Configuration**: API keys stored in WordPress options
