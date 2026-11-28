# Tumblr Handler

The Tumblr integration publishes Neue Post Format (NPF) text posts, reads a blog's posts, and discovers public posts by tag through Tumblr's official API v2.

## Authentication

Register an application at `https://www.tumblr.com/oauth/apps`, then configure its OAuth Consumer Key as `client_id` and OAuth Consumer Secret as `client_secret`.

The integration uses Tumblr OAuth 2.0 authorization code flow at `https://www.tumblr.com/oauth2/authorize` with `basic write offline_access` scopes. Tokens refresh through `https://api.tumblr.com/v2/oauth2/token`; Tumblr rotates refresh tokens, so the provider stores each returned replacement token. Configure the Data Machine callback URL as an app redirect URL.

## Publish Configuration

`blog_identifier` is required for publishing. It can be a blog name such as `extrachill` or a hostname such as `extrachill.tumblr.com`. `default_tags` adds comma-separated tags to every handler post.

Published posts contain optional title and required body NPF text blocks, optional tags, and the pipeline source URL as Tumblr `source_url` attribution. Native image/video posts require Tumblr's multipart NPF media upload (`identifier` blocks plus binary form parts); this integration intentionally does not claim that support.

## Capability Matrix

| Capability | Status | Official API |
|---|---|---|
| OAuth 2.0 auth and refresh | Supported | `/oauth2/authorize`, `/v2/oauth2/token` |
| NPF text publish | Supported | `POST /v2/blog/{blog}/posts` |
| Read blog posts and one post | Supported | `GET /v2/blog/{blog}/posts` |
| Edit/delete owned posts | Supported | `PUT /posts/{id}`, `POST /post/delete` |
| Tag discovery | Supported | `GET /v2/tagged` |
| Like/follow engagement | Supported | `/v2/user/like`, `/v2/user/follow` |
| Native media upload | Not implemented | Requires multipart NPF upload flow |
| Analytics | Unsupported by Tumblr API | No dedicated analytics endpoint |
| Notes | Not implemented | `/notes` offers note counts, not analytics |

Tumblr requires a consistent `User-Agent`. Data Machine's shared HTTP client supplies one. Important limits include 1,000 calls/hour and 5,000 calls/day per consumer key; Tumblr returns HTTP 429 when a limit is exceeded. It also limits users to 250 published posts and 250 uploaded images per day.

## CLI

`wp datamachine-socials tumblr status|info|posts|post|tagged|publish|delete`

Use `tagged` for discovery. It accepts a tag and optional `--limit` up to 20; it is not general keyword or audience search.
