# OAuth Handlers

**Location**: `/inc/Core/OAuth/`

## Overview

Data Machine uses a unified base class architecture for authentication providers, eliminating code duplication across all OAuth 1.0a and OAuth 2.0 implementations. All authentication providers extend standardized base classes that centralize option storage, configuration management, and authentication validation.

## Base Authentication Architecture (@since v0.2.6)

### BaseAuthProvider

**Location**: `/inc/Core/OAuth/BaseAuthProvider.php`
**Since**: v0.2.6

Abstract base class providing core functionality for all authentication providers.

**Key Features**:
- Centralized option storage and retrieval via WordPress options
- Unified callback URL generation
- Configuration and authentication state checking
- Account data management (save, retrieve, clear)

**Core Methods**:

```php
// Abstract methods (must be implemented by child classes)
abstract public function get_config_fields(): array;
abstract public function is_authenticated(): bool;

// Concrete methods (inherited by all providers)
public function __construct(string $provider_slug);
public function is_configured(): bool;
public function get_callback_url(): string;
public function get_site_account(): ?array;
public function save_site_account(array $account): bool;
public function delete_site_account(): bool;
public function get_account_for_user(int $user_id): ?array;
public function save_account_for_user(int $user_id, array $account): bool;
public function delete_account_for_user(int $user_id): bool;
public function get_account_for_agent(int $agent_id): ?array;
public function save_account_for_agent(int $agent_id, array $account): bool;
public function delete_account_for_agent(int $agent_id): bool;
public function get_account(): array;
public function get_config(): array;
public function save_account(array $data): bool;
public function save_config(array $data): bool;
public function clear_account(): bool;
public function get_account_details(): ?array;
```

**Storage Pattern**:

All providers store data in WordPress options using a consistent structure:

```php
// Data stored in 'datamachine_auth_data' option
[
    'provider_slug' => [
        'config' => [
            'client_id' => '...',
            'client_secret' => '...'
        ],
        'account' => [
            'access_token' => '...',
            'refresh_token' => '...',
            'user_id' => '...'
        ]
    ]
]
```

### Site-wide account API (@since v0.128.0)

Three concrete methods on `BaseAuthProvider` let callers operate on the shared
site-wide account slot without consulting auth scope policy:

```php
public function get_site_account(): ?array;
public function save_site_account( array $account ): bool;
public function delete_site_account(): bool;
```

Use these methods for shared bot accounts, scheduled flows, and other cases
where the credential is intentionally not owned by a specific user or agent.
`get_site_account()` returns `null` when no site-wide account exists. The
legacy `get_account()` method continues to return an empty array for missing
accounts and remains unchanged during the migration window.

### Per-user account API (@since v0.123.0)

Three concrete methods on `BaseAuthProvider` let callers operate on a specific
human user's credentials, independent of the site-wide account slot:

```php
public function get_account_for_user( int $user_id ): ?array;
public function save_account_for_user( int $user_id, array $account ): bool;
public function delete_account_for_user( int $user_id ): bool;
```

These share the same on-disk shape as the principal-scoped API
(`principals[user:<id>][account]`), so an account written via either surface
is readable through the other. They differ from the scope-aware
`get_account( array $context )` in two ways:

1. **No scope policy consultation.** A caller invoking
   `get_account_for_user( 101 )` has made a deliberate choice — the return
   value reflects that user's slot or null.
2. **No site fallback.** A missing per-user account always returns `null`,
   never the shared site account. This prevents cross-user leakage in
   callers that did not explicitly opt in to a site fallback.

Resolution order inside `get_account_for_user()`:

1. **`datamachine_resolve_oauth_account_for_user` filter** — platform
   plugins return a non-null array to plug in their own per-user storage
   (custom table, external KMS, encrypted blob). Returning null lets the
   default path run. Filter results are NOT re-decrypted: platform plugins
   are responsible for returning already-plaintext account data.
2. **Default storage** at `principals[user:<id>][account]` (encrypted at
   rest, decrypted on read).
3. **`null`** if neither yields an account.

