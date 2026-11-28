# YouTube MVP

Last verified: 2026-07-13 against the official YouTube documentation listed below.

Data Machine Socials supports the smallest useful official YouTube integration: Google OAuth, resumable video upload, authenticated channel reads, and YouTube search. It does **not** claim or attempt to create YouTube Community posts.

## Supported Matrix

| Capability | Official API status | This MVP |
|---|---|---|
| Video uploads | Supported: `videos.insert` with resumable upload | Supported |
| Shorts | No separate upload API or Short type; YouTube classifies eligible uploaded videos | Upload normally; production workflow must deliver a vertical, short-form master |
| Community post creation | **Not publicly supported**: Data API v3 exposes no Community post resource or create endpoint | Not implemented |
| Comments | Supported: `commentThreads.insert` and `comments.insert` | Deferred from this focused MVP |
| Search/discovery | Supported: `search.list` | Supported |
| Playlists | Supported: `playlists.insert`, `playlistItems.insert` | Deferred from this focused MVP |
| Channel/video analytics | Supported by YouTube Analytics and Reporting APIs | Deferred from this focused MVP |

## Why Community Posting Is Excluded

The official YouTube Data API v3 resource and operations list includes activities, channels, comments, playlists, search results, videos, and related resources. It contains no Community post resource or Community-post create operation. No OAuth scope grants Community publishing. This integration therefore must never present Community creation as available.

## Setup And Gates

1. Create a Google Cloud OAuth web application and add the Data Machine callback URL shown in Settings > Authentication > YouTube.
2. Enable YouTube Data API v3 for that project.
3. Connect the channel owner through OAuth. Service accounts do not work with YouTube channels (`NoLinkedYouTubeAccount`).
4. Requested scopes are `youtube.upload`, `youtube.force-ssl`, and `youtube`.
5. Keep pilot uploads `private` until the Google Cloud project passes YouTube's API compliance audit.

### Upload Verification Gate

Official documentation states that videos uploaded using `videos.insert` from API projects created after 2020-07-28 are restricted to **private viewing mode** until the project completes YouTube's compliance audit. The ability defaults to `private`; a `public` or `unlisted` request is not evidence that the platform will honor it before verification.

### Quotas

Official default allocation is 100 `search.list` calls/day, 100 `videos.insert` calls/day, and 10,000 combined units/day for other endpoints. `videos.insert` and `search.list` each cost one unit under the current documentation. Track usage in Google Cloud Console; request an extension only after a real pilot justifies it.

## Required Video Workflow

1. Produce a rights-cleared source video with title, description, category, tags, and a chosen privacy state.
2. For a Short, produce an eligible vertical short-form master. The upload endpoint is the same `videos.insert` endpoint; classification is controlled by YouTube, not an API flag.
3. Pipeline upload uses the local `video_file_path` when available. A public `video_url` is downloaded to a temporary file first because YouTube expects video bytes in the resumable upload protocol.
4. The MVP starts a resumable session, uploads bytes, and returns the new video ID and watch URL. It does not promise that processing is complete or that an unaudited project can make the result public.

## Fit For Extra Chill

| Surface | Fit (5 max) | Reason |
|---|---:|---|
| Events | 4/5 | Show recaps, venue previews, artist interviews, and vertical teasers can create durable discovery assets. Requires actual video production, not a text-post substitute. |
| Forums | 2/5 | Search can identify relevant videos, but Community posts are unavailable and forum promotion needs manual YouTube Community publishing. |
| Artist profiles | 5/5 | Artist interviews, live sessions, music videos, and Shorts are a strong portable profile asset when rights and production are in place. |

## Pilot

Run a four-week pilot with 8-12 rights-cleared uploads: event recaps and artist sessions, plus vertical cutdowns where editorially useful. Do not optimize around a Community-post workflow.

Measure per video at 7 and 28 days:

- Public-status success after verification, upload failure rate, and processing completion rate.
- Views, unique viewers, average view duration, average percentage viewed, and subscribers gained.
- Clicks to Extra Chill via tracked description links and resulting event/profile/forum sessions.
- Search discovery: impressions, click-through rate, and traffic-source share from YouTube Search.

Pilot success threshold: at least 90% successful uploads, measurable qualified referrals to Extra Chill, and one content format with repeatable watch-time or conversion performance sufficient to justify API-audit and analytics follow-up.

## Official Sources

- [Videos: insert](https://developers.google.com/youtube/v3/docs/videos/insert), last updated 2026-07-08: upload scopes, 256 GB media limit, video-upload quota, and audit requirement for projects created after 2020-07-28.
- [Resumable Uploads](https://developers.google.com/youtube/v3/guides/using_resumable_upload_protocol), last updated 2026-06-01: session initiation, `Location` URI, binary `PUT`, and retry/resume behavior.
- [YouTube Data API Overview](https://developers.google.com/youtube/v3/getting-started), last updated 2026-06-01: official resource list proving no Community-post resource, default quota allocation, and operation support.
- [OAuth 2.0 Authorization](https://developers.google.com/youtube/v3/guides/authentication), last updated 2026-06-01: OAuth user authorization and service-account limitation.
- [CommentThreads: insert](https://developers.google.com/youtube/v3/docs/commentThreads/insert), last updated 2026-06-01: official comment capability and `youtube.force-ssl` scope.
- [YouTube Reporting API](https://developers.google.com/youtube/reporting/v1/reports), last verified 2026-07-13: bulk analytics reporting scopes and 24-hour report lifecycle.
