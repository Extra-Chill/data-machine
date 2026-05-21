# Data Machine Business

Business and enterprise integrations for Data Machine.

## Description

This plugin extends Data Machine with business-focused integrations including:

- **Google Search**: Search the web through Google Custom Search API as an AI tool
- **Google Sheets**: Fetch data from spreadsheets and append data for reporting
- **PageSpeed Insights**: Run Lighthouse audits for performance, SEO, accessibility, and optimization opportunities
- **Slack**: Post messages and fetch conversations from channels
- **Discord**: Post messages and fetch messages from server channels
- **Amazon Affiliate Link**: Search Amazon products and return affiliate links using the Amazon Creators API

## Requirements

- WordPress 6.9+
- PHP 8.2+
- Data Machine core plugin (required)

## Installation

1. Install and activate Data Machine core plugin
2. Upload and activate this plugin
3. Configure Google Sheets authentication in Data Machine settings
4. Create flows using the Google Sheets handlers

## Google Search Tool

Data Machine Business registers the `google_search` AI tool for chat and pipeline contexts. It uses the Google Custom Search API endpoint and the same `datamachine_search_config` site option previously used by Data Machine core, so existing saved API keys and Custom Search Engine IDs continue to work after this plugin is activated.

### Google Search Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Enable the Custom Search API for your project
3. Create or select an API key with access to the Custom Search API
4. Create a Programmable Search Engine and copy its Search Engine ID
5. Save the API key and Search Engine ID in Data Machine tool settings for `google_search`

### Google Search Usage

The tool accepts a required `query` parameter and an optional `site_restrict` domain. It returns up to 10 structured results with title, link, snippet, and display link fields.

## Google Sheets Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing
3. Enable the Google Sheets API
4. Create OAuth 2.0 credentials (Web application type)
5. Add your site's URL as authorized redirect URI
6. Copy Client ID and Client Secret to Data Machine settings
7. Authenticate the handler

## PageSpeed Insights

PageSpeed Insights runs Google Lighthouse audits against any URL. It works without an API key, but Google may rate-limit anonymous requests. Add an optional Google API key in the Data Machine tool settings under **PageSpeed** for higher limits.

Data Machine Business registers the same PageSpeed surfaces that previously lived in Data Machine core:

- AI tool: `pagespeed`
- Ability: `datamachine/pagespeed`
- REST endpoint: `POST /wp-json/datamachine/v1/analytics/pagespeed`
- WP-CLI command: `wp datamachine analytics pagespeed <action>`

Existing PageSpeed API-key config is adopted automatically because the integration continues to use the `datamachine_pagespeed_config` site option.

### PageSpeed Actions

| Action | Description |
|--------|-------------|
| `analyze` | Full Lighthouse audit with category scores and key metrics |
| `performance` | Focused performance score and Core Web Vitals metrics |
| `opportunities` | Optimization opportunities sorted by estimated savings |

### PageSpeed CLI Examples

```bash
wp datamachine analytics pagespeed analyze --page-url="https://example.com" --strategy=mobile
wp datamachine analytics pagespeed performance --page-url="https://example.com" --strategy=desktop
wp datamachine analytics pagespeed opportunities --page-url="https://example.com" --format=json
```

### PageSpeed REST Example

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/analytics/pagespeed \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: {nonce}" \
  --cookie "{auth_cookie}" \
  -d '{"action":"analyze","url":"https://example.com/","strategy":"desktop"}'
