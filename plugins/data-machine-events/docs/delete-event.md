# Deleting and cancelling events

`data-machine-events/delete-event` (chat tool: `delete_event`) accepts an `event` ID or an `events` array, an optional free-form audit `reason`, and an optional `reason_type`:

- `cancelled`: trash the event and explicitly mark it human-owned. Link both current (time-aware) and legacy source identities derived from the stored title, date, venue, and time for title/date/venue-based importers. Matching imports resolve the trashed event and return `no_change`, rather than restoring it or creating a replacement.
- `duplicate` or `other`: ordinary recoverable trash. Existing importer restoration behavior is unchanged. `other` is the default for backward compatibility; free-form reason text does not select cancellation behavior.

Example:

```json
{"event":276763,"reason_type":"cancelled","reason":"Venue confirmed cancellation."}
```

Cancellation can also be applied to an event already in trash. Repeating it does not duplicate linked identities. Existing primary source metadata is preserved. The response and audit log include `reason_type`.

Generated links require stored event-details date and venue information. Identity matching is exact: if a legacy source changes its title/date/venue/time before any stable identity was recorded, these generated links cannot identify that changed listing. Existing primary and linked identities continue to protect events that already have them. Permanently deleting the post also deletes its protection.

To reverse a cancellation, restore the post in WordPress. To return it to full importer control, use the `event-ownership` command or ownership ability to set ownership to `import`.
