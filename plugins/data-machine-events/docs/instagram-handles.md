# Instagram handles

Venues expose `instagram` through venue reads (`get-venue`, `Venue_Taxonomy::get_venue_data`, profile reads). Stored at `_venue_instagram`, it is editable through `VenueProfileMutations::updateProfile` with the usual expected revision, or `update-venue`. Artist terms store the same field at `instagram`; `events-by-term` and filter hierarchy term data expose it. Missing handles read as empty strings.

Both accept usernames, leading `@`, and Instagram profile URLs (with optional www, scheme and trailing slash). Values are lowercased and restricted to 1–30 ASCII letters, digits, periods and underscores. Empty strings clear handles. Invalid values return `instagram_invalid` without changing stored data.

## Backfill

The write-authorized `data-machine-events/set-term-instagram` ability accepts `taxonomy` (`venue` or `artist`), positive `term_id`, and `instagram`. Its thin CLI adapter is:

```sh
wp data-machine-events set-term-instagram venue 1531 '@MusicFarm'
wp data-machine-events set-term-instagram artist 123 'https://www.instagram.com/Artist.Name/'
wp data-machine-events set-term-instagram artist 123 ''
```

Invoke once per known term to backfill. No handles are guessed or scraped. Venue writes use the canonical mutation lock, transaction, and revision contract; artist writes validate the local taxonomy and persist term meta. Consumers can pass these handles to roundup tag placement / Instagram carousel user_tags without a leading `@`.
