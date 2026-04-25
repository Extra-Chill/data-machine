# LinkedIn Publish Handler

Posts content to LinkedIn using OAuth 2.0 authentication with media upload support and configurable post visibility.

## Architecture

**Base Class**: Extends PublishHandler (@since v0.5.0)

**Handler Registration**: `linkedin_publish` via `HandlerRegistrationTrait`

**Implementation**: Tool-first architecture via `handle_tool_call()` method for AI agents

**Capabilities**: publish (text up to 3000 chars, images, article sharing)

## Authentication

**OAuth 2.0 Required**: Uses LinkedIn authorization code flow with `client_id`/`client_secret` from [LinkedIn Developers](https://linkedin.com/developers).

**Provider**: `LinkedInAuth` extends `BaseOAuth2Provider` with:
- Authorization URL: `https://www.linkedin.com/oauth/v2/authorization`
- Token URL: `https://www.linkedin.com/oauth/v2/accessToken`
- Userinfo URL: `https://api.linkedin.com/v2/userinfo` (OpenID Connect)
- Scopes: `openid profile email w_member_social`
- API version header: `202603`

**Token Refresh**: Supports `refresh_token` grant for programmatic token renewal. Access tokens last 60 days.

**Configuration Fields** (`LinkedInAuth::get_config_fields()`):
- `client_id` (required): LinkedIn application Client ID
- `client_secret` (required): LinkedIn application Client Secret

## Configuration Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `visibility` | string | No | Post visibility: `PUBLIC` or `CONNECTIONS` (default: `PUBLIC`) |

## Source URL Attribution

**Engine Data Source**: `source_url` retrieved from fetch handlers via `datamachine_engine_data` filter

Content and source URL are assembled by `LinkedInPublishAbility::execute_publish()` which handles the actual API call.

## Usage Examples

**Basic Tool Call**:
```php
$parameters = [
    'content' => 'This is my LinkedIn post content'
];

$tool_def = [
    'handler_config' => [
        'visibility' => 'PUBLIC'
    ]
];

$result = $handler->handle_tool_call($parameters, $tool_def);
```

**With Media**:
```php
$parameters = [
    'content' => 'Important announcement about new features.',
    'source_url' => 'https://example.com/article',
    'image_url' => 'https://example.com/image.jpg'
];
```

## Media Support

- Image uploads via engine data (`getImagePath()`)
- Up to 9 images per post
- Any aspect ratio supported

## Tool Call Response

**Success Response**:
```php
[
    'success' => true,
    'data' => [
        'post_id' => 'linkedin_post_urn',
        'post_url' => 'https://www.linkedin.com/feed/update/{post_id}',
        'content' => 'Posted content'
    ],
    'tool_name' => 'linkedin_publish'
]
```

**Error Response**:
```php
[
    'success' => false,
    'error' => 'Error description',
    'tool_name' => 'linkedin_publish'
]
```

## Settings

`LinkedInSettings` extends `PublishHandlerSettings` with:
- `visibility`: Select (`PUBLIC` or `CONNECTIONS`) — controls who can see published posts

## Error Handling

- **Authentication Errors**: Missing OAuth credentials, expired tokens, profile fetch failures
- **Content Errors**: Empty content parameter, API post creation failures
- **Media Errors**: Invalid image paths, upload failures

## API Integration

- Uses `LinkedInAuth::api_request()` for authenticated API calls with required headers (`Authorization`, `Linkedin-Version`, `X-Restli-Protocol-Version`)
- Person URN resolved from stored `person_id` via `LinkedInAuth::get_person_urn()`
