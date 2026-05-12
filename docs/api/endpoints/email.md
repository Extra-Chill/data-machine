# Email Endpoints

**Implementation**: `inc/Api/Email.php`

**Base URL**: `/wp-json/datamachine/v1/email`

## Overview

Email endpoints expose Data Machine email abilities over REST for sending mail, reading IMAP inboxes, replying, moving, flagging, deleting, unsubscribing, and testing the stored IMAP connection.

## Authentication

All email routes require Data Machine manage permission via `PermissionHelper::can_manage()`.

External REST clients should use WordPress application passwords or cookie auth. Inbox operations also require a configured `email_imap` auth provider; missing IMAP credentials return `not_configured` with HTTP 400.

## Route Table

| Method | Route | Ability | Purpose |
|---|---|---|---|
| POST | `/email/send` | `datamachine/send-email` | Send an email with optional headers and attachments. |
| GET | `/email/fetch` | `datamachine/fetch-email` | Fetch messages from an IMAP folder. |
| GET | `/email/{uid}/read` | `datamachine/fetch-email` | Read one message by UID. |
| POST | `/email/reply` | `datamachine/email-reply` | Reply with threading headers. |
| DELETE | `/email/{uid}` | `datamachine/email-delete` | Delete one message by UID. |
| POST | `/email/{uid}/move` | `datamachine/email-move` | Move one message to another folder. |
| POST | `/email/{uid}/flag` | `datamachine/email-flag` | Set or clear an IMAP flag. |
| POST | `/email/batch/move` | `datamachine/email-batch-move` | Move messages matching an IMAP search. |
| POST | `/email/batch/flag` | `datamachine/email-batch-flag` | Flag messages matching an IMAP search. |
| POST | `/email/batch/delete` | `datamachine/email-batch-delete` | Delete messages matching an IMAP search. |
| POST | `/email/{uid}/unsubscribe` | `datamachine/email-unsubscribe` | Unsubscribe from a list using one message's headers. |
| POST | `/email/batch/unsubscribe` | `datamachine/email-batch-unsubscribe` | Unsubscribe from lists matching an IMAP search. |
| POST | `/email/test-connection` | `datamachine/email-test-connection` | Test stored IMAP credentials. |

## Core Parameters

### Send And Reply

| Parameter | Type | Required | Notes |
|---|---|---|---|
| `to` | string | yes | Recipient email address or comma-separated addresses for send. |
| `subject` | string | yes | Subject line. Send supports `{month}`, `{year}`, `{site_name}`, and `{date}` placeholders. |
| `body` | string | yes | HTML or plain-text body. |
| `cc`, `bcc` | string | no | Comma-separated addresses. `bcc` is send-only. |
| `from_name`, `from_email`, `reply_to` | string | no | Send headers. Defaults use site name/admin email when omitted. |
| `content_type` | string | no | `text/html` by default; `text/plain` is also supported. |
| `attachments` | array | no | Server file paths for `wp_mail()` attachments. |
| `in_reply_to` | string | reply only | Message-ID of the email being replied to. |
| `references` | string | no | References header chain for threading. |

### Fetch And Message Operations

| Parameter | Type | Default | Notes |
|---|---|---|---|
| `folder` | string | `INBOX` | Source IMAP folder. |
| `uid` | integer | route param | Message UID for read/delete/move/flag/unsubscribe. |
| `search` | string | `UNSEEN` for fetch | IMAP search string, for example `ALL`, `UNSEEN`, `FROM "github.com"`, or `SINCE "1-Mar-2026"`. |
| `max` | integer | route-specific | Safety limit for fetch or batch operations. |
| `offset` | integer | `0` | Pagination offset for fetch. |
| `headers_only` | boolean | `false` | Fast list mode; skips body parsing. |
| `mark_as_read` | boolean | `false` | Mark fetched messages as read. |
| `download_attachments` | boolean | `false` | Download attachments into local storage. |
| `destination` | string | required for move | Target IMAP folder, such as `Archive` or `[Gmail]/All Mail`. |
| `flag` | string | required for flag | IMAP flag such as `Seen`, `Flagged`, `Answered`, `Deleted`, or `Draft`. |
| `action` | string | `set` | `set` or `clear` for flag endpoints. |

## Response Shape

Email endpoints return ability-native success objects. Failed ability results are normalized to an `email_error` REST error with HTTP 400.

Send response shape:

```json
{
  "success": true,
  "message": "Email sent successfully",
  "recipients": ["editor@example.com"],
  "subject": "Weekly report",
  "logs": []
}
```

Fetch response shape:

```json
{
  "success": true,
  "data": {
    "items": [],
    "count": 10,
    "total_matches": 42,
    "offset": 0,
    "has_more": true
  },
  "logs": []
}
```

Batch operation response shapes include counters such as `moved_count`, `flagged_count`, `deleted_count`, `unsubscribed`, `failed`, or `no_header` depending on the route.

## Agent Usage Examples

List unread message headers without marking them read:

```bash
curl "https://example.com/wp-json/datamachine/v1/email/fetch?search=UNSEEN&headers_only=1&max=20" \
  -u username:application_password
```

Move GitHub notifications to an archive folder:

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/email/batch/move \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"search":"FROM \"github.com\"","destination":"Archive","max":100}'
```

Send a concise agent report:

```bash
curl -X POST https://example.com/wp-json/datamachine/v1/email/send \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"to":"editor@example.com","subject":"Daily agent summary","body":"<p>No blockers.</p>"}'
```
