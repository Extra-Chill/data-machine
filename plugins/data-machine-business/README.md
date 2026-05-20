# Data Machine Business

Business and enterprise integrations for Data Machine.

## Description

This plugin extends Data Machine with business-focused integrations including:

- **Google Search**: Search the web through Google Custom Search API as an AI tool
- **Google Sheets**: Fetch data from spreadsheets and append data for reporting
- **Google Analytics (GA4)**: Fetch visitor analytics, realtime, engagement, and comparison metrics
- **Google Search Console**: Read search analytics, inspect URLs, and manage sitemaps
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

## Google Analytics (GA4) Setup

Data Machine Business registers the `datamachine/google-analytics` ability and `google_analytics` chat/pipeline tool. Data Machine core keeps the compatible REST and WP-CLI dispatchers (`/analytics/ga` and `wp datamachine analytics ga`), which work when this plugin is active.

Existing Data Machine GA4 settings are adopted automatically because this plugin uses the same option key, `datamachine_ga_config`, and token transient, `datamachine_ga_access_token`.

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Enable the Google Analytics Data API
3. Create a service account and download its JSON key
4. Grant the service account access to the GA4 property
5. Configure the Service Account JSON and GA4 Property ID in Data Machine tool settings

### GA4 Ability And Tool

- `datamachine/google-analytics` — Fetches GA4 reports and realtime analytics
- `google_analytics` — Chat and pipeline tool wrapper for AI agents

Supported actions include `page_stats`, `traffic_sources`, `date_stats`, `realtime`, `top_events`, `user_demographics`, `landing_pages`, `engagement`, and `new_vs_returning`.

## Google Sheets Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing
3. Enable the Google Sheets API
4. Create OAuth 2.0 credentials (Web application type)
5. Add your site's URL as authorized redirect URI
6. Copy Client ID and Client Secret to Data Machine settings
7. Authenticate the handler

## Google Search Console Setup

Google Search Console uses a Google service account, not the shared Google OAuth user credential.

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create or select a project with the Search Console APIs enabled
3. Create a service account and JSON key
4. Add the service account email as a user on the Search Console property
5. Configure the `google_search_console` tool in Data Machine settings with the JSON key and property URL

Existing Data Machine core installations keep using the same `datamachine_gsc_config` option, so activating Data Machine Business adopts previously saved Search Console configuration automatically.

### Google Search Console Usage

- AI tool: `google_search_console`
- Ability: `datamachine/google-search-console`
- REST: `POST /wp-json/datamachine/v1/analytics/gsc`
- WP-CLI: `wp datamachine analytics gsc query_stats`

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
