# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [0.13.0] - 2026-04-26

### Changed
- return /platforms as ordered array with slug, filter fetch handlers, sort authenticated-first

## [0.12.1] - 2026-04-26

### Changed
- disable homeboy autofix

### Fixed
- resolve Instagram username via Facebook Graph API instead of graph.instagram.com, which rejects FB Login tokens with 'Cannot parse access token' and silently stored empty usernames
- return save_account result from OAuth storage callback
- resolve Instagram user ID from Facebook token and support fb_exchange_token
- use Facebook graph endpoint for token exchange
- use Facebook Login dialog for Instagram Graph API publishing

## [0.12.0] - 2026-04-22

### Added
- async social publishing via DM Jobs + Publisher utility

## [0.11.1] - 2026-04-22

### Fixed
- register datamachine-socials ability category and update all abilities

## [0.11.0] - 2026-04-21

### Added
- migrate SocialCrossPostTask to executeTask() contract

## [0.10.0] - 2026-04-21

### Added
- broaden social ability permissions from can_manage to use_tools (refs #110)

### Changed
- Revert "docs(changelog): add Unreleased section for 0.9.1 → next release"
- migrate aiToolCallback to new datamachine_tools signature
- restrict homeboy to audit-only (no refactor/autofix)
- Revert "Merge pull request #108 from Extra-Chill/ci/autofix/data-machine-socials/main"

## [0.9.1] - 2026-04-03

### Added
- add capabilities metadata to all social platform handlers

### Changed
- migrate REST namespace from datamachine-socials/v1 to datamachine/v1/socials

## [0.9.0] - 2026-04-02

### Added
- add SocialCrossPostTask for async cross-posting via DM Task System

### Changed
- Migrate all 293 ability error returns to WP_Error, remove 57 function_exists guards
- Add generic comments API with normalized SocialComment shape
- clean break — drop all legacy username keys from auth providers
- add concurrency group to cancel stale PR runs
- Add VoteReddit chat tool and BlueskySettings handler config
- drop cron schedule — push to main covers release

### Fixed
- normalize username storage across all social auth providers
- Fix missing check_edit_permission method and Pinterest class reference

## [0.8.1] - 2026-03-21

### Fixed
- remove duplicate get_auth_status and get_platforms methods in RestApi

## [0.8.0] - 2026-03-21

### Added
- add Reddit write commands (reply, submit, vote)

## [0.7.0] - 2026-03-21

### Added
- add global search to Reddit fetch ability, CLI, and chat tool

## [0.6.1] - 2026-03-21

### Changed
- replace hardcoded platform arrays with handler $meta registry

## [0.6.0] - 2026-03-21

### Added
- fold auth status into /platforms response, add LinkedIn

## [0.5.0] - 2026-03-21

### Added
- add cron and manual dispatch triggers for automated releases
- add homeboy-action v2 CI pipeline
- add fullstack LinkedIn integration with OAuth 2.0, CRUD posts, CLI, and chat tools
- add publish chat tools for all platforms and CLI publish commands for Facebook, Threads, Pinterest

### Fixed
- resolve merge conflict with main
- restore homeboy component identity

## [0.4.0] - 2026-03-16

### Added
- use core media primitives for video support across all handlers
- add SocialShareTracker for cross-platform share history
- add Instagram Story publishing support
- add Instagram Reel publishing + fix delete ability

### Changed
- use core PublishHandler::resolveMediaUrls() instead of per-handler duplication
- route all wrapper layers through wp_get_ability() registry

### Fixed
- sync DATAMACHINE_SOCIALS_VERSION to 0.3.0 and add constant to homeboy version targets

## [0.3.0] - 2026-03-11

### Added
- add Instagram comment reply primitive
- add Pinterest analytics ability

### Changed
- add Homeboy config for socials plugin

## [0.2.1] - 2026-03-09

### Fixed
- Update all 19 chat tool `registerTool()` calls for Data Machine 0.39.0 compatibility

## [0.1.0] - 2025-02-21

### Added

- Initial release
- Extract social handlers from Data Machine core plugin
- Support for Twitter/X publishing with OAuth 1.0a
- Support for Facebook Pages publishing with OAuth 2.0
- Support for Bluesky publishing with app password authentication
- Support for Meta Threads publishing with OAuth 2.0
- Support for Pinterest pinning with bearer token authentication
- Handler documentation for all platforms
- Composer dependencies for abraham/twitteroauth

[0.1.0]: https://github.com/Extra-Chill/data-machine-socials/releases/tag/v0.1.0
