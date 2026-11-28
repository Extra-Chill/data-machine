# TikTok Integration

Last verified: 2026-07-13.

## Supported MVP

Data Machine Socials uses TikTok's official Login Kit, Content Posting API, and Display API:

| Capability | MVP support | Official scope | Prerequisite |
| --- | --- | --- | --- |
| OAuth web authorization and refresh | Yes | `user.info.basic` | Approved TikTok app |
| Direct video post from server URL | Yes, `PULL_FROM_URL` | `video.publish` | App review, verified video-host domain |
| Creator privacy/duration query | Yes | `video.publish` | OAuth authorization |
| Publish-status polling | Yes | `video.publish` | OAuth authorization |
| List the connected creator's public videos | Yes | `video.list` | App review and OAuth authorization |
| Read or reply to comments | No | Not exposed by these attainable product APIs | Not implemented |
| Search/discover public TikTok content | No | Research API is not a commercial discovery API | Not implemented |
| Platform analytics beyond own-video public counters | No | No attainable general analytics API in this MVP | Not implemented |
| Research API | No | `research.data.*` | Independent/academic, non-profit research application required |

## Approval And Audit Gates

1. Create a TikTok developer app and register the exact HTTPS callback URI. Login Kit and API access require TikTok App Review.
2. Request `user.info.basic`, `video.list`, and `video.publish`.
3. Verify ownership of the domain or URL prefix that serves `video_url`. TikTok rejects unverified pull URLs.
4. Test using `SELF_ONLY`. Until the separate Content Posting Audit is approved, TikTok restricts unaudited clients to private viewing. Do not expect public promotion before the audit completes.
5. Submit the Content Posting Audit after the workflow is tested. Public visibility is allowed only after that audit and TikTok moderation.

The Research API is not a fallback for discovery: TikTok limits it to independent and academic researchers conducting non-profit research, with a separate approval process. Extra Chill should not claim eligibility without independently satisfying that program.

## Media And Rate Limits

- Server-hosted publishing uses `source=PULL_FROM_URL`; TikTok pulls the public HTTPS URL directly. It must not redirect and must remain available for the transfer.
- Video: MP4 recommended; WebM and MOV supported. H.264 recommended; H.265, VP8, and VP9 supported. 23-60 FPS, 360-4096 px per dimension, up to 4 GB.
- Creator-specific duration is returned by Creator Info; the API maximum is 10 minutes.
- Direct Post: 6 requests/minute per user access token. Post Status: 30/minute. Display API user/video reads: 600/minute.

## Product Fit

| Surface | Fit (1-5) | Repeatable video formats | Pilot KPI |
| --- | --- | --- | --- |
| Events | 5 | Weekly city roundup, 15-45 second venue/show picks, day-of reminders, artist soundcheck clips | Published clips/week, post completion rate, tracked event-page visits, event marks |
| Artist profiles | 4 | New release teaser, 30-second artist introduction, live-performance cut, link-page callout | Published clips/artist, profile visits from tagged links, artist access requests |
| Forums | 3 | Community question of the week, member takeaways, discussion recap | Clips/week, community topic starts and replies attributed to campaign links |

## Pilot

Start with 8 weeks after the Content Posting Audit clears:

- Publish 2 event roundups and 1 artist clip per week, using 9:16 video with a direct on-screen CTA.
- Keep a `SELF_ONLY` validation pass for every new video template before public scheduling.
- Track API success/status, TikTok public post ID, UTM landing-page sessions, event marks, artist-profile visits, and community topic/reply conversion.
- Success threshold: at least 95% successful API submissions, at least 90% completed TikTok processing, and a measurable tracked-action rate from tagged TikTok landing links.

## Official Sources

- TikTok Login Kit Web, accessed 2026-07-13: https://developers.tiktok.com/doc/login-kit-web
- User Access Token Management, accessed 2026-07-13: https://developers.tiktok.com/doc/oauth-user-access-token-management
- Content Posting API Get Started, accessed 2026-07-13: https://developers.tiktok.com/doc/content-posting-api-get-started
- Direct Post API Reference, accessed 2026-07-13: https://developers.tiktok.com/doc/content-posting-api-reference-direct-post
- Get Post Status, accessed 2026-07-13: https://developers.tiktok.com/doc/content-posting-api-reference-get-video-status
- Media Transfer Guide, accessed 2026-07-13: https://developers.tiktok.com/doc/content-posting-api-media-transfer-guide
- Scopes Reference, accessed 2026-07-13: https://developers.tiktok.com/doc/tiktok-api-scopes
- Rate Limits, accessed 2026-07-13: https://developers.tiktok.com/doc/tiktok-api-v2-rate-limit
- Research Tools, accessed 2026-07-13: https://developers.tiktok.com/doc/about-research-api
- App Review FAQ, accessed 2026-07-13: https://developers.tiktok.com/doc/getting-started-faq
