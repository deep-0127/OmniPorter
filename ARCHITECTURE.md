# OmniPorter Architecture

## Overview

OmniPorter is a unified CSV/Excel Import/Export library for Laravel applications. It provides a generic, configurable system that works with any Laravel model through trait-based integration, eliminating boilerplate code while maintaining flexibility.

### Value Proposition

- **Zero Boilerplate**: Add a trait to your model, configure via property, and you're done
- **Queue-Based**: All imports/exports run asynchronously via Laravel queues for performance
- **Validation-First**: Leverages existing FormRequest validators for row-level validation
- **Relation Support**: Handles BelongsTo and BelongsToMany relationships automatically
- **Type Casting**: Automatic type casting based on model cast definitions
- **Progress Tracking**: Built-in progress tracking and result export
- **Email Notifications**: Automatic email notifications with result files

## Technology Stack

| Component | Technology | Purpose |
|-----------|-----------|---------|
| Core Framework | Laravel ^12.0 | Application framework |
| Excel Processing | Maatwebsite/Excel ^3.1 | CSV/Excel file handling |
| Caching | Redis (via Predis ^3.0) | State management across chunks |
| Queue | Laravel Queue | Asynchronous processing |
| PHP | ^8.2 | Language runtime |

## System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         Frontend Layer                           │
│  (File Upload / Export Request)                                  │
└────────────────────────────┬────────────────────────────────────┘
                             │ HTTP POST
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Controller Layer                             │
│  ┌──────────────────┐  ┌──────────────────┐                    │
│  │ ImportController │  │ ExportController │                    │
│  └──────────────────┘  └──────────────────┘                    │
└────────────────────────────┬────────────────────────────────────┘
                             │ Queue Job
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Processing Layer                             │
│  ┌──────────────────┐  ┌──────────────────┐                    │
│  │  GenericImport   │  │  GenericExport   │                    │
│  │  (OnEachRow)     │  │  (FromQuery)     │                    │
│  └──────────────────┘  └──────────────────┘                    │
│         │                        │                                │
│         ▼                        ▼                                │
│  ┌──────────────────┐  ┌──────────────────┐                    │
│  │ImportDetailsCache│  │ExportDetailsCache│                    │
│  │  (State Mgmt)    │  │  (State Mgmt)    │                    │
│  └──────────────────┘  └──────────────────┘                    │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Data Layer                                   │
│  ┌──────────────────┐  ┌──────────────────┐                    │
│  │   Eloquent Model │  │  FormRequest     │                    │
│  │   (HasImport)    │  │  (Validation)    │                    │
│  └──────────────────┘  └──────────────────┘                    │
└─────────────────────────────────────────────────────────────────┘
```

## Core Components

### 1. Traits (Model Integration)

#### HasImport Trait
**Location**: `src/Traits/HasImport.php`

Enables import functionality on Eloquent models. Provides:
- `import()` - Queues an import job
- `getUniqueKeysForUpdate()` - Defines fields for record matching
- `getUniqueKeyForImportExport()` - Defines relation lookup key
- `getListOfRelationDetails()` - Defines foreign-key relations
- `getImportValidators()` - Maps validators to operations
- `applyImportContext()` - Hook for setting context before save
- `afterImportSave()` - Hook for post-save actions

#### HasExport Trait
**Location**: `src/Traits/HasExport.php`

Enables export functionality on Eloquent models. Provides:
- `export()` - Queues an export job
- `getUniqueKeyForImportExport()` - Defines relation lookup key
- `getListOfRelationDetails()` - Defines foreign-key relations
- `getColumnsToExport()` - Defines exportable columns

### 2. Controllers (HTTP Layer)

#### ImportController
**Location**: `src/Import/Http/Controllers/ImportController.php`

Handles HTTP requests for imports:
- `importResource()` - Validates mode and routes to import()
- `import()` - Stores file and queues import job
- Maintains static class map of importable models

#### ExportController
**Location**: `src/Export/Http/Controllers/ExportController.php`

Handles HTTP requests for exports:
- `exportResource()` - Validates columns and queues export job
- Maintains static class map of exportable models

#### ProgressController
**Location**: `src/Import/Http/Controllers/ProgressController.php`

Handles progress tracking for imports:
- `show()` - Returns progress status for a given batch ID
- Calculates percentage complete
- Determines status (pending, in_progress, completed)

### 3. Import Processing

#### GenericImport
**Location**: `src/Import/Imports/GenericImport.php`

Core import processor implementing Maatwebsite/Excel interfaces:
- `OnEachRow` - Processes each row individually
- `WithChunkReading` - Processes in chunks (configurable size)
- `WithEvents` - Handles BeforeImport, AfterChunk, AfterImport
- `ShouldQueue` - Runs asynchronously

Key responsibilities:
- Data mapping and type casting
- Relation resolution (BelongsTo, BelongsToMany)
- Model instance resolution (create vs update)
- Validation via FormRequest
- Transaction management
- Result aggregation

#### ImportDetailsCache
**Location**: `src/Import/Helpers/ImportDetailsCache.php`

Manages import state across chunk processing:
- Caches fillable fields and relation definitions
- Maps Excel headings to model fields
- Caches related models for relation lookups
- Tracks progress (total/processed rows)
- Persists to Redis for cross-chunk access

#### ImportAttributeCaster
**Location**: `src/Import/Helpers/ImportAttributeCaster.php`

Handles type casting based on model cast definitions:
- Boolean, integer, float casting
- Date/datetime parsing (Excel serial dates)
- Enum resolution
- String trimming

### 4. Export Processing

#### GenericExport
**Location**: `src/Export/Exports/GenericExport.php`

Core export processor:
- `FromQuery` - Builds query from filters
- `WithHeadings` - Generates column headings
- `WithMapping` - Maps model attributes to export format
- `ShouldQueue` - Runs asynchronously

#### ExportDetailsCache
**Location**: `src/Export/Helpers/ExportDetailsCache.php`

Manages export state and configuration.

### 5. Results & Notifications

#### ResultExport
**Location**: `src/Import/Results/ResultExport.php`

Exports import results as Excel file with status for each row.

#### ImportCompleteMail
**Location**: `src/Import/Mail/ImportCompleteMail.php`

Email notification with attached result file.

#### DispatchCompleteImportNotificationJob
**Location**: `src/Import/Jobs/DispatchCompleteImportNotificationJob.php`

Queues email notification after import completion.

### 6. Service Provider

#### OmniPorterServiceProvider
**Location**: `src/OmniPorterServiceProvider.php`

Bootstrap and configuration:
- Merges config from package
- Loads routes
- Auto-discovers models with HasImport/HasExport traits
- Registers console commands
- Publishes assets (config, views, stubs)

**Model Discovery**:
- Scans configured paths for models
- Caches discovered models in `bootstrap/cache/omniporter_models.php`
- Hydrates controller class maps

## Design Patterns

### 1. Trait-Based Configuration
Models configure OmniPorter via the `$omni_porter_config` property:

```php
protected $omni_porter_config = [
    'unique_key' => 'email',
    'relations' => [
        'department' => [
            'type' => 'belongsTo',
            'model' => Department::class,
            'field' => 'department_id',
        ],
    ],
    'validation' => EmployeeImportRequest::class,
    'columns' => ['name', 'email', 'department_id'],
];
```

### 2. State Management via Cache
ImportDetailsCache persists state to Redis:
- Allows chunked processing to share configuration
- Avoids reloading relation data for each chunk
- Tracks progress across chunks

### 3. Event-Driven Processing
Maatwebsite/Excel events drive processing:
- `BeforeImport` - Initialize cache, count rows
- `AfterChunk` - Persist chunk results
- `AfterImport` - Merge results, send notification

### 4. Transaction Per Row
Each row is wrapped in a database transaction:
- Ensures atomicity of row operations
- Allows rollback on validation errors
- Prevents partial row corruption

### 5. Lazy Relation Loading
Related models are cached on first lookup:
- Reduces database queries
- Improves performance for large datasets
- Cache is updated if new relations are found

## Queue Strategy

### Import Queue
- **Connection**: Configurable (default: `sync`)
- **Queue Name**: Configurable (default: `imports`)
- **Chunk Size**: Configurable (default: 500 rows)

### Export Queue
- **Connection**: Configurable
- **Queue Name**: Configurable

## Error Handling

### Validation Errors
- Thrown as `ImportException` with validator instance
- Row-level validation via FormRequest
- Errors recorded in result export

### Database Errors
- `UniqueConstraintViolationException` caught and logged
- Transaction rollback on any error
- Error status recorded in results

### System Errors
- Generic exceptions caught and logged
- "System Error" message in results
- Full stack trace in logs (debug mode)

## Caching Strategy

### ImportDetailsCache
- **Store**: Configurable (default: Redis)
- **Prefix**: `omniporter_import_`
- **TTL**: Configurable (default: 3600 seconds)
- **Key Pattern**: `{prefix}_import_{batchId}`

### Model Discovery Cache
- **Location**: `bootstrap/cache/omniporter_models.php`
- **Content**: Import/export class maps
- **Environment**: Production only (local scans each time)

## File Storage

### Import Files
- **Disk**: Configurable
- **Upload Path**: `imports/uploads/`
- **Results Path**: `imports/results/{batchId}/`
- **Chunk Files**: `chunk-{index}.jsonl`
- **Final Result**: `final_result_{batchId}.xlsx`

### Export Files
- **Disk**: Configurable
- **Path**: `exports/`
- **Format**: `{batchId}.{type}`

## Security Considerations

1. **File Validation**: Only xlsx, xls, csv files accepted
2. **Authorization**: ImportRequest authorizes all requests (customize as needed)
3. **SQL Injection**: Uses Eloquent query builder (parameterized)
4. **Path Traversal**: Uses Laravel Storage abstraction
5. **Memory Safety**: Chunked processing prevents memory overflow

## Performance Characteristics

### Import Performance
- **Memory**: O(chunk_size) - constant memory regardless of file size
- **Database**: O(rows) - one query per row for relations
- **Cache**: O(relations) - relation models cached once
- **Queue**: Parallel processing possible with multiple workers

### Export Performance
- **Memory**: O(chunk_size) - constant memory
- **Database**: Single query with eager loading
- **File**: Streaming write to disk

## Extension Points

### Model Hooks
- `beforeImport()` - Static method called before import starts
- `applyImportContext()` - Instance method before save
- `afterImportSave()` - Instance method after save

### Custom Validation
- FormRequest classes for create/update modes
- Row-level validation with full model context

### Custom Type Casting
- Extend `ImportAttributeCaster` for custom types
- Model cast definitions drive behavior

### Custom Notifications
- Override `ImportCompleteMail` view
- Custom notification jobs
