# Pinterest Publish Handler

Creates pins on Pinterest via API v5 with OAuth 2.0 authentication, board selection (pre-selected, AI-decides, or category-mapped), and media upload support.

## Architecture

**Base Class**: Extends PublishHandler (@since v0.3.0)

**Handler Registration**: `pinterest_publish` via `HandlerRegistrationTrait`

**Implementation**: Tool-first architecture via `handle_tool_call()` method for AI agents

**Capabilities**: publish (1 image per pin, 2:3 aspect ratio, up to 500 char description)

## Authentication

**OAuth 2.0 Required**: Uses Pinterest authorization code flow with `App ID`/`App Secret` from [developers.pinterest.com](https://developers.pinterest.com).

**Provider**: `PinterestAuth` extends `BaseOAuth2Provider` with:
- Authorization URL: `https://www.pinterest.com/oauth/`
- Token URL: `https://api.pinterest.com/v5/oauth/token`
- Scopes: `boards:read,pins:read,pins:write,user_accounts:read`
- Basic Auth required for token exchange and refresh

**Token Lifecycle**:
- Access tokens expire in 30 days (production)
- Refresh tokens valid for 1 year and rotated on use — new refresh token must be stored after each refresh
- Proactive refresh via WP-Cron at `(expires_at - 7 day buffer)`

**Configuration Fields** (`PinterestAuth::get_config_fields()`):
- `client_id` / `App ID` (required)
- `client_secret` / `App Secret` (required)

## Configuration Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `board_id` | string | Conditional | Pinterest board ID (required if no default set) |
| `title` | string | Yes | Pin title (max 100 characters) |
| `description` | string | Yes | Pin description (max 500 characters) |

## Board Selection

Three modes configured via `PinterestSettings`:

1. **Pre-selected** (`board_selection_mode: 'pre_selected'`): Uses the default `board_id` from handler settings. No AI choice.

2. **AI Decides** (`board_selection_mode: 'ai_decides'`): AI agent picks a board from cached board list. The handler injects available board names + IDs into the tool parameter description at runtime so the AI can select. Boards cached via `PinterestBoardsAbility::get_cached_boards()`.

3. **Category Mapping** (`board_selection_mode: 'category_mapping'`): Maps WordPress post categories to Pinterest boards via `board_mapping` config (format: `category_slug=board_id`, one per line). Resolved by `PinterestBoardsAbility::resolve_board_id()`.

**Resolution order**: AI parameter `board_id` → category mapping → default `board_id` from settings.

## Source URL Attribution

**Engine Data Source**: `source_url` retrieved from fetch handlers. Used as the pin's `link` field to drive traffic back to the source.

## Media Support

- **Images**: Uploaded via `source_type: 'image_url'` with the publicly accessible image URL from engine data
- **Video**: Supported via `source_type: 'video_id'` with a cover image URL
- **Resolution**: Images resolved via `resolveMediaUrls()` which checks engine data for both image and video URLs
- **Requirement**: At least one media URL (image or video) is required — pins cannot be text-only

## Usage Examples

**Basic Pin**:
```php
$parameters = [
    'title' => 'Blog Post Title',
    'description' => 'A brief description of the content',
    'board_id' => '1234567890123456789'
];

$tool_def = [
    'handler_config' => [
        'board_id' => '1234567890123456789',
        'board_selection_mode' => 'pre_selected'
    ]
];

$result = $handler->handle_tool_call($parameters, $tool_def);
```

**AI Board Selection**:
```php
$tool_def = [
    'handler_config' => [
        'board_selection_mode' => 'ai_decides'
    ]
];
// Tool parameter description will include: "Available boards: Board A (123), Board B (456)"
```

## Tool Call Response

**Success Response**:
```php
[
    'success' => true,
    'data' => [
        'pin_id' => 'pinterest_pin_id',
        'pin_url' => 'https://www.pinterest.com/pin/{pin_id}/'
    ],
    'tool_name' => 'pinterest_publish'
]
```

**Error Response**:
```php
[
    'success' => false,
    'error' => 'No publicly accessible media URL found for pin',
    'tool_name' => 'pinterest_publish'
]
```

## Settings

`PinterestSettings` extends `PublishHandlerSettings` with:
- `board_id`: Default Pinterest board ID
- `board_selection_mode`: `pre_selected` | `ai_decides` | `category_mapping`
- `board_mapping`: Category-to-board mapping (one per line: `category_slug=board_id`)

## API Integration

- Creates pins via `POST https://api.pinterest.com/v5/pins`
- Expects 201 status on success with `id` in response
- Bearer token from `PinterestAuth::get_valid_access_token()`
- Image and video pins use different `media_source` structures

## Error Handling

- **Authentication Errors**: Missing or expired token, incomplete OAuth config
- **Board Errors**: No board_id resolved, invalid board ID
- **Media Errors**: No image/video URL in engine data, download failures
- **API Errors**: Non-201 status codes, Pinterest API error messages