```

## Usage

### Fetch Handler
Fetches data from Google Sheets with three processing modes:
- **By Row**: Process one row at a time (deduplication supported)
- **By Column**: Process one column at a time
- **Full Spreadsheet**: Process entire sheet at once

### Publish Handler
Appends structured data to Google Sheets with customizable column mapping.

## Slack Setup

Slack integration uses a **Bot Token** (`xoxb-...`) rather than OAuth2. The token is long-lived and managed in your Slack App settings.

### Creating a Slack App

1. Go to [Slack API: Applications](https://api.slack.com/apps)
2. Click **Create New App** → **From scratch**
3. Give it a name (e.g., "Data Machine") and select your workspace

### Adding Bot Token Scopes

1. Go to **OAuth & Permissions** in the sidebar
2. Under **Bot Token Scopes**, add:
   - `chat:write` — Send messages
   - `channels:history` — Read messages from public channels
   - `groups:history` — Read messages from private channels
   - `channels:read` — List public channels (optional)
   - `groups:read` — List private channels (optional)
3. Click **Install to Workspace** (or reinstall if already installed)
4. Copy the **Bot OAuth Token** (`xoxb-...`)

### Configuring Data Machine

1. Go to Data Machine → Settings in WordPress admin
2. Find the Slack provider configuration
3. Paste your Bot OAuth Token
4. Click **Validate** to verify the connection

### Adding the Bot to Channels

The bot must be explicitly added to any channel it should post to or read from:
- Open the channel in Slack
- Type `/invite @Data Machine` (or whatever you named your app)

## Slack Usage

### Publish Handler
Posts messages to a configured Slack channel. Supports:
- Plain text and Slack mrkdwn formatting
- Source URL appending
- Thread replies (reply to a specific message)
- Link unfurling (rich previews)

### Fetch Handler
Fetches messages from a configured Slack channel with:
- Configurable message limit (1-1000)
- Time-based filtering (oldest/latest timestamps)
- Per-message deduplication (skips already-processed messages)
- Automatic filtering of join/leave noise

### Abilities (REST API / Chat Tools)
- `datamachine/post-message-slack` — Post a message to any channel
- `datamachine/fetch-messages-slack` — Fetch messages from any channel

## Discord Setup

Discord integration uses a **Bot Token** from the Discord Developer Portal. The token is long-lived and managed in your application settings.

### Creating a Discord Bot

1. Go to [Discord Developer Portal](https://discord.com/developers/applications)
2. Click **New Application**, give it a name (e.g., "Data Machine")
3. Go to **Bot** in the sidebar
4. Click **Reset Token** and copy the token (you only see it once)
5. Under **Privileged Gateway Intents**, enable any intents your use case requires

### Adding Bot Token Scopes

1. Go to **OAuth2** → **URL Generator** in the sidebar
2. Under **Scopes**, select `bot`
3. Under **Bot Permissions**, select:
   - `Send Messages` — Post messages to channels
   - `Read Message History` — Fetch messages from channels
4. Copy the generated URL and open it to invite the bot to your server

### Configuring Data Machine

1. Go to Data Machine → Settings in WordPress admin
2. Find the Discord provider configuration
3. Paste your Bot Token
4. Click **Validate** to verify the connection

### Adding the Bot to Channels

The bot can see channels based on its server permissions:
- Ensure the bot's role has **Read Messages** and **Send Messages** permissions in the target channel
- For private channels, explicitly grant access to the bot's role

## Discord Usage

### Publish Handler
Posts messages to a configured Discord channel. Supports:
- Plain text messages
- Source URL appending
- Discord embed objects for rich formatting

### Fetch Handler
Fetches messages from a configured Discord channel with:
- Configurable message limit (1-100)
- Pagination via before/after message IDs
- Per-message deduplication (skips already-processed messages)
- Automatic filtering of join/leave and system messages

### Abilities (REST API / Chat Tools)
- `datamachine/post-message-discord` — Post a message to any channel
- `datamachine/fetch-messages-discord` — Fetch messages from any channel

## Amazon Affiliate Link Tool

The `amazon_affiliate_link` AI tool searches Amazon products and returns an affiliate URL, product title, thumbnail, and ASIN. It is available in Data Machine chat and pipeline contexts when Data Machine Business is active.

Existing credentials saved by older Data Machine core versions are adopted automatically because the extension uses the same `datamachine_amazon_config` site option.

### Amazon Setup

1. Join or open Amazon Associates.
2. Create Amazon Creators API credentials.
3. In Data Machine tool settings, configure Credential ID, Credential Secret, Partner Tag, and Marketplace.
4. Use `amazon_affiliate_link` only for genuinely relevant product references.

## License

GPL v2 or later

## Author

Chris Huber - [chubes.net](https://chubes.net)
