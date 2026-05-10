# Data Machine Socials

Social media publishing extension for [Data Machine](https://github.com/Extra-Chill/data-machine). Enables automated publishing to Twitter/X, Facebook Pages, Bluesky, Threads, and Pinterest.

## Requirements

- WordPress 6.9+
- PHP 8.2+
- [Data Machine](https://github.com/Extra-Chill/data-machine) core plugin (required)

## Installation

1. Install and activate the Data Machine core plugin
2. Install this plugin in your WordPress plugins directory
3. Activate Data Machine Socials
4. Configure authentication for each platform in Data Machine settings

## Supported Platforms

| Platform | Auth Type | Character Limit | Media Support |
|----------|-----------|-----------------|---------------|
| **Twitter/X** | OAuth 1.0a | 280 chars | Images |
| **Facebook Pages** | OAuth 2.0 | No limit | Images |
| **Bluesky** | App Password | 300 chars | Images |
| **Threads** | OAuth 2.0 | 500 chars | Images |
| **Pinterest** | Bearer Token | N/A (pins) | Images required |

## Configuration

Each platform requires authentication setup:

1. Go to **Data Machine → Settings → Authentication**
2. Select the platform you want to configure
3. Follow the OAuth flow or enter credentials
4. Save and verify connection

### Platform-Specific Setup

**Twitter/X:**
- Requires Twitter Developer account
- Create an app at [developer.twitter.com](https://developer.twitter.com)
- Enter API Key and API Secret in settings
- Complete OAuth authorization

**Facebook:**
- Requires Facebook App with Pages permissions
- Needs `pages_manage_posts`, `pages_manage_engagement` scopes
- Authorize your Facebook account
- Select which Page to publish to

**Bluesky:**
- Generate an App Password in Bluesky settings
- Enter your handle and app password
- No OAuth flow required

**Threads:**
- Requires Meta app registration
- OAuth 2.0 authentication
- `threads_basic` and `threads_content_publish` scopes

**Pinterest:**
- Requires Pinterest Developer account
- Generate an access token
- Configure default board or use category mapping

## Usage in Pipelines

Once authenticated, social handlers appear in the Data Machine Pipeline Builder:

1. Create or edit a Pipeline
2. Add a "Publish" step
3. Select the social platform handler
4. Configure handler settings:
   - **Include Images:** Enable/disable image uploads
   - **Link Handling:** How to handle source URLs (append, reply, comment, none)
5. Save and run your flow

## AI Tool Integration

Social handlers register AI tools that can be used in Data Machine Chat:

- `twitter_publish` - Post to Twitter with media support
- `facebook_publish` - Post to Facebook Pages
- `bluesky_publish` - Post to Bluesky
- `threads_publish` - Post to Threads
- `pinterest_publish` - Create Pinterest pins

## Architecture

This plugin follows the Data Machine extension pattern:

- **Namespace:** `DataMachineSocials\Handlers\{Platform}`
- **Registration:** Handlers self-register via `HandlerRegistrationTrait`
- **Discovery:** Core `HandlerAbilities` discovers handlers via WordPress filters
- **Auth:** Core `AuthAbilities` manages OAuth flows and token storage

## Handler API

Each handler registers itself via `HandlerRegistrationTrait::registerHandler()` with
a `meta` config array (the trailing `array(...)` argument). The shape exposed to
clients via `GET /datamachine/v1/socials/platforms` is:

```php
array(
    'charLimit'          => 280,                 // optional int
    'maxImages'          => 4,                   // optional int
    'aspectRatios'       => array( 'any' ),      // optional string[]
    'defaultAspectRatio' => 'any',               // optional string
    'supportsCarousel'   => false,               // optional bool
    'supportsVideo'      => true,                // optional bool
    'capabilities'       => array(               // optional, canonicalised server-side
        array( 'slug' => 'publish', 'label' => 'Publish' ),
    ),
    'preview'            => array(               // optional — see "Preview shape" below
        'aspectRatio'     => '16:9',
        'captionPosition' => 'above',
        'previewSurface'  => 'feed',
    ),
)
```

### Preview shape

The `preview` field declares how clients should render a post preview for the
platform. It exists so consumers (e.g. the Studio publish pane) can render
platform-canonical previews **without any per-platform branching** — the client
renders whatever shape the server declares.

| Field | Values | Meaning |
|---|---|---|
| `aspectRatio` | `1:1`, `4:5`, `16:9`, `native` | How images are framed in the preview |
| `captionPosition` | `above`, `below`, `overlay` | Where the caption renders relative to media |
| `previewSurface` | `card`, `feed`, `square` | Visual chrome around the preview |

Per-platform defaults declared in this plugin:

| Platform | `aspectRatio` | `captionPosition` | `previewSurface` |
|---|---|---|---|
| Twitter / X | `16:9` | `above` | `feed` |
| Bluesky | `16:9` | `above` | `feed` |
| Threads | `native` | `below` | `feed` |
| Instagram | `1:1` | `below` | `square` |
| Facebook | `native` | `above` | `card` |
| LinkedIn | `native` | `above` | `card` |
| Pinterest | `4:5` | `below` | `square` |
| Reddit | `native` | `above` | `card` |

**Backwards compatibility.** `preview` is optional. Handlers that don't declare
it (or older DM-Socials installs running against newer clients) get a generic
feed-shaped default applied server-side:

```php
array(
    'aspectRatio'     => 'native',
    'captionPosition' => 'above',
    'previewSurface'  => 'feed',
)
```

The field is always present on the response — clients never have to default it.

## File Structure

```
data-machine-socials/
├── data-machine-socials.php          # Plugin entry point
├── composer.json                      # Dependencies (twitteroauth)
├── inc/
│   └── Handlers/
│       ├── Twitter/
│       │   ├── Twitter.php           # Handler implementation
│       │   ├── TwitterAuth.php       # OAuth 1.0a auth
│       │   └── TwitterSettings.php   # Handler settings
│       ├── Facebook/
│       │   ├── Facebook.php
│       │   ├── FacebookAuth.php      # OAuth 2.0 auth
│       │   └── FacebookSettings.php
│       ├── Bluesky/
│       │   ├── Bluesky.php
│       │   └── BlueskyAuth.php       # App password auth
│       ├── Threads/
│       │   ├── Threads.php
│       │   ├── ThreadsAuth.php       # OAuth 2.0 auth
│       │   └── ThreadsSettings.php
│       └── Pinterest/
│           ├── Pinterest.php
│           ├── PinterestAuth.php     # Bearer token auth
│           └── PinterestSettings.php
└── docs/
    └── handlers/
        ├── twitter.md
        ├── facebook.md
        ├── bluesky.md
        ├── threads.md
        └── pinterest.md
```

## Dependencies

- `abraham/twitteroauth` - Twitter OAuth 1.0a client
- Data Machine core plugin - Provides base classes and abilities

## Development

### Setup

```bash
cd data-machine-socials
composer install
```

### Coding Standards

This plugin follows WordPress coding standards and the Data Machine style guide:
- PSR-4 autoloading
- WordPress 6.9+ compatibility
- PHP 8.2+ features
- Proper namespacing and abstraction

## License

GPL v2 or later - See [LICENSE](LICENSE)

## Support

- Issues: [GitHub Issues](https://github.com/Extra-Chill/data-machine-socials/issues)
- Documentation: [Data Machine Docs](https://github.com/Extra-Chill/data-machine/tree/main/docs)
- Author: Chris Huber ([chubes.net](https://chubes.net))
