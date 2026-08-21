# Import/Export System

Data Machine provides comprehensive import/export functionality for pipeline configurations, enabling backup, migration, and sharing of workflow templates across installations.

## Overview

The import/export system handles pipeline structures including steps, configurations, and associated flow data. Operations use the REST-visible `datamachine/import-pipelines` and `datamachine/export-pipelines` abilities. Data Machine does not register pipeline import/export WP-CLI commands.

## Export Functionality

### Export Process

Pipeline export generates a CSV file containing complete pipeline and flow configuration data:

```csv
pipeline_id,pipeline_name,step_position,step_type,step_config,flow_id,flow_name,settings
1,"News Pipeline",0,"fetch","{""step_type"":""fetch""}","","",""
```

### Export Structure

The CSV export includes two types of rows:

1. **Pipeline Structure Rows**: Define the pipeline steps and their configurations
2. **Flow Configuration Rows**: Detail how each flow implements the pipeline steps with canonical settings such as `handler_slugs` and `handler_configs`

Export through `POST /wp-json/wp-abilities/v1/abilities/datamachine/export-pipelines/run`. The curated `GET /wp-json/datamachine/v1/pipelines?format=csv&ids=1,2` endpoint is also available when a raw CSV download response is required.

## Import Functionality

### Import Process

Pipeline import processes CSV data to recreate pipeline structures and flow configurations:

1. Parses CSV rows to identify pipeline names and step configurations
2. Creates pipelines if they don't exist
3. Adds pipeline steps with proper execution ordering
4. Maintains flow-specific handler configurations

Import through `POST /wp-json/wp-abilities/v1/abilities/datamachine/import-pipelines/run`. Successful ability payloads include the imported pipeline IDs. The curated `datamachine/v1/pipelines` controller does not provide CSV import.

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
- **Database Integration**: Import/export is implemented by `DataMachine\Engine\Actions\ImportExport` and surfaced through `inc/Abilities/Pipeline/ImportExportAbility.php`; the WordPress Abilities REST runner and curated CSV export route use that same action layer.
