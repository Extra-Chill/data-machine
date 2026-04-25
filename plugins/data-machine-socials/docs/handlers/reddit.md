# Reddit Fetch Handler

Fetches posts from Reddit subreddits via OAuth 2.0 with timeframe filtering, keyword matching, and batch fan-out support.

## Architecture

**Base Class**: Extends FetchHandler (@since v0.3.0)

**Handler Registration**: `reddit` (fetch step) via `HandlerRegistrationTrait`

**Implementation**: Delegates to `FetchRedditAbility` for core logic, returns eligible items as batch for fan-out

**Note**: Reddit is a **fetch** handler (reads posts from subreddits), not a publish handler. Use `SubmitRedditAbility` and `ReplyRedditAbility` for writing.

## Authentication

**OAuth 2.0 Required**: Uses Reddit authorization code flow with `client_id`/`client_secret` from [reddit.com/prefs/apps](https://www.reddit.com/prefs/apps).

**Provider**: `RedditAuth` extends `BaseOAuth2Provider` with:
- Authorization URL: `https://www.reddit.com/api/v1/authorize`
- Token URL: `https://www.reddit.com/api/v1/access_token`
- Scopes: `identity read submit vote`
- Basic Auth required for token exchange (`client_id:client_secret`)
- Tokens expire in 1 hour; refresh uses stored refresh_token

**Configuration Fields** (`RedditAuth::get_config_fields()`):
- `client_id` (required): Reddit application Client ID
- `client_secret` (required): Reddit application Client Secret
- `developer_username` (required): Reddit username registered in the app (used for User-Agent header)

## Configuration Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `subreddit` | string | Yes | Subreddit name (without r/ prefix) |
| `sort_by` | string | No | Sort method: `hot`, `new`, `top`, `rising` (default: `hot`) |
| `timeframe_limit` | string | No | Time filter for `top` sort: `hour`, `day`, `week`, `month`, `year`, `all_time` (default: `all_time`) |
| `min_upvotes` | int | No | Minimum upvote threshold (default: 0) |
| `min_comment_count` | int | No | Minimum comment count threshold (default: 0) |
| `search` | string | No | Keyword filter for post titles and content |

## Fetch Behavior

1. Authenticates via `RedditAuth::get_valid_access_token()` (auto-refreshes expired tokens)
2. Delegates to `FetchRedditAbility::execute()` with config parameters
3. Fetches up to `fetch_batch_size` (100) posts across `max_pages` (5) pages
4. Filters by upvotes, comment count, and keywords
5. Checks processed items for dedup
6. Downloads images for eligible posts via `ExecutionContext::downloadFile()`
7. Returns all eligible items as batch array for fan-out via `PipelineBatchScheduler`

## Data Output

Each eligible item contains:
```php
[
    'metadata' => [
        'original_id' => 't3_abc123',
        'dedup_key'   => 't3_abc123',
        'source_url'  => 'https://reddit.com/r/subreddit/comments/abc123/...',
        '_engine_data' => [
            'source_url'      => 'https://reddit.com/...',
            'image_file_path' => '/path/to/downloaded/image.jpg'
        ]
    ],
    // ... post content fields
]
```

## Image Handling

- Reddit post images are downloaded to the file repository during fetch via `store_reddit_image()`
- File naming: `reddit_image_{item_id}.{extension}`
- Downloaded images are stored as `file_info` metadata for downstream pipeline steps

## Settings

`RedditSettings` provides subreddit and filter configuration for the handler.

## CLI Commands

`RedditCommand` provides WP-CLI access:
- `wp datamachine reddit fetch` — fetch posts from a subreddit

## Chat Tools

- `VoteReddit` — upvote/downvote Reddit posts
- `ReplyReddit` — reply to Reddit posts or comments
- `SubmitReddit` — submit new posts to subreddits

## Error Handling

- **Authentication Errors**: Missing OAuth config, expired/revoked tokens, refresh failures
- **API Errors**: Rate limiting (Reddit enforces per-app limits), subreddit not found, network timeouts
- **Image Errors**: Download failures, invalid URLs — non-fatal (post still eligible without image)
