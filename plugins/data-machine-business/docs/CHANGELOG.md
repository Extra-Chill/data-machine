# Changelog

All notable changes to this project will be documented in this file.

Homeboy maintains this file from conventional commits at release time —
do not edit by hand.

## [0.5.0] - 2026-05-30

### Added
- expose hostName in GA4 page_stats + add network_density action
- add Media Hygiene ability + wp datamachine media CLI

## [0.4.1] - 2026-05-23

### Fixed
- register GoogleDrive abilities under datamachine-fetch category

## [0.4.0] - 2026-05-23

### Added
- add pagespeed integration
- feat(google-drive): add download-googledrive ability
- feat(google-drive): add read-googledrive-doc ability
- feat(google-drive): add list-googledrive-files ability

### Changed
- refactor(google-drive): extract Drive client into shared helper

### Fixed
- preserve scope on Google token refresh (closes data-machine#2167)

## [0.3.0] - 2026-05-21

### Added
- add search console integration
- register GA4 tooling
- add Bing Webmaster integration
- add Amazon affiliate link tool
- add Google Search tool
- feat(google-drive): prefer markdown export for Docs and surface file fields top-level
- feat(google-drive): add Drive fetch handler + rename Google auth (closes #7)

### Changed
- Add Discord integration with ability-first architecture
- Add Slack integration and refactor to ability-first architecture
- Add README with setup instructions
- Initial commit: Extract Google Sheets from data-machine core

### Fixed
- migrate Slack/Discord/Google* abilities to semantic categories
- defer Data Machine core check to plugins_loaded
