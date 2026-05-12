# Email Endpoints

**Implementation**: `inc/Api/Email.php`

**Base URL**: `/wp-json/datamachine/v1/email`

Thin REST wrappers around registered email abilities for SMTP sending and IMAP mailbox operations.

## Endpoints

| Method | Route | Purpose |
|--------|-------|---------|
| `POST` | `/email/send` | Send an email. |
| `GET` | `/email/fetch` | Fetch messages from a folder/search. |
| `GET` | `/email/{uid}/read` | Read a single message by UID. |
| `POST` | `/email/reply` | Send a reply. |
| `DELETE` | `/email/{uid}` | Delete a message. |
| `POST` | `/email/{uid}/move` | Move a message to another folder. |
| `POST` | `/email/{uid}/flag` | Set or clear a message flag. |
| `POST` | `/email/batch/move` | Move messages matching an IMAP search. |
| `POST` | `/email/batch/flag` | Set or clear flags on messages matching an IMAP search. |
| `POST` | `/email/batch/delete` | Delete messages matching an IMAP search. |
| `POST` | `/email/{uid}/unsubscribe` | Unsubscribe from a single message. |
| `POST` | `/email/batch/unsubscribe` | Unsubscribe from messages matching an IMAP search. |
| `POST` | `/email/test-connection` | Test configured email connectivity. |

## Permission

All email endpoints use `PermissionHelper::can_manage()`. A caller passes if they have any of these scoped Data Machine capabilities:

- `manage_flows`
- `manage_settings`
- `manage_agents`

Administrators also pass through the `manage_options` fallback built into `PermissionHelper`.

## Ability Delegation

The REST controller delegates behavior to email abilities such as `datamachine/send-email`, `datamachine/fetch-email`, and related mailbox actions. IMAP routes require an authenticated `email_imap` auth provider.
