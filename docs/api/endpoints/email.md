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

Message operations take `auth_ref`, a non-secret mailbox handle such as `email_imap:work` or `email_imap:personal`. Legacy operations default to `email_imap:default`; forwarding requires an explicit authorized inbox. Mailbox management operates on the current user/agent rather than accepting an arbitrary owner ID.

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
| `datamachine/email-mailboxes` | List accessible named inboxes and non-secret connection metadata. |
| `datamachine/email-mailbox-connect` | Connect or update one inbox (`name`, `credentials`) owned by the current principal. |
| `datamachine/email-mailbox-grant` | Delegate explicit `operations` on an owned inbox to `agent_id`. |
| `datamachine/email-forward` | Forward an original message by `uid` from `auth_ref` to `to`, with optional `folder` and introductory `body`. Includes every attachment and an `original.eml` copy. |

## Multiple inboxes per user

Data Machine core owns IMAP/SMTP connections. Each inbox is a separate encrypted named account under its user or agent principal. Connecting a second inbox preserves the first. Names are installation-unique; use a distinct name if another principal already owns the desired handle. User credentials do not automatically become available to the user's agent: grant each inbox separately.

For each inbox, create a private JSON file outside Git:

```json
{
  "imap_host": "imap.gmail.com",
  "imap_port": 993,
  "imap_encryption": "ssl",
  "imap_user": "your-address@example.com",
  "imap_password": "YOUR_IMAP_APP_PASSWORD",
  "smtp_host": "smtp.gmail.com",
  "smtp_port": 587,
  "smtp_encryption": "tls",
  "smtp_user": "your-address@example.com",
  "smtp_password": "YOUR_SMTP_APP_PASSWORD",
  "display_name": "Your Name",
  "sent_folder": "[Gmail]/Sent Mail"
}
```

Use your provider's server settings. SMTP is optional for read-only inboxes and required for authenticated forwarding. Both passwords are encrypted at rest. Native PHP IMAP, OpenSSL, and WordPress's PHPMailer are runtime prerequisites. Connections are explicitly TLS/SSL; no certificate validation is disabled.

```sh
wp --user=USER_ID datamachine email mailbox-connect work --input-file=/private/work.json
wp --user=USER_ID datamachine email mailbox-connect personal --input-file=/private/personal.json
wp --user=USER_ID datamachine email mailboxes
wp --user=USER_ID datamachine email test-connection --mailbox=work
wp --user=USER_ID datamachine email fetch --mailbox=personal --search='FROM "vendor.example"'
wp --user=USER_ID datamachine email forward MESSAGE_UID --mailbox=personal --to=receipts@example.com
```

For an agent that needs to find and forward invoices:

```sh
wp --user=USER_ID datamachine email mailbox-grant work AGENT_ID --operations=read,search,send
wp --user=USER_ID datamachine email mailbox-grant personal AGENT_ID --operations=read,search,send
```

An agent's permission to read does not imply permission to send; forwarding requires both. Deleting or replacing a sibling inbox is not part of connection setup. Existing send, queued-send, and reply abilities use the selected inbox's SMTP configuration when present, while legacy site mail behavior is preserved for existing configurations.

Forwarding traverses nested MIME parts, preserves HTML and inline images, sends attachments as their original bytes, and attaches the original email. It reads with IMAP PEEK and fails before sending if any part cannot be extracted. Successful sends are deduplicated by source content, inbox owner, and recipients. An interrupted or uncertain SMTP send remains blocked for inspection rather than being retried automatically. `delivery: accepted_by_smtp` establishes SMTP acceptance, not downstream application processing. `sent_copy_saved` reports the optional IMAP Sent-folder append separately.

SMTP delivery uses a fresh PHPMailer instance, avoiding cross-inbox credential leakage through WordPress's global mailer. Trusted extensions can customize it through `datamachine_email_phpmailer_init`.

Verification: `WORDPRESS_PATH=/path/to/wordpress php tests/email-multi-inbox-forward-smoke.php` exercises two inboxes, actual credential encryption, principal/delegation boundaries, recursive attachments, actual PHPMailer MIME serialization, sender-specific SMTP authentication, and duplicate suppression against deterministic peers.

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
