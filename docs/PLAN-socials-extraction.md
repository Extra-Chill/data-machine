# Data Machine Socials Extraction Plan

**Issue:** [#275](https://github.com/Extra-Chill/data-machine/issues/275)  
**Status:** Planned  
**Complexity:** Low  

## Overview

Extract social media publishing functionality from the core Data Machine plugin into a new `data-machine-socials` plugin. This modularizes the codebase and allows optional social media features.

## What Moves to Socials Plugin

**Publish Handlers:** (all social media destinations)
- `Twitter/Twitter.php` - Twitter/X publishing
- `Facebook/Facebook.php` - Facebook Pages publishing
- `Bluesky/Bluesky.php` - Bluesky publishing
- `Threads/Threads.php` - Meta Threads publishing
- `Pinterest/Pinterest.php` - Pinterest pinning

**Authentication Classes:**
- `Twitter/TwitterAuth.php` - OAuth 1.0a
- `Facebook/FacebookAuth.php` - OAuth 2.0
- `Bluesky/BlueskyAuth.php` - App password
- `Threads/ThreadsAuth.php` - OAuth 2.0
- `Pinterest/PinterestAuth.php` - Bearer token

**Settings Classes:**
- `Twitter/TwitterSettings.php`
- `Facebook/FacebookSettings.php`
- `Pinterest/PinterestSettings.php`
- `Threads/ThreadsSettings.php`

**Handler Documentation:**
- All social handler docs move to new plugin

## What Stays in Core

**Infrastructure:** (shared base classes)
- `PublishHandler.php` - base publish handler class
- `PublishHandlerSettings.php` - base settings class
- `BaseAuthProvider.php` - base auth provider
- `BaseOAuth1Provider.php` - OAuth 1.0a base
- `BaseOAuth2Provider.php` - OAuth 2.0 base
- `AuthAbilities.php` - centralized auth API
- `HandlerRegistrationTrait.php` - registration mechanism

**Content Handlers:**
- WordPress publish handler (core publishing)
- ALL fetch handlers: Reddit, RSS, WordPress API, WordPress Media, Files, Google Sheets

## Core Changes Required

**File: `data-machine.php`**

Remove handler instantiations (lines ~220-225):
```php
// REMOVE these lines:
new \DataMachine\Core\Steps\Publish\Handlers\Twitter\Twitter();
new \DataMachine\Core\Steps\Publish\Handlers\Facebook\Facebook();
new \DataMachine\Core\Steps\Publish\Handlers\Threads\Threads();
new \DataMachine\Core\Steps\Publish\Handlers\Bluesky\Bluesky();
new \DataMachine\Core\Steps\Publish\Handlers\Pinterest\Pinterest();
```

Keep WordPress and other handlers:
```php
// KEEP these:
new \DataMachine\Core\Steps\Publish\Handlers\WordPress\WordPress();
new \DataMachine\Core\Steps\Publish\Handlers\GoogleSheets\GoogleSheets();
```

## Socials Plugin Structure

```
data-machine-socials/
├── data-machine-socials.php        # Plugin header + instantiations
├── inc/
│   └── Handlers/
│       ├── Twitter/
│       │   ├── Twitter.php
│       │   ├── TwitterAuth.php
│       │   └── TwitterSettings.php
│       ├── Facebook/
│       │   ├── Facebook.php
│       │   ├── FacebookAuth.php
│       │   └── FacebookSettings.php
│       ├── Bluesky/
│       │   ├── Bluesky.php
│       │   └── BlueskyAuth.php
│       ├── Threads/
│       │   ├── Threads.php
│       │   ├── ThreadsAuth.php
│       │   └── ThreadsSettings.php
│       └── Pinterest/
│           ├── Pinterest.php
│           ├── PinterestAuth.php
│           └── PinterestSettings.php
└── docs/
    └── handlers/
        ├── twitter.md
        ├── facebook.md
        ├── bluesky.md
        ├── threads.md
        └── pinterest.md
```

## Why Reddit Stays in Core

The Reddit fetch handler remains in core because:

1. **Source vs Destination**: Fetch handlers are content *sources* (like RSS, WordPress API). Publish handlers are content *destinations* (Twitter, Facebook, etc.)
2. **No coupling**: Fetch handlers don't depend on publish handlers
3. **Use case**: "Fetch from Reddit, publish to WordPress" is a core workflow
4. **Conceptual clarity**: Socials plugin = "Publishing to social media platforms"

## Technical Details

**Registration Mechanism:**

Handlers register via `HandlerRegistrationTrait` using WordPress filters:
- `datamachine_handlers` - registers handler classes
- `datamachine_auth_providers` - registers auth providers
- `chubes_ai_tools` - registers AI tools

This means the socials plugin simply instantiates handler classes - no core code changes needed beyond removing the instantiations from core.

**Zero Breaking Changes:**
- Existing users install both plugins
- Same functionality, distributed across two plugins
- Socials plugin requires core plugin

## Migration Path

1. Create new `data-machine-socials` repository
2. Move social handler files
3. Create plugin header with dependency check
4. Move documentation
5. Remove instantiations from core
6. Test both plugins together
7. Update documentation to reflect modular structure

## Estimated Effort

- **File moves**: ~25-30 files
- **Code changes**: ~6 lines removed from core
- **New plugin setup**: ~100 lines (plugin header + instantiations)
- **Documentation**: Migrate existing docs

**Total**: 1-2 days for MVP

## Auth Considerations

`AuthAbilities` stays in core and remains extensible. The socials plugin provides auth providers that integrate with the core auth infrastructure. Other plugins can also register auth providers for their handlers.

## Decision Summary

| Component | Location | Reason |
|-----------|----------|--------|
| Twitter publish | Socials | Social destination |
| Facebook publish | Socials | Social destination |
| Bluesky publish | Socials | Social destination |
| Threads publish | Socials | Social destination |
| Pinterest publish | Socials | Social destination |
| Reddit fetch | Core | Content source |
| RSS fetch | Core | Content source |
| WordPress API fetch | Core | Content source |
| Auth infrastructure | Core | Shared base classes |
| Handler registration | Core | Extension point |
