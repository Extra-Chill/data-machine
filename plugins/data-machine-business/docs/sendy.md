# Sendy sending and scheduling

`datamachine/sendy-send-campaign` creates a new campaign via `/api/campaigns/create.php` with `send_campaign=1`. Sendy cannot send an existing draft: this ability never updates one and does not accept `campaign_id`. The existing `sendy-push-campaign` draft behavior is unchanged.

## Input

Required strings: `from_name`, `from_email`, `reply_to`, `subject`, `html_text`, `brand_id`. Optional strings: `plain_text`, `title` (accepted for the shipped extrachill-newsletter consumer), `query_string`.

Provide at least one `list_ids` or `segment_ids` entry. Both are arrays of individual string IDs; blank, whitespace-padded, and comma-containing IDs are rejected before HTTP. Optional `exclude_list_ids` and `exclude_segment_ids` use the same shape. Arrays are comma-joined for Sendy; `exclude_segment_ids` maps to Sendy's `exclude_segments_ids` parameter. List IDs must be Sendy's encrypted API IDs, not database primary keys.

To schedule, supply both `schedule_date_time` (`Y-m-d H:i`, future, five-minute increments) and `schedule_timezone` (IANA timezone, e.g. `America/New_York`). Invalid dates, timezones, and timezone-only requests are rejected before HTTP. Omit both to send now.

## Configuration and safety

Like push, this ability resolves credentials internally from the `datamachine_sendy_config` network option/filter. No credentials or config input are accepted. It uses the same management permission gate as push, with `destructive: true`, `idempotent: false`, `readonly: false`, and `show_in_rest: false`. Consumers own confirmation, audience policy, and duplicate-send guards; do not automatically retry a send after an uncertain transport outcome.

## Output

`success`, nullable `campaign_id`, `message`, `scheduled`, and `raw`. Sendy's text responses `Campaign created and now sending` and `Campaign scheduled` are normalized; a numeric response supplies the ID. Ordinary stock Sendy responses have no ID. Error strings and unsuccessful HTTP responses produce `success=false`, as do invalid targets or schedules. Configuration failures use the existing ability `WP_Error` convention. `raw` is diagnostic provider text; do not publish it indiscriminately.
