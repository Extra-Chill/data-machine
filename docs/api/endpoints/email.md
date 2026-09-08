# Email Abilities

**Implementation**: `inc/Abilities/Email/EmailAbilities.php`, `inc/Abilities/Fetch/FetchEmailAbility.php`, `inc/Abilities/Publish/SendEmailAbility.php`, `inc/Abilities/Publish/SendEmailQueuedAbility.php`

The `datamachine/v1/email` REST routes were retired in #3456. Email operations are exposed through the REST-visible Data Machine abilities, executed through WordPress core's ability runner:

```
POST /wp-json/wp-abilities/v1/abilities/datamachine/<slug>/run
Content-Type: application/json

{ "input": { ... } }
```

## Authentication

Each ability's permission callback requires Data Machine manage permission (`PermissionHelper::can( 'use_tools' )` or `PermissionHelper::can_manage()`, meaning `manage_flows`, `manage_settings`, or `manage_agents`; administrators pass through the `manage_options` fallback). Agent tokens are additionally scoped per ability and category by the global `wp_ability_permission_result` filter. The core ability runner also requires `show_in_rest` and a valid REST nonce for cookie-authenticated callers.

Inbox operations require a configured `email_imap` auth provider; missing IMAP credentials return an `email_*` error with HTTP 400.

## Response Envelope

The ability runner returns the ability's output directly (no `{success, data}` wrapper). Errors return standard REST error objects (`code`, `message`, `data.status`).

## Email Abilities

Every ability takes `auth_ref` (non-secret mailbox auth ref, for example `email_imap:default`; default `email_imap:default`).

| Ability slug | Purpose |
| --- | --- |
| `datamachine/send-email` | Send an email (`to`, `subject`, `body`, optional `cc`, `bcc`, `from_name`, `from_email`, `reply_to`, `content_type`, `attachments`). Subject supports `{month}`, `{year}`, `{site_name}`, and `{date}` placeholders. |
| `datamachine/send-email-queued` | Queue an email for delivery via Action Scheduler (`send_at`, `priority`). |
| `datamachine/fetch-email` | Fetch messages (`folder`, `search_criteria`, `max_messages`, `offset`, `headers_only`, `mark_as_read`, `download_attachments`) or read one by `uid`. |
| `datamachine/email-reply` | Reply with threading headers (`in_reply_to`, optional `references`). |
| `datamachine/email-delete` | Delete one message by `uid`. |
| `datamachine/email-move` | Move one message to `destination` folder. |
| `datamachine/email-flag` | Set or clear an IMAP `flag` (`Seen`, `Flagged`, ...) with `action` `set`/`clear`. |
| `datamachine/email-batch-move` | Move messages matching an IMAP `search` (`destination`, `max`). |
| `datamachine/email-batch-flag` | Flag messages matching an IMAP `search` (`flag`, `action`, `max`). |
| `datamachine/email-batch-delete` | Delete messages matching an IMAP `search` (`max`). |
| `datamachine/email-unsubscribe` | Unsubscribe from a list using one message's headers (`uid`). |
| `datamachine/email-batch-unsubscribe` | Unsubscribe from lists matching an IMAP `search` (`max`). |
| `datamachine/email-test-connection` | Test stored IMAP credentials for the mailbox. |

## Search Strings

IMAP search strings follow the IMAP SEARCH syntax, for example `ALL`, `UNSEEN`, `FROM "github.com"`, or `SINCE "1-Mar-2026"`.

## Example

List unread message headers through the ability runner:

```bash
curl -X POST https://example.com/wp-json/wp-abilities/v1/abilities/datamachine/fetch-email/run \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"input":{"search_criteria":"UNSEEN","headers_only":true,"max_messages":20}}'
```