Every successful resolve emits a `debug`-level entry via the
`datamachine_log` action with `{ provider, user_id, source }`. Failed
lookups do not log (they would dwarf real signal during normal "no account
yet" probes). The token itself is never logged at any level.

### Per-agent account API (@since v0.129.0)

Three concrete methods on `BaseAuthProvider` let callers operate on credentials
delegated to a specific agent, independent of the site-wide and per-user account
slots:

```php
public function get_account_for_agent( int $agent_id ): ?array;
public function save_account_for_agent( int $agent_id, array $account ): bool;
public function delete_account_for_agent( int $agent_id ): bool;
```

These share the same on-disk shape as the principal-scoped API
(`principals[agent:<id>][account]`), so an account written via either surface is
readable through the other. Like the per-user API, these methods never consult
auth scope policy and never fall back to site-wide storage.

#### When to use per-user vs. site-wide credentials

```
Decision flow:

  Does the agent need credentials tied to a specific human user?
   │
   ├── Yes ──→ Use per-user API (get_account_for_user)
   │           Caller resolves calling_user_id from $payload first.
   │
   └── No  ──→ Use site-wide API (get_account, no context)
               Bot accounts, shared posting accounts, scheduled flows.
```

Use **per-user** for any workflow where the credential authorizes access to
a specific human's data: "read my files on the upstream service", "post to
my own account", "summarize my mailbox". The agent must know who is asking
— see the `calling_user_id` section below.

Use **site-wide** for shared automation: the platform's own bot account, a
single shared posting account, anything where "whose account is this?" is
not a meaningful question.

#### Vendor-plugin pattern

A vendor plugin (`data-machine-business`, `data-machine-socials`, etc.)
registers an ability that reads its own credentials. Inside the ability's
execute callback:

```php
use function DataMachine\Engine\AI\datamachine_get_calling_user_id;

public function execute_my_handler( array $input, array $context = array() ) {
    $calling_user_id = datamachine_get_calling_user_id( $context['payload'] ?? array() );
    $provider        = my_get_auth_provider();  // returns a BaseAuthProvider subclass

    if ( $calling_user_id > 0 ) {
        $account = $provider->get_account_for_user( $calling_user_id );
        if ( null === $account ) {
            return new \WP_Error(
                'no_per_user_credentials',
                __( 'Connect your account before using this tool.', 'my-plugin' )
            );
        }
    } else {
        // No human caller — fall back to site-wide or refuse, depending on
        // the handler's policy. Shared bot accounts use this branch.
        $account = $provider->get_account();
        if ( empty( $account['access_token'] ) ) {
            return new \WP_Error( 'no_credentials', __( 'Not connected.', 'my-plugin' ) );
        }
    }

    // ... use $account['access_token'] to call upstream.
}
```

#### Revocation

```bash
# Site-wide revoke.
wp datamachine auth revoke <handler>

# Per-user revoke.
wp datamachine auth revoke <handler> --user=42
```

`wp datamachine auth disconnect <handler>` is kept as a deprecated site-wide
alias for `revoke` so existing scripts continue to run during the CLI verb
transition. New callsites should use `revoke`.

Or via the abilities surface:

```php
$result = wp_get_ability( 'datamachine/revoke-auth-for-user' )->execute(
    array(
        'handler_slug' => 'my_handler',
        'user_id'      => 42,
    )
);
```

If the provider exposes a `revoke_token_for_user( int $user_id )` method,
the abilities executor calls it BEFORE local deletion so the upstream
credential is invalidated even if local storage is later restored from
backup. An upstream failure logs a warning but does not block local
deletion — losing local access is preferable to leaking credentials.

#### Calling-user identity in AI invocations

`calling_user_id` is a new field on every AI invocation payload, alongside
the existing `agent_id`. It identifies the human user on whose behalf the
agent is acting during *this specific* invocation:

| Flow                     | `calling_user_id` value                        |
|--------------------------|------------------------------------------------|
| Chat session             | the chat caller's user ID                      |
| Pipeline execution       | `0` (no human caller; scheduled work)          |
| System task              | `0` (alt-text, meta, internal-linking, etc.)   |
| REST chat with bearer    | the bearer-owner user ID                       |
| processPing health check | `0` (admin user_id borrowed for storage only)  |

Tools and directives read it with the consumer helper:

```php
use function DataMachine\Engine\AI\datamachine_get_calling_user_id;

$calling_user_id = datamachine_get_calling_user_id( $payload );
// Returns 0 when absent, non-numeric, or non-positive.
```

`calling_user_id` is intentionally distinct from `agent_id` (the acting
agent identity, same across invocations for one agent) and from pipeline
`user_id` (the flow/job owner — typically an admin who scheduled the work,
not someone "calling" the agent right now).

#### Migration

Existing installs need no action. The legacy site-wide flow
(`get_account()` / `save_account()` without a context arg) continues to
work unchanged. Tokens stored before the encryption-at-rest feature landed
in v0.88.0 are lazy-migrated on next save. Per-user storage activates only
when callers opt in via `get_account_for_user( $user_id )` or by setting
`calling_user_id > 0` in the payload.

For production, define `DATAMACHINE_OAUTH_ENCRYPTION_KEY` is **not**
required: keys are derived from `wp_salt( 'auth' )`, which already pulls
from `AUTH_KEY` / `SECURE_AUTH_KEY` / `LOGGED_IN_KEY` in `wp-config.php`.
Operators who want explicit key isolation can rotate `AUTH_KEY` to force
re-encryption on next save (but be aware that doing so invalidates any
encrypted values not re-saved).

The account API is moving toward explicit named scope methods so vendor
plugins can choose site, user, agent, or policy-resolved credentials without
relying on context-array fallback semantics. See
[OAuth Account Scope API](../architecture/oauth-account-scope-api.md) for the
proposed migration path.

### BaseOAuth1Provider

**Location**: `/inc/Core/OAuth/BaseOAuth1Provider.php`
**Since**: v0.2.6

Base class for OAuth 1.0a authentication providers extending BaseAuthProvider.

**Features**:
- OAuth1Handler instance for three-legged flow
- Standardized configuration validation (api_key, api_secret)
- Authentication validation (access_token, access_token_secret)

**Abstract Methods**:

```php
abstract public function get_authorization_url(): string;
abstract public function handle_oauth_callback();
```

**Providers Using BaseOAuth1Provider**:

Core data-machine does not ship any concrete OAuth1 providers. Extensions register their own — for example, `data-machine-socials` ships `TwitterAuth` for tweet publishing.

**Example Implementation**:

```php
class TwitterAuth extends BaseOAuth1Provider {

    public function __construct() {
        parent::__construct('twitter');
    }

    public function get_config_fields(): array {
        return [
            'api_key' => [
                'label' => __('API Key', 'datamachine'),
                'type' => 'text',
                'required' => true
            ],
            'api_secret' => [
                'label' => __('API Secret', 'datamachine'),
                'type' => 'text',
                'required' => true
            ]
        ];
    }

    public function get_authorization_url(): string {
        $config = $this->get_config();
        $request_token = $this->oauth1->get_request_token(
            'https://api.twitter.com/oauth/request_token',
            $config['api_key'],
            $config['api_secret'],
            $this->get_callback_url(),
            'twitter'
        );

        return $this->oauth1->get_authorization_url(
            'https://api.twitter.com/oauth/authenticate',
            $request_token['oauth_token'],
            'twitter'
        );
    }

    public function handle_oauth_callback() {
        $config = $this->get_config();

        $this->oauth1->handle_callback(
            'twitter',
            'https://api.twitter.com/oauth/access_token',
            $config['api_key'],
            $config['api_secret'],
            function($access_token_data) {
                return [
                    'access_token' => $access_token_data['oauth_token'],
                    'access_token_secret' => $access_token_data['oauth_token_secret'],
                    'user_id' => $access_token_data['user_id'],
                    'screen_name' => $access_token_data['screen_name']
                ];
            },
            [$this, 'save_account']
        );
    }
}
```

### BaseOAuth2Provider

**Location**: `/inc/Core/OAuth/BaseOAuth2Provider.php`
**Since**: v0.2.0 (enhanced in v0.2.6)

Base class for OAuth 2.0 authentication providers extending BaseAuthProvider.

**Features**:
- OAuth2Handler instance for authorization code flow
- Standardized configuration validation (client_id, client_secret)
- Authentication validation (access_token presence)
- Account details formatting with username, scope, refresh timestamps

**Abstract Methods**:

```php
abstract public function get_config_fields(): array;
abstract public function get_authorization_url(): string;
abstract public function handle_oauth_callback();
```

**Optional Methods**:

```php
public function refresh_token(): bool; // Token refresh implementation
```

**Providers Using BaseOAuth2Provider**:

Core data-machine ships only `EmailAuth` (`inc/Core/Steps/Fetch/Handlers/Email/EmailAuth.php`). Concrete OAuth2 social/event providers live in extension plugins:

- `data-machine-socials` — `RedditAuth`, `FacebookAuth`, `ThreadsAuth`, etc.
- `data-machine-events` — `DiceFmAuth`, `TicketmasterAuth`, etc.

**Example Implementation**:

```php
class RedditAuth extends BaseOAuth2Provider {

    public function __construct() {
        parent::__construct('reddit');
    }

    public function get_config_fields(): array {
        return [
            'client_id' => [
                'label' => __('Client ID', 'datamachine'),
                'type' => 'text',
                'required' => true
            ],
            'client_secret' => [
                'label' => __('Client Secret', 'datamachine'),
                'type' => 'text',
                'required' => true
            ]
        ];
    }

    public function get_authorization_url(): string {
        $config = $this->get_config();
        $state = $this->oauth2->create_state('reddit');

        $params = [
            'client_id' => $config['client_id'],
            'response_type' => 'code',
            'state' => $state,
            'redirect_uri' => $this->get_callback_url(),
            'duration' => 'permanent',
            'scope' => 'identity read'
        ];

        return $this->oauth2->get_authorization_url(
            'https://www.reddit.com/api/v1/authorize',
            $params
        );
    }

    public function handle_oauth_callback() {
        $config = $this->get_config();

        $this->oauth2->handle_callback(
            'reddit',
            'https://www.reddit.com/api/v1/access_token',
            [
                'grant_type' => 'authorization_code',
                'code' => $_GET['code'],
                'redirect_uri' => $this->get_callback_url()
            ],
            function($token_data) {
                return [
                    'access_token' => $token_data['access_token'],
                    'refresh_token' => $token_data['refresh_token'],
                    'username' => $this->fetch_username($token_data['access_token']),
                    'scope' => $token_data['scope'],
                    'token_expires_at' => time() + $token_data['expires_in']
                ];
            },
            null,
            [$this, 'save_account']
        );
    }

    public function refresh_token(): bool {
        $account = $this->get_account();
        $config = $this->get_config();

        // Refresh logic using $account['refresh_token']
        // Update account data via $this->save_account()

        return true;
    }
}
```

### Bluesky Authentication (BaseAuthProvider Direct Extension)

**Provider**: BlueskyAuth
**Location**: the Bluesky handler in the `data-machine-socials` extension plugin
**Since**: v0.1.0 (updated to extend BaseAuthProvider in v0.2.6)

Bluesky authentication uses app password authentication and extends BaseAuthProvider directly. The implementation lives in the `data-machine-socials` extension plugin; the example below shows the pattern any extension can follow.

**Implementation Pattern**:

```php
class BlueskyAuth extends BaseAuthProvider {

    public function __construct() {
        parent::__construct('bluesky');
    }

    public function get_config_fields(): array {
        return [
            'username' => [
                'label' => __('Bluesky Handle', 'datamachine'),
                'type' => 'text',
                'required' => true,
                'description' => __('Your Bluesky handle (e.g., user.bsky.social)', 'datamachine')
            ],
            'app_password' => [
                'label' => __('App Password', 'datamachine'),
                'type' => 'password',
                'required' => true,
                'description' => __('Generate an app password at bsky.app/settings/app-passwords', 'datamachine')
            ]
        ];
    }

    public function is_authenticated(): bool {
        $config = $this->get_config();
        return !empty($config) &&
               !empty($config['username']) &&
               !empty($config['app_password']);
    }

    public function authenticate(): bool {
        $config = $this->get_config();

        // Validate credentials with API
        $session = $this->create_session(
            $config['username'],
            $config['app_password']
        );

        if (is_wp_error($session)) {
            return false;
        }

        // Store session data
        return $this->save_config([
            'username' => $config['username'],
            'app_password' => $config['app_password'],
            'session_token' => $session['accessJwt'],
            'did' => $session['did']
        ]);
    }
}
```

## OAuth Handler Services

### OAuth2Handler

**Location**: `/inc/Core/OAuth/OAuth2Handler.php`

Centralized OAuth 2.0 authorization code flow handler used by all OAuth2 providers.

**Key Methods**:

```php
public function create_state(string $provider_key): string;
public function verify_state(string $provider_key, string $state): bool;
public function get_authorization_url(string $auth_url, array $params): string;
public function handle_callback(
    string $provider_key,
    string $token_url,
    array $token_params,
    callable $account_details_fn,
    ?callable $token_transform_fn = null,
    ?callable $save_fn = null
): bool;
```

**State Management**:
- CSRF protection via WordPress transients
- 15-minute expiration window
- Automatic cleanup on verification

**Token Exchange**:
- Authorization code to access token exchange
- Optional token transformation (e.g., Meta long-lived tokens)
- Account details retrieval via callback
- Storage via custom save function or default filter

### OAuth1Handler

**Location**: `/inc/Core/OAuth/OAuth1Handler.php`

Centralized OAuth 1.0a three-legged flow handler used by OAuth1 providers.

**Key Methods**:

```php
public function get_request_token(
    string $request_token_url,
    string $consumer_key,
    string $consumer_secret,
    string $callback_url,
    string $provider_key = 'oauth1'
): array|WP_Error;

public function get_authorization_url(
    string $authorize_url,
    string $oauth_token,
    string $provider_key = 'oauth1'
): string;

public function handle_callback(
    string $provider_key,
    string $access_token_url,
    string $consumer_key,
    string $consumer_secret,
    callable $account_details_fn,
    ?callable $save_fn = null
): bool;
```

**Temporary Token Management**:
- Transient storage with provider and token scoping
- 15-minute expiration for request tokens
- Automatic cleanup after exchange

## Provider Registration via Filters

Concrete OAuth providers self-register via the `datamachine_auth_providers` filter (typically through `HandlerRegistrationTrait`). Core data-machine does not ship a central providers directory; every concrete provider lives next to its handler in either core or an extension plugin.

**In core data-machine:**

- `EmailAuth` — `inc/Core/Steps/Fetch/Handlers/Email/EmailAuth.php`

**In extension plugins:**

- `data-machine-socials` — Bluesky, Twitter, Reddit, Facebook, Threads, etc.
- `data-machine-events` — Dice.fm, Ticketmaster, etc.

**Reusable provider pattern**:

When multiple handlers in the same plugin need the same OAuth2 provider (e.g. a fetch and publish handler against the same API), implement the auth provider once and inject it into both handlers. The shared instance lives next to its handlers (e.g. `<plugin>/inc/Handlers/<Service>/<Service>Auth.php`).

```php
class MyServiceFetch extends FetchHandler {

    private $auth;

    public function __construct() {
        $this->auth = new MyServiceAuth();
    }

    public function fetch($flow_step_config, $job_id) {
        $access_token = $this->auth->get_service();

        if (is_wp_error($access_token)) {
            return $this->errorResponse($access_token->get_error_message());
        }

        // Use access token for API calls
    }
}
```

## Provider Integration Patterns

### Configuration Storage

All providers use BaseAuthProvider methods for configuration storage:

```php
// Save configuration
$config_data = [
    'client_id' => $client_id,
    'client_secret' => $client_secret
];
$this->save_config($config_data);

// Retrieve configuration
$config = $this->get_config();
$client_id = $config['client_id'] ?? '';
```

### Account Data Storage

All providers use BaseAuthProvider methods for account data:

```php
// Save account data
$account_data = [
    'access_token' => $access_token,
    'refresh_token' => $refresh_token,
    'user_id' => $user_id,
    'username' => $username
];
$this->save_account($account_data);

// Retrieve account data
$account = $this->get_account();
$access_token = $account['access_token'] ?? '';

// Clear account data
$this->clear_account();
```

### Authentication Checks

Base classes provide standardized authentication checks:

```php
// Check if configured
if (!$this->is_configured()) {
    return new WP_Error('not_configured', 'Provider not configured');
}

// Check if authenticated
if (!$this->is_authenticated()) {
    return new WP_Error('not_authenticated', 'User not authenticated');
}
```

## Security Features

**State Nonce Protection** (OAuth2):
- CSRF protection via WordPress transient system
- 15-minute expiration window
- Automatic cleanup on verification

**Temporary Token Management** (OAuth1):
- Transient storage with provider and token scoping
- 15-minute expiration for request tokens
- Automatic cleanup after exchange

**Input Sanitization**:
- All callback parameters sanitized via `sanitize_text_field()`
- WordPress `wp_unslash()` before sanitization
- Comprehensive error handling

**Redirect Security**:
- Success/error redirects to admin settings page
- Error codes passed via query parameters
- Admin capability required for OAuth URLs

**Centralized Storage**:
- All credentials stored in WordPress options
- No direct database access from providers
- Consistent encryption and security patterns

## Error Handling

**OAuth2 Errors**:
- `oauth_denied` - User denied authorization
- `invalid_state` - State verification failed
- `token_exchange_failed` - Token exchange error
- `token_transform_failed` - Token transformation error
- `account_fetch_failed` - Account details retrieval error
- `storage_failed` - Account data storage error

**OAuth1 Errors**:
- `access_denied` - User denied access
- `missing_parameters` - Missing oauth_token or oauth_verifier
- `token_secret_expired` - Temporary token secret expired
- `access_token_failed` - Access token exchange failed
- `storage_failed` - Account data storage error
- `request_token_failed` - Request token retrieval failed

**Logging**:

All OAuth operations logged via `datamachine_log` action:

```php
do_action('datamachine_log', 'info', 'OAuth2: Authentication successful', [
    'provider' => $provider_key,
    'account_id' => $account_data['id']
]);

do_action('datamachine_log', 'error', 'OAuth1: Failed to get access token', [
    'provider' => $provider_key,
    'http_code' => $http_code
]);
```

## Benefits of Base Class Architecture

**Code Elimination**:
- Removes duplicated storage logic across all providers
- Eliminates redundant configuration validation code
- Centralizes callback URL generation
- Unified authentication state checking

**Consistency**:
- Identical option storage patterns across all providers
- Standardized error handling and logging
- Uniform security implementation
- Consistent API for all authentication types

**Maintainability**:
- Single point of update for storage improvements
- Centralized security enhancements
- Easier debugging with consistent patterns
- Reduced testing surface area

**Extensibility**:
- New providers integrate via simple base class extension
- Minimal boilerplate required for new authentication types
- Inherited functionality ensures feature parity
- Clear extension points for custom behavior

## Migration from Legacy Pattern

**Before** (v0.2.5 and earlier):

```php
class RedditAuth {
    public function get_account() {
        $all_auth = get_option('datamachine_auth_data', []);
        return $all_auth['reddit']['account'] ?? [];
    }

    public function save_account(array $data) {
        $all_auth = get_option('datamachine_auth_data', []);
        $all_auth['reddit']['account'] = $data;
        return update_option('datamachine_auth_data', $all_auth);
    }

    public function is_authenticated(): bool {
        $account = $this->get_account();
        return !empty($account) &&
               !empty($account['access_token']);
    }
}
```

**After** (v0.2.6):

```php
class RedditAuth extends BaseOAuth2Provider {
    public function __construct() {
        parent::__construct('reddit');
    }

    // Inherits: get_account(), save_account(), is_authenticated()
    // Only implements provider-specific logic
}
```

**Elimination**:
- Removed ~50 lines of storage code per provider
- Removed ~30 lines of validation code per provider
- Eliminated 6 duplicate implementations of identical patterns
- Reduced authentication provider code by approximately 60%

## Related Documentation

- Core Filters - OAuth service discovery filters
- Twitter Handler - OAuth1 implementation example
- Reddit Handler - OAuth2 implementation example
- Facebook Handler - OAuth2 with token transformation
- Bluesky Handler - Direct BaseAuthProvider extension example
- Settings Configuration - OAuth credential management

---

**Implementation**: `/inc/Core/OAuth/` directory ships `BaseAuthProvider`, `BaseOAuth1Provider`, and `BaseOAuth2Provider` base classes plus the `OAuth1Handler` and `OAuth2Handler` services. Concrete providers ship in core (`EmailAuth`) and in extension plugins (`data-machine-socials`, `data-machine-events`).
**Architecture**: Inheritance-based provider system with centralized storage and validation
