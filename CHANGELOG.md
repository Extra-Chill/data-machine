# Changelog

All notable changes to Data Machine will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-02-02

### Changed
- **BREAKING**: Renamed `enabled_tools` to `disabled_tools` in AI step configuration
  - Empty array now means "use all globally enabled tools" (no exclusions)
  - Non-empty array explicitly excludes those tools from the step
  - Old `enabled_tools` config is ignored (graceful degradation)
- Tool enablement logic: `Available = Globally enabled − Step disabled`

### Fixed
- Tool enablement bug where empty `enabled_tools` array disabled all tools instead of using defaults
- Clearer UX: "disable specific tools" is more intuitive than "enable from scratch"

## [0.19.11] - 2026-02-01

### Fixed
- Agent Ping handler slug fallback for non-handler steps

## [0.19.10] - 2026-02-01

### Added
- Auth header fields in Agent Ping React UI
- Optional authentication header support for Agent Ping webhooks

### Fixed
- Handle `url_list` array in Agent Ping validation and execution

## [0.19.9] - 2026-01-31

### Fixed
- Engine step failure detection from packet metadata
- Agent Ping `flow_id`/`pipeline_id` retrieval from `flow_step_config`

## [0.19.8] - 2026-01-31

### Added
- Multiple webhook URL support for Agent Ping (`url_list` field type)
- "+" button UI for adding multiple URLs

## [0.19.7] - 2026-01-30

### Fixed
- Step settings display suppression when already configured

## [0.19.6] - 2026-01-30

### Fixed
- CLI `--pipeline-config` flag handling
- Return `updated_fields` from `executeUpdatePipelineStep`
- CLI `--step` parameter declaration for queue commands
- Added `wp_unslash`, `is_array` guards, restored JSON output

## [0.19.0] - 2026-01-15

### Added
- QueueableTrait for Agent Ping steps - prompt queues for dynamic task scheduling
- Flow-level prompt queues with WP-CLI management
- Comprehensive AI tools system with global/step-level configuration

### Changed
- Major architecture improvements for step configuration
- Enhanced SettingsDisplayService for better config visibility

## [0.18.0] - 2025-12-01

### Added
- Agent Ping handler for external webhook triggers
- Pipeline step abilities API
- Local search tool for content discovery

### Changed
- Refactored handler configuration system
- Improved flow step configuration UI

---

For older versions, see git history.
