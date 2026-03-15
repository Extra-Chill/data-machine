# Instagram Handler

Social media integration for Instagram with support for images, carousels, Reels (video), Stories, comments, and account management.

## Architecture

**Base Class**: Extends PublishHandler (@since v0.2.0)

**Inherited Functionality**:
- Engine data retrieval via `getSourceUrl()` and `getImageFilePath()`
- Image validation via `validateImage()`
- Standardized responses via `successResponse()` and `errorResponse()`

**Publish Handler**: `instagram_publish` — registered via `HandlerRegistrationTrait`

**Abilities** (6 registered):
- `datamachine/instagram-publish` — publish images, carousels, Reels, Stories
- `datamachine/instagram-account` — get authenticated account info
- `datamachine/instagram-read` — list posts, get details, get comments
- `datamachine/instagram-update` — edit caption, delete, archive
- `datamachine/instagram-delete` — dedicated delete primitive
- `datamachine/instagram-comment-reply` — reply to comments

## Authentication

### OAuth 2.0 Configuration

**Provider**: `InstagramAuth` (extends `BaseOAuth2Provider`)

**Required Scopes**:
- `instagram_business_basic`
- `instagram_business_content_publish`
- `instagram_business_manage_messages`
- `instagram_business_manage_comments`

**Token Lifecycle**:
- Initial auth returns a short-lived token
- Automatically exchanged for a long-lived token (60 days)
- Proactive refresh scheduled via `schedule_proactive_refresh()`
- Cron event: `datamachine_refresh_token_instagram`
- Refresh uses `ig_refresh_token` grant type

**OAuth URLs**: `/datamachine-auth/instagram/`

## Publishing

### Media Kinds

Instagram supports four distinct publishing modes via the `media_kind` parameter:

| Media Kind | API Type | Required Input | Notes |
|------------|----------|----------------|-------|
| `image` | `IMAGE` | `image_urls` (1) | Default. Single image post. |
| `carousel` | `CAROUSEL` | `image_urls` (2-10) | Auto-detected from image count. |
| `reel` | `REELS` | `video_url` | Video Reel. Longer processing time. |
| `story` | `STORIES` | `story_image_url` or `video_url` | Ephemeral (24h). No captions via API. |

### Container Flow

All publishing follows the Instagram Graph API container pattern:

```
1. POST graph.instagram.com/{user_id}/media
   → Create container (returns container_id)

2. GET graph.instagram.com/{container_id}?fields=status_code
   → Poll until FINISHED (retry with backoff)

3. POST graph.instagram.com/{user_id}/media_publish
   → Publish container (returns media_id)

4. GET graph.instagram.com/{media_id}?fields=id,permalink
   → Fetch permalink
```

### Processing Timeouts

| Media Type | Max Retries | Poll Interval | Max Wait |
|------------|-------------|---------------|----------|
| Image | 10 | 1s | ~10s |
| Carousel (per item) | 10 | 1s | ~10s |
| Reel (video) | 30 | 2s | ~60s |
| Story (image) | 10 | 1s | ~10s |
| Story (video) | 30 | 2s | ~60s |

### Publish Input Schema

```php
[
    'content'         => 'Caption text (max 2200 chars)',  // Required
    'media_kind'      => 'image',                          // image|carousel|reel|story
    'image_urls'      => ['https://...'],                  // For image/carousel
    'video_url'       => 'https://...',                    // For reel/story
    'story_image_url' => 'https://...',                    // For story (image)
    'cover_url'       => 'https://...',                    // For reel (optional)
    'share_to_feed'   => true,                             // For reel (default true)
    'aspect_ratio'    => '4:5',                            // For image (1:1|4:5|3:4|1.91:1)
    'source_url'      => 'https://...',                    // Appended to caption
]
```

### Publish Output

```php
[
    'success'    => true,
    'media_id'   => '17891234567890',
    'media_kind' => 'reel',
    'permalink'  => 'https://www.instagram.com/reel/ABC123/',
]
```

## Configuration Options

### Handler Settings

**Default Aspect Ratio** (`default_aspect_ratio`):
- Options: `1:1`, `4:5` (default), `3:4`, `1.91:1`

