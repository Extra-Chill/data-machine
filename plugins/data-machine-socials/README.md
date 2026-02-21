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
