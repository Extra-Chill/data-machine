# Import/Export System

Data Machine provides comprehensive import/export functionality for pipeline configurations, enabling backup, migration, and sharing of workflow templates across installations.

## Overview

The import/export system handles pipeline structures including steps, configurations, and associated flow data. All operations are performed through WordPress actions and are accessible via the REST API.

## Export Functionality

### Export Process

Pipeline export generates a CSV file containing complete pipeline and flow configuration data:

```csv
pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,handler,settings
1,"News Pipeline",0,"fetch","{""step_type"":""fetch"",""handler_slug"":""rss""}","","","",""
1,"News Pipeline",0,"fetch","{""step_type"":""fetch"",""handler_slug"":""rss""}",2,"Daily News","rss","{""url"":""https://example.com/feed""}"
```

### Export Structure

The CSV export includes two types of rows:

1. **Pipeline Structure Rows**: Define the pipeline steps and their configurations
2. **Flow Configuration Rows**: Detail how each flow implements the pipeline steps with specific handlers and settings

### Export Actions

```php
// Export specific pipelines
do_action('datamachine_export', 'pipelines', [1, 2, 3]);

// Access export result
$csv_data = apply_filters('datamachine_export_result', '');
```

## Import Functionality

### Import Process

Pipeline import processes CSV data to recreate pipeline structures and flow configurations:

1. Parses CSV rows to identify pipeline names and step configurations
2. Creates pipelines if they don't exist
3. Adds pipeline steps with proper execution ordering
4. Maintains flow-specific handler configurations

### Import Actions

```php
// Import CSV data
do_action('datamachine_import', 'pipelines', $csv_content);

// Access import result
$result = apply_filters('datamachine_import_result', []);
// Returns: ['imported' => [1, 2, 3]] - array of imported pipeline IDs
```

### Import Behavior

- **Pipeline Creation**: Automatically creates pipelines with default flow configurations
- **Step Synchronization**: Adds steps to existing pipelines without duplication
- **Flow Preservation**: Maintains existing flow configurations while adding new pipeline steps
- **Error Handling**: Skips invalid rows and logs issues for troubleshooting

## Security & Permissions

All import/export operations require `manage_options` capability, ensuring only administrators can perform these actions.

## Use Cases

### Backup & Restore
Regularly export pipeline configurations for backup purposes, enabling quick restoration after updates or migrations.

### Migration
Export pipelines from development environments and import into production systems.

### Template Sharing
Share pipeline templates between different WordPress installations or team members.

### Version Control
Store pipeline configurations in external version control systems for change tracking.

## Technical Details

- **CSV Format**: Standard CSV with proper escaping for complex JSON configurations
- **Execution Ordering**: Pipeline steps are sorted by `execution_order` during export
- **Flow Isolation**: Each flow's handler configurations are preserved independently
- **Database Integration**: Import/export is implemented by `DataMachine\Engine\Actions\ImportExport` and surfaced through `Pipeline/ImportExportAbility.php`; REST, CLI, and ability callers all route through that same action layer.
