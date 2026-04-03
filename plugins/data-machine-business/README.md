# Data Machine Business

Business and enterprise integrations for Data Machine.

## Description

This plugin extends Data Machine with business-focused integrations including:

- **Google Sheets**: Fetch data from spreadsheets and append data for reporting
- **Slack**: Post messages and fetch conversations from channels
- **Discord** (planned): Post messages and fetch server data

## Requirements

- WordPress 6.9+
- PHP 8.2+
- Data Machine core plugin (required)

## Installation

1. Install and activate Data Machine core plugin
2. Upload and activate this plugin
3. Configure Google Sheets authentication in Data Machine settings
4. Create flows using the Google Sheets handlers

## Google Sheets Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing
3. Enable the Google Sheets API
4. Create OAuth 2.0 credentials (Web application type)
5. Add your site's URL as authorized redirect URI
6. Copy Client ID and Client Secret to Data Machine settings
7. Authenticate the handler

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

## License

GPL v2 or later

## Author

Chris Huber - [chubes.net](https://chubes.net)
