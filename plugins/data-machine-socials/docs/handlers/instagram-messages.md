# Instagram messages

The `instagram_messages` fetch handler reads inbound DMs using the existing Instagram connection and its Page messaging token. It never sends messages, marks them read, or changes conversations.

Settings:

- `max_items`: maximum fresh messages per run (default 10, range 1–500).
- `days_back`: message age window (default 7; 0 disables the age filter).
- `require_attachment`: require an image attachment (default true).
- `conversation_limit`: maximum conversations scanned per run (default 20, range 1–100).

Each item contains message text, sender username, timestamp, conversation ID, and the message ID as its stable processed-item identifier. The source URL opens the Instagram inbox; Graph conversation IDs are not web thread permalinks. The first image attachment is downloaded into `file_info`, with `_engine_data.image_file_path` for downstream `include_images`, just like `instagram_account`. Failed image downloads are skipped and remain eligible for a later run. With `require_attachment` disabled, messages without images are emitted without a file.

The handler is generic: use an AI step to interpret messages for the desired workflow. For example, an `event_import` flow can interpret a flyer and create a draft through its existing AI and upsert steps.

Meta limitations still apply: only recent message details are available (typically the latest 20 messages per conversation), and message requests from non-followers may not be exposed. Conversation pagination reuses the reader's limit=1 fallback when Meta rejects larger pages. A run scans only the configured number of conversations; it is not an exhaustive historical inbox import.
