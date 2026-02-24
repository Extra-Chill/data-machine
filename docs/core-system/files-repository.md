# FilesRepository Components

## Overview

The FilesRepository is a modular component system for file operations in the Data Machine pipeline system. Introduced in version 0.2.1, it centralizes file handling functionality and reduces code duplication across handlers.

## Architecture

**Location**: `/inc/Core/FilesRepository/`
**Components**: 6 specialized classes
**Since**: 0.2.1

## Components

### DirectoryManager

**File**: `DirectoryManager.php`
**Purpose**: Directory creation and path management

```php
use DataMachine\Core\FilesRepository\DirectoryManager;

$dir_manager = new DirectoryManager();
$pipeline_dir = $dir_manager->get_pipeline_directory($pipeline_id);
$flow_dir = $dir_manager->get_flow_directory($pipeline_id, $flow_id);
$job_dir = $dir_manager->get_job_directory($pipeline_id, $flow_id, $job_id);
```

**Key Methods**:
- `get_pipeline_directory($pipeline_id)`: Get pipeline directory
- `get_flow_directory($pipeline_id, $flow_id)`: Get flow directory
- `get_job_directory($pipeline_id, $flow_id, $job_id)`: Get job directory
- `get_flow_files_directory($pipeline_id, $flow_id)`: Get flow file storage directory
- `ensure_directory_exists($directory)`: Create directory if it does not exist

### FileStorage

**File**: `FileStorage.php`
**Purpose**: File operations and flow-isolated storage

```php
use DataMachine\Core\FilesRepository\FileStorage;

$storage = new FileStorage();
$context = [
    'pipeline_id' => $pipeline_id,
    'flow_id' => $flow_id,
];
$stored_path = $storage->store_file($source_path, $filename, $context);
```

**Key Methods**:
- `store_file($source_path, $filename, $context)`: Copy a local file into flow file storage
- `get_all_files($context)`: List files for a flow
- `delete_file($filename, $context)`: Delete a stored file
- `store_data_packet($data, $job_id, $context)`: Persist step data for a job
- `retrieve_data_packet($reference)`: Read a persisted data packet

### FileCleanup

**File**: `FileCleanup.php`
**Purpose**: Retention policy enforcement and cleanup

```php
use DataMachine\Core\FilesRepository\FileCleanup;

$cleanup = new FileCleanup();
// Automatic cleanup via scheduled action
```

**Key Features**:
- Scheduled cleanup of old files
- Retention policy enforcement
- Job data cleanup on failure
- Configurable retention periods

### ImageValidator

**File**: `ImageValidator.php`
**Purpose**: Image validation and metadata extraction

```php
use DataMachine\Core\FilesRepository\ImageValidator;

$validator = new ImageValidator();
$validation = $validator->validate_image_file($file_path);

if ($validation['valid']) {
    $metadata = $validation['metadata'];
    // width, height, mime_type, file_size, etc.
}
```

**Key Methods**:
- `validate_image_file($file_path)`: Validate image and extract metadata
- `is_valid_image_type($mime_type)`: Check if MIME type is supported
- `get_image_dimensions($file_path)`: Get image width/height

### RemoteFileDownloader

**File**: `RemoteFileDownloader.php`
**Purpose**: Remote file downloading with validation

```php
use DataMachine\Core\FilesRepository\RemoteFileDownloader;

$downloader = new RemoteFileDownloader();
$context = [
    'pipeline_id' => $pipeline_id,
    'flow_id' => $flow_id,
];
$result = $downloader->download_remote_file($url, $filename, $context);

if ($result) {
    $local_path = $result['path'];
    $stored_filename = $result['filename'];
    $file_url = $result['url'];
}
```

**Key Methods**:
- `download_remote_file($url, $filename, $context, $options)`: Download remote file and store in flow files directory

### FileRetrieval

**File**: `FileRetrieval.php`
**Purpose**: Data retrieval operations from flow-isolated file storage

Separated from FileStorage per Single Responsibility Principle - FileStorage handles write operations while FileRetrieval handles read operations.

```php
use DataMachine\Core\FilesRepository\FileRetrieval;

$file_retrieval = new FileRetrieval();
$file_data = $file_retrieval->retrieve_data_by_job_id($job_id, [
    'pipeline_id' => $pipeline_id,
    'pipeline_name' => $pipeline_name,
    'flow_id' => $flow_id,
    'flow_name' => $flow_name
]);
```

**Key Methods**:
- `retrieve_data_by_job_id($job_id, $context)`: Retrieves all file data for a specific job

**Context Requirements**:
- `pipeline_id` - Pipeline identifier
- `pipeline_name` - Pipeline name for directory path
- `flow_id` - Flow identifier
- `flow_name` - Flow name for directory path

## Integration Pattern

Components work together for complete file handling:

> Note: When mapping flow_step_id -> flow_id, the REST API uses `datamachine_get_flow_id_from_step` filter (see datamachine/inc/Api/Files.php:168). Implement this filter when connecting flow-step-aware file operations from extensions.

```php
use DataMachine\Core\FilesRepository\RemoteFileDownloader;
use DataMachine\Core\FilesRepository\ImageValidator;
use DataMachine\Core\FilesRepository\DirectoryManager;

// Download and validate image
$downloader = new RemoteFileDownloader();
$context = [
    'pipeline_id' => $pipeline_id,
    'flow_id' => $flow_id,
];
$result = $downloader->download_remote_file($image_url, $filename, $context);

if ($result) {
    $validator = new ImageValidator();
    $validation = $validator->validate_image_file($result['path']);

    if ($validation['valid']) {
        // Image is valid and stored
        $image_path = $result['path'];
    }
}
```

## Directory Structure

Files are organized under the `datamachine-files` uploads directory, grouped by pipeline then flow:

```
wp-content/uploads/datamachine-files/
└── pipeline-5/
    ├── context/
    │   └── example.pdf
    └── flow-42/
        ├── flow-42-files/
        │   ├── image1.jpg
        │   └── document.pdf
        └── jobs/
            └── job-123/
                └── data.json
```

## Scheduled Cleanup

Automatic cleanup is handled via WordPress Action Scheduler:

```php
// Scheduled daily cleanup
if (!as_next_scheduled_action('datamachine_cleanup_old_files')) {
    as_schedule_recurring_action(
        time(),
        DAY_IN_SECONDS,
        'datamachine_cleanup_old_files'
    );
}
```

## Benefits

- **Modularity**: Specialized components for different file operations
- **Isolation**: Flow-specific directories prevent conflicts
- **Validation**: Built-in image and file validation
- **Cleanup**: Automatic retention policy enforcement
- **Consistency**: Standardized file handling across all handlers

## Used By

The FilesRepository modular components are used by:
- Files Handler - Primary consumer of all components
- PublishHandler Base Class - Uses ImageValidator for validation
- Engine Actions - Use FileCleanup for retention policy enforcement

The modular architecture allows individual components to be used independently throughout the system.