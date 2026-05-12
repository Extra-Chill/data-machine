# Auth Endpoints

**Implementation**: `inc/Api/Auth.php`

**Base URL**: `/wp-json/datamachine/v1/auth`

Auth endpoints manage handler authentication and provider metadata. They are separate from WordPress REST authentication, which is documented in [Authentication](authentication.md).

## Authentication

Requires the Data Machine `manage_settings` permission (`PermissionHelper::can( 'manage_settings' )`).

## Response Envelope

Successful auth routes return either:

```json
{
  "success": true,
  "data": {}
}
```

or the successful ability result directly when the ability already includes `success` and related fields.

Failures are `WP_Error` responses. Common statuses are 400 for invalid input, 404 for missing providers/handlers, 409 for not-authenticated refresh/disconnect states, and 500 for internal failures.

## Routes

### GET `/wp-json/datamachine/v1/auth/providers`

List registered auth providers.

**Success shape**:

```json
{
  "success": true,
  "data": []
}
```

### GET `/wp-json/datamachine/v1/auth/{handler_slug}/status`

Check auth status for a handler slug. The handler slug is resolved through `AuthAbilities`, including handlers that share an auth provider key.

**Response data may include**:

- `handler_slug`
- `authenticated`
- `requires_auth`
- `message`
- `oauth_url`
- `instructions`

Handlers with `requires_auth: false` return success without OAuth state.

### PUT `/wp-json/datamachine/v1/auth/{handler_slug}`

Save auth configuration for a handler.

**Body**: provider-specific config fields. All request params except `handler_slug` are passed as `config` to `AuthAbilities::executeSaveAuthConfig()`.

### DELETE `/wp-json/datamachine/v1/auth/{handler_slug}`

Disconnect a handler account.

### PUT `/wp-json/datamachine/v1/auth/{handler_slug}/token`

Set account/token data manually.

**Body**: JSON object passed as `account_data` to `AuthAbilities::executeSetAuthToken()`.

### POST `/wp-json/datamachine/v1/auth/{handler_slug}/refresh`

Force a token refresh for a handler.

## Notes for Agents

- There is no `/auth/{handler_slug}/callback` REST route in `inc/Api/Auth.php`.
- Browser OAuth initiation/callback URLs are outside this REST controller, for example `/datamachine-auth/{handler}/`.
- Do not document static provider field sets here; provider config comes from the registered auth provider/handler metadata.
