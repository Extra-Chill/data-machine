# Mastodon / Fediverse

Data Machine Socials supports any instance exposing the Mastodon REST API. It does not select or assume a particular host.

## Configuration

1. Choose the instance that hosts the account.
2. In that instance's Settings > Development area, create an application with `read` and `write` scopes.
3. Save its user access token and the instance URL in Data Machine authentication settings for `mastodon`.

The token is encrypted at rest by Data Machine's auth provider. `wp datamachine-socials mastodon register-app <instance>` can register an OAuth application, but authorization and token storage remain an explicit operator action.

## Supported Capabilities

| Capability | Support | API surface |
| --- | --- | --- |
| Configure any instance | Yes | Configured base URL |
| OAuth app registration helper | Yes | `POST /api/v1/apps` |
| Publish text status | Yes | `POST /api/v1/statuses` |
| Publish one image with alt text | Yes | `POST /api/v2/media`, then statuses |
| Delete own status | Yes | `DELETE /api/v1/statuses/:id` |
| Read statuses, profiles, threads, timelines, hashtags | Yes | Accounts, statuses, timelines APIs |
| Search accounts, statuses, hashtags | Yes | `GET /api/v2/search` |
| Notifications | Yes | Notifications API |
| Favourite, boost, bookmark and undo | Yes | Status engagement endpoints |
| Post analytics / impressions / reach | No | Not provided by the standard API |
| Scheduled statuses, polls, multiple media, video | Not in this release | Deliberately out of initial scope |

## Compatibility Limits

- This targets the Mastodon REST API. Other Fediverse software may implement only a subset or vary in behavior.
- Character and media limits are instance-configurable. Mastodon's common default is 500 characters and four images; check `GET /api/v2/instance` for the selected instance.
- `POST /api/v2/media` may process larger media asynchronously. The initial integration is optimized for image posts.
- Rate limits are instance-configurable and returned in `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `X-RateLimit-Reset`. A common Mastodon default is 300 requests per five minutes.
- Status objects expose favourites, boosts, and replies, but no standard impressions, reach, or click analytics exist.

## CLI

```bash
wp datamachine-socials mastodon status
wp datamachine-socials mastodon posts --limit=20
wp datamachine-socials mastodon timeline --timeline=public
wp datamachine-socials mastodon hashtag CharlestonMusic
wp datamachine-socials mastodon search "indie music" --type=statuses
wp datamachine-socials mastodon publish "New show announced" --source-url=https://example.com/event
```

## Pilot Recommendations

| Surface | Score | First pilot | KPI over 30 days |
| --- | --- | --- | --- |
| Events calendar | 9/10 | Post 10 local event announcements with city/genre hashtags and canonical event links | At least 25 link visits, 10 boosts/favourites, and 3 attributable RSVP or outbound-ticket clicks |
| Community forums | 8/10 | Weekly conversation prompt linking to one active forum discussion | At least 15 link visits, 5 replies/boosts, and 2 new forum accounts or first replies |
| Artist profiles | 7/10 | Three opt-in artist spotlights with image, tagged artist account, and profile URL | At least 20 profile visits, 10 engagements, and 2 artist-owner confirmations of referral traffic |

Use UTM-tagged canonical links to measure on-site visits and conversions; use status favourites, boosts, and replies only as engagement signals.
