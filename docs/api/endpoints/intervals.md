# Scheduling Intervals

Available scheduling intervals for Data Machine flow automation using WordPress Action Scheduler.

## Overview

Data Machine uses WordPress Action Scheduler for recurring flow execution. Scheduling is handled through the Flows API with the `scheduling_config` parameter, supporting standard WordPress intervals plus custom intervals for workflow automation.

## Standard Intervals

These intervals are available by default in the Data Machine core plugin.

### Every 5 Minutes
```php
'interval' => 'every_5_minutes'
```
- **Frequency**: Every 5 minutes
- **Seconds**: 300
- **Use Case**: Real-time monitoring, high-frequency data ingestion.

### Hourly
```php
'interval' => 'hourly'
```
- **Frequency**: Every hour
- **Seconds**: 3600
- **Use Case**: High-frequency content processing, social media updates.

### Every 2 Hours
```php
'interval' => 'every_2_hours'
```
- **Frequency**: Every 2 hours
- **Seconds**: 7200
- **Use Case**: Frequent processing without hourly overhead.

### Every 4 Hours
```php
'interval' => 'every_4_hours'
```
- **Frequency**: Every 4 hours
- **Seconds**: 14400
- **Use Case**: Periodic updates, moderate frequency.

### Every 6 Hours (Quarter Daily)
```php
'interval' => 'qtrdaily'
```
- **Frequency**: Every 6 hours
- **Seconds**: 21600
- **Use Case**: Business-hour processing, multi-day cycles.

### Twice Daily
```php
'interval' => 'twicedaily'
```
- **Frequency**: Every 12 hours
- **Seconds**: 43200
- **Use Case**: Semi-daily processing, moderate frequency updates.

### Daily
```php
'interval' => 'daily'
```
- **Frequency**: Every 24 hours
- **Seconds**: 86400
- **Use Case**: Daily content aggregation, blog post scheduling.

### Weekly
```php
'interval' => 'weekly'
```
- **Frequency**: Every 7 days
- **Seconds**: 604800
- **Use Case**: Weekly reports, content roundups, maintenance tasks.

## Special Scheduling Modes

### Manual
```json
{ "interval": "manual" }
```
- **Behavior**: No automatic execution. The flow must be triggered via the `run_flow` tool or the Execute API.

### One-Time
```json
{ 
  "interval": "one_time",
  "timestamp": 1735689600 
}
```
- **Behavior**: Executes once at the specified Unix timestamp.
- **Requirement**: A valid future Unix timestamp must be provided in the `timestamp` field.

## Usage Examples

### Flows Ability Integration

```bash
# Schedule flow to run daily
curl -X POST https://example.com/wp-json/wp-abilities/v1/abilities/datamachine/create-flow/run \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{
    "input": {
      "pipeline_id": 123,
      "flow_name": "Daily Flow",
      "scheduling_config": {"interval": "daily"}
    }
  }'

# Schedule flow to run every 5 minutes
curl -X POST https://example.com/wp-json/wp-abilities/v1/abilities/datamachine/create-flow/run \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{
    "input": {
      "pipeline_id": 123,
      "flow_name": "Rapid Processing",
      "scheduling_config": {"interval": "every_5_minutes"}
    }
  }'
```

### Custom Intervals via Filters

Developers can register additional intervals using the `datamachine_scheduler_intervals` filter:

```php
add_filter('datamachine_scheduler_intervals', function($intervals) {
    $intervals['every_3_days'] = [
        'label' => 'Every 3 Days',
        'seconds' => DAY_IN_SECONDS * 3,
    ];
    return $intervals;
});
```

## Interval Selection Guidelines

### High Frequency (5 Minutes to Hourly)
- **Best For**: Social media posting, news monitoring, real-time data processing
- **Considerations**: Higher server load, API rate limits
- **Examples**: Twitter feeds, breaking news alerts, live data updates

### Medium Frequency (Every 2 Hours to Daily)
- **Best For**: Content aggregation, blog posting, regular data processing
- **Considerations**: Balanced server load, sufficient content accumulation
- **Examples**: RSS feed processing, email newsletters, content curation

### Low Frequency (Weekly)
- **Best For**: Reports, archives, maintenance tasks, bulk processing
- **Considerations**: Lower server impact, content batching opportunities
- **Examples**: Weekly reports, content audits, archive processing, cleanup tasks

## Performance Considerations

### Server Load
- Higher frequency intervals (like `every_5_minutes`) increase server resource usage
- Consider content volume and processing complexity
- Monitor job execution times and system performance

### API Rate Limits
- External APIs (Twitter, Facebook, etc.) have rate limits
- Schedule intervals should respect API limitations
- Build in buffer time for API failures

## Error Handling

### Missed Schedules
Action Scheduler automatically handles missed schedules:
- **Backlog Processing**: Runs missed executions on the next WordPress cron
- **Failure Recovery**: Failed jobs are logged; the next execution follows the scheduled interval
- **Overlap Prevention**: Prevents multiple concurrent executions of the same flow

## Monitoring and Management

### Schedule Status
```bash
# Check current schedule status
curl -X POST https://example.com/wp-json/wp-abilities/v1/abilities/datamachine/get-flows/run \
  -H "Content-Type: application/json" \
  -u username:application_password \
  -d '{"input": {"flow_id": 123}}'
```

Response includes `scheduling_config` with current settings and historical execution data.

## Related Documentation

- [Flows API](flows.md) - Flow management and configuration
- [Execute API](execute.md) - Immediate flow execution
- [Jobs API](jobs.md) - Job monitoring and execution history
