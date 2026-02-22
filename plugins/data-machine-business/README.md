# Data Machine Business

Business and enterprise integrations for Data Machine.

## Description

This plugin extends Data Machine with business-focused integrations including:

- **Google Sheets**: Fetch data from spreadsheets and append data for reporting
- **Slack** (planned): Post messages and fetch conversations
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

## License

GPL v2 or later

## Author

Chris Huber - [chubes.net](https://chubes.net)
