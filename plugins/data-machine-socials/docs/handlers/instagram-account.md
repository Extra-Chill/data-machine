# Instagram account (public)

The `instagram_account` fetch handler reads public **Business/Creator** account posts using Graph API Business Discovery. Personal and nonexistent accounts are not supported.

Connect Instagram normally; this reuses the existing `instagram_basic` and `pages_read_engagement` scopes. Set `username` (a username, @handle, or Instagram profile URL), `max_items`, optionally `days_back` (0/unset means unlimited), and `search` (case-insensitive, comma-separated caption keywords; match any).

Posts are deduplicated by media ID using Data Machine's processed/claimed-item collector, paginating past previously imported posts. Each pipeline item includes the caption, permalink, account and media metadata, and a stored image file: the image itself, the first image child of a carousel (falling back to its media URL), or the thumbnail of a video. Posts whose images cannot be stored are logged and skipped, not emitted as caption-only items.

## Optional recent-post context

Set `recent_context` to an integer from **0 to 12** (default **0**, off) to append up to N other most recent posts from the same fetched Business Discovery media page to each item's content. Each entry includes its timestamp, permalink, and caption, excluding the item itself. This can help combine event details split across posts, such as a venue announcement and a later doors-time announcement.

The section is labelled **“Other recent posts from this account (context only — do not create items from these alone)”**. It reuses the fetched page without extra API requests or fetching more pages just for context. Context may include posts excluded by caption/date filters or already processed posts; these are not emitted or marked processed merely for appearing as context. Fewer than N entries are included when the page has fewer other posts. With `recent_context=0`, content and output are unchanged.

Preview without downloading images or marking posts processed:

```sh
wp datamachine-socials instagram account grooving.violation --limit=10
```

The `datamachine-socials/fetch-instagram-account` ability accepts the same settings and returns mapped candidates with image URLs. Pipeline execution adds local file storage. Rate limits advise retrying later; missing/expired authentication requires reconnecting Instagram.
