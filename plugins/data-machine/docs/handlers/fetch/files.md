# Files Fetch Handler

Processes uploaded files from flow-isolated storage with automatic MIME type detection, file validation, and deduplication tracking.

## Architecture

**Base Class**: Extends FetchHandler (@since v0.2.1)

**Inherited Functionality**:
- Automatic deduplication via `isItemProcessed()` and `markItemProcessed()`
- Engine data storage via `storeEngineData()` for downstream handlers
- Standardized responses via `successResponse()`, `emptyResponse()`, `errorResponse()`
- Centralized logging and error handling

**Implementation**: Uses DataPacket class for consistent packet structure

## File Management

**Flow Isolation**: Files are stored and accessed within flow-specific contexts to prevent cross-flow contamination.

**Repository Integration**: Uses centralized Files Repository system for persistent file storage with UUID-based organization.

**MIME Detection**: Automatic MIME type detection using WordPress `wp_check_filetype()` function.

## Configuration

**No Configuration Required**: Handler operates without specific configuration parameters, processing available files from repository.

**Automatic Discovery**: When no files are explicitly configured, automatically discovers files from the repository for the current flow step.

## Usage Examples

**Basic File Processing**:
```php
// Files are uploaded via admin interface or API
// No handler configuration needed - processes next available file
$handler_config = [
    'files' => []
];
```

**With Explicit File List**:
```php
$handler_config = [
    'files' => [
        'uploaded_files' => [
            [
                'original_name' => 'document.pdf',
                'persistent_path' => '/path/to/file.pdf',
                'size' => 1024000,
                'mime_type' => 'application/pdf'
            ]
        ]
    ]
];
```

## Processing Logic

**Sequential Processing**: Processes one file per execution, finding the next unprocessed file from available uploads.

**Deduplication**: Uses file path as unique identifier to track processed files and prevent reprocessing.

**File Validation**: Verifies file existence before processing and reports missing files as errors.

## Output Structure

**Clean Data Packet (AI-visible)**:
```php
[
    'processed_items' => [
        [
            'data' => [
                'title' => 'original_filename.ext',
                'content' => 'File: original_filename.ext\nType: mime/type\nSize: 1024 bytes',
                'file_info' => [
                    'file_path' => '/path/to/file.ext',
                    'file_name' => 'original_filename.ext',
                    'mime_type' => 'mime/type',
                    'file_size' => 1024
                ]
            ],
            'metadata' => [
                'source_type' => 'files',
                'item_identifier_to_log' => '/path/to/file.ext',
                'original_id' => '/path/to/file.ext',
                'original_title' => 'original_filename.ext',
                'original_date_gmt' => '2024-01-01 12:00:00'
            ]
        ]
    ]
]
```

**Engine Data Storage (Database)**:
```php
// Stored in database for downstream handler access via datamachine_engine_data filter
[
    'source_url' => '',           // Empty for local files
    'image_url' => $public_url    // Public URL for image files only, empty for non-images
]
```

## File Type Support

**All File Types**: Handles any file type uploaded through the system, with downstream steps responsible for type-specific processing.

**Common MIME Types**:
- Documents: PDF, DOC, DOCX, TXT
- Images: JPEG, PNG, GIF, WebP  
- Audio: MP3, WAV, OGG
- Video: MP4, AVI, MOV
- Archives: ZIP, RAR, TAR

## Error Handling

**File System Errors**:
- Missing or inaccessible files
- Repository service unavailability
- File permission issues

**Upload Errors**:
- PHP upload error code translation
- File size limit violations
- Temporary directory issues

**Processing Errors**:
- Empty file lists
- Invalid file metadata
- MIME type detection failures

## Integration Points

**Files Repository**: Integrates with centralized file repository system for persistent storage and retrieval.

**Flow Isolation**: Maintains strict flow-level file separation to prevent data leakage between different pipeline instances.

**Clean Data Separation**: Returns clean data packets to AI agents without URLs, while storing engine parameters (image_url for images) in database for downstream handler access via `datamachine_engine_data` filter.

**Engine Data Architecture**: Uses centralized filter pattern for engine data access:
```php
// Storage by Files handler via centralized filter (array storage)
if ($job_id) {
    apply_filters('datamachine_engine_data', null, $job_id, [
        'source_url' => '',  // Empty for local files
        'image_url' => $file_url  // Public URL for images
    ]);
}

// Retrieval by downstream handlers (via filter)
$engine_data = apply_filters('datamachine_engine_data', [], $job_id);
$image_url = $engine_data['image_url'] ?? null;
```

**Image URL Generation**: For image files, generates public URLs for use by publish handlers (WordPress featured images, social media uploads, etc.).

**Logging**: Uses `datamachine_log` action with debug/error levels for file discovery, processing status, and error conditions.