**Caption Source** (`caption_source`):
- `content` (default) — use post content
- `post_excerpt` — use post excerpt
- `post_title` — use post title

### Platform Constraints

- **Character limit**: 2200
- **Max images**: 10 (carousel)
- **Supported aspect ratios**: 1:1, 4:5, 3:4, 1.91:1
- **Text-only posts**: Not supported (Instagram API requires media)

## REST API Endpoints

### Publishing

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/datamachine-socials/v1/post` | POST | Cross-platform post (supports media_kind) |
| `/datamachine-socials/v1/instagram/reel` | POST | Dedicated Reel publish |
| `/datamachine-socials/v1/instagram/story` | POST | Dedicated Story publish |

### Reading

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/datamachine-socials/v1/instagram/media` | GET | List posts, get details, get comments |

### Updating

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/datamachine-socials/v1/instagram/update` | POST | Edit caption, delete, archive |
| `/datamachine-socials/v1/instagram/comments/reply` | POST | Reply to a comment |

### Platform Info

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/datamachine-socials/v1/platforms` | GET | Instagram config (limits, supported features) |
| `/datamachine-socials/v1/auth/status` | GET | Auth status for all platforms |

## CLI Commands

All commands under `wp datamachine-socials instagram`:

### Publishing

```bash
# Single image post
wp datamachine-socials instagram publish "Caption" --image=https://example.com/photo.jpg

# Carousel (multiple --image flags)
wp datamachine-socials instagram publish "Multi" --image=https://a.jpg --image=https://b.jpg

# Reel
wp datamachine-socials instagram publish-reel "Caption" --video=https://example.com/clip.mp4
wp datamachine-socials instagram publish-reel "Caption" --video=URL --cover=URL --no-feed

# Story
wp datamachine-socials instagram publish-story --image=https://example.com/story.jpg
wp datamachine-socials instagram publish-story --video=https://example.com/story.mp4
```

### Reading

```bash
# List recent posts
wp datamachine-socials instagram posts [--limit=25] [--after=cursor] [--format=table|json]

# Get post details
wp datamachine-socials instagram post <media_id> [--format=table|json]

# Get comments on a post
wp datamachine-socials instagram comments <media_id> [--limit=25] [--format=table|json]
```

### Updating

```bash
# Edit caption
wp datamachine-socials instagram edit-caption <media_id> "New caption"

# Archive (hide from profile)
wp datamachine-socials instagram archive <media_id>

# Delete
wp datamachine-socials instagram delete <media_id>

# Reply to comment
wp datamachine-socials instagram reply-comment <comment_id> "Reply text"
```

### Account

```bash
# Auth status, token expiry, cron schedule
wp datamachine-socials instagram status
```

## Chat Tools

| Tool Name | Description |
|-----------|-------------|
| `read_instagram` | List posts, get details, get comments |
| `update_instagram` | Edit caption, delete, archive |
| `delete_instagram` | Dedicated delete |
| `reply_instagram_comment` | Reply to a comment |
| `publish_reel_instagram` | Publish a video Reel |
| `publish_story_instagram` | Publish an ephemeral Story |

## Error Handling

### Authentication Errors

- **Provider not available**: Instagram auth not configured
- **Not authenticated**: OAuth not connected
- **Token unavailable**: Token expired and refresh failed

### API Errors

- **Container creation failure**: Invalid media URL, unsupported format, or permissions issue
- **Processing timeout**: Video too large or server-side processing failure
- **Publish failure**: Container not ready or quota exceeded
- **Delete limitation**: Instagram API may not support deletion for all media types — archive recommended as alternative

### Validation Errors

- **No media**: Instagram requires at least one image for feed posts
- **Invalid URLs**: Image/video URLs validated before API calls
- **Character limit**: Captions truncated to 2200 characters with ellipsis
- **Carousel limit**: Maximum 10 images per carousel

## Instagram API Limitations

- **No text-only posts**: Every feed post requires at least one image
- **Stories have no captions**: The API does not support caption text on Stories
- **Delete is limited**: The Graph API may not support deletion for all media types; archiving is more reliable
- **Reel cover images**: Optional but recommended for better thumbnail control
- **Token refresh**: Long-lived tokens expire after 60 days — proactive refresh handles this automatically
