# Changelog

All notable changes to this project will be documented in this file.

Homeboy maintains this file from conventional commits at release time —
do not edit by hand.

## [0.16.0] - 2026-07-17

### Added
- expose Mediavine dimensional reports

## [0.15.0] - 2026-07-16

### Added
- expose GA page acquisition reports
- expose GSC audience segmentation

## [0.14.1] - 2026-07-16

### Changed
- make Mediavine provenance schema gates dependency-free

### Fixed
- aggregate canonical GSC page opportunities
- reconcile GA comparison rows by dimensions

## [0.14.0] - 2026-07-13

### Added
- preserve Mediavine report provenance for downstream attribution

## [0.13.4] - 2026-07-13

### Changed
- centralize business ability registration

## [0.13.3] - 2026-07-12

### Fixed
- restore GSC row click initialization

## [0.13.2] - 2026-07-12

### Fixed
- update Mediavine reporting API contract (#66)
- preserve SERP-captured GSC visibility

## [0.13.1] - 2026-07-11

### Fixed
- discount SERP-captured definition-box queries in GSC opportunity scorer
- enumerate flat __invoke command actions in AGENTS.md section

## [0.13.0] - 2026-07-03

### Added
- auto-scope GSC ability to subsite when on a genuine subdomain

### Fixed
- constrain GSC site_url to the verified property and clarify 403s

## [0.12.0] - 2026-06-28

### Added
- repoint GSC and PageSpeed analytics routes to view_analytics cap

### Changed
- remove hardcoded Extra Chill knowledge from Analytics abilities

## [0.11.1] - 2026-06-27

### Fixed
- allow public/anonymous use of sendy-subscribe ability

## [0.11.0] - 2026-06-21

### Added
- generic Sendy integration (API + read-only metrics)
- GSC opportunity auditor
- mediavine-reports ability (direct GraphQL+CSV fetch)

## [0.10.0] - 2026-06-21

### Added
- content-flags confidence guards (low-sample + query-intent caveat)

## [0.9.0] - 2026-06-21

### Added
- content red-flag detector ability (deterministic triage signatures)

### Changed
- make content-flags outcome-first per empirical validation

## [0.8.0] - 2026-06-21

### Added
- add content-performance ability for within-category engagement audit

### Changed
- align assignment operators in content-performance ability (phpcbf)

## [0.7.0] - 2026-06-16

### Added
- own the google-analytics CLI command in business

### Changed
- reflect DMB AGENTS.md CLI surface via shared introspector

## [0.6.1] - 2026-06-14

### Fixed
- filter network_density GA report to in-network referrers server-side (closes #36)

## [0.6.0] - 2026-06-13

### Added
- add GA4 path_sequence action for true ordered cross-host journeys

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
