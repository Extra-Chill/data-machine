# WP-CLI Commands

Data Machine provides WP-CLI commands for managing pipelines, flows, jobs, and more from the command line.

## Available Commands

### datamachine pipelines

Manage pipelines.

```bash
# List all pipelines
wp datamachine pipelines list

# Get a specific pipeline
wp datamachine pipeline get 5

# Create a pipeline
wp datamachine pipeline create --name="My Pipeline" --description="..."

# Delete a pipeline
wp datamachine pipeline delete 5

# Add a step to a pipeline
wp datamachine pipeline add-step 5 --type=fetch --handler=rss --name="RSS Fetch"
```

### datamachine flows

Manage flows.

```bash
# List all flows
wp datamachine flows list

# Get a specific flow
wp datamachine flow get 10

# Create a flow from pipeline
wp datamachine flow create --pipeline_id=5 --name="Daily Flow"

# Run a flow immediately
wp datamachine flow run 10

# Delete a flow
wp datamachine flow delete 10
```

### datamachine jobs

Manage jobs.

```bash
# List jobs
wp datamachine jobs list

# List failed jobs
wp datamachine jobs list --status=failed

# Get a specific job
wp datamachine job get 42

# Retry a failed job
wp datamachine job retry 42

# Delete a job
wp datamachine job delete 42
```

### datamachine posts

Manage Data Machine posts.

```bash
# List posts created by Data Machine
wp datamachine posts list

# Get a post
wp datamachine post get 123
```

### datamachine logs

Manage logs.

```bash
# View recent logs
wp datamachine logs read

# Clear logs
wp datamachine logs clear

# Add a log entry
wp datamachine logs write --message="Test log" --level=info
```

### datamachine settings

Manage plugin settings.

```bash
# Get all settings
wp datamachine settings get

# Get a specific setting
wp datamachine setting get provider

# Update a setting
wp datamachine setting update provider anthropic

# Update model
wp datamachine setting update model claude-sonnet-4-20250514
```

### datamachine links

Manage internal links.

```bash
# Analyze internal links
wp datamachine links analyze

# Build internal links
wp datamachine links build
```

### datamachine blocks

Manage Gutenberg blocks.

```bash
# List block patterns
wp datamachine blocks list

# Analyze blocks
wp datamachine blocks analyze
```

### datamachine alt-text

Manage image alt text.

```bash
# Generate alt text for images without it
wp datamachine alt-text generate

# Check alt text status
wp datamachine alt-text status
```

## Aliases

Most commands have singular and plural forms:

- `wp datamachine pipeline` / `wp datamachine pipelines`
- `wp datamachine flow` / `wp datamachine flows`
- `wp datamachine job` / `wp datamachine jobs`
- `wp datamachine post` / `wp datamachine posts`
- `wp datamachine log` / `wp datamachine logs`
- `wp datamachine link` / `wp datamachine links`
- `wp datamachine block` / `wp datamachine blocks`

## Examples

### Run a flow from a script

```bash
#!/bin/bash
# Daily sync script
wp datamachine flow run 10 --force
wp datamachine jobs list --status=failed
```

### Bulk create flows

```bash
# Create flows for multiple pipelines
for id in 1 2 3 4 5; do
  wp datamachine flow create --pipeline_id=$id --name="Flow for Pipeline $id"
done
```

### Monitor job status

```bash
# Check for failed jobs
failed=$(wp datamachine jobs list --status=failed --format=count)
if [ "$failed" -gt 0 ]; then
  echo "Warning: $failed failed jobs"
  wp datamachine jobs list --status=failed
fi
```
