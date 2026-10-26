# Instagram account (public)

The `instagram_account` fetch handler reads public **Business/Creator** account posts using Graph API Business Discovery. Personal and nonexistent accounts are not supported.

Connect Instagram normally; this reuses the existing `instagram_basic` and `pages_read_engagement` scopes. Set `username` (a username, @handle, or Instagram profile URL), `max_items`, optionally `days_back` (0/unset means unlimited), and `search` (case-insensitive, comma-separated caption keywords; match any).

Posts are deduplicated by media ID using Data Machine's processed/claimed-item collector, paginating past previously imported posts. Each pipeline item includes the caption, permalink, account and media metadata, and a stored image file: the image itself, the first image child of a carousel (falling back to its media URL), or the thumbnail of a video. Posts whose images cannot be stored are logged and skipped, not emitted as caption-only items.

Preview without downloading images or marking posts processed:

```sh
wp datamachine-socials instagram account grooving.violation --limit=10
```

The `datamachine-socials/fetch-instagram-account` ability accepts the same settings and returns mapped candidates with image URLs. Pipeline execution adds local file storage. Rate limits advise retrying later; missing/expired authentication requires reconnecting Instagram.
