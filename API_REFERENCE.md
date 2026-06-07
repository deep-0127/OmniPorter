# OmniPorter API Reference

This document provides a complete reference for all public APIs, configuration options, and extension points in OmniPorter.

## Table of Contents

- [HTTP Endpoints](#http-endpoints)
- [HasImport Trait](#hasimport-trait)
- [HasExport Trait](#hasexport-trait)
- [Configuration](#configuration)
- [Event Hooks](#event-hooks)
- [Console Commands](#console-commands)

---

## HTTP Endpoints

### Import Endpoints

#### POST /api/v1/imports/{resource}/{mode}

Initiates an import for a specified resource.

**Parameters**:
- `resource` (path): Plural model name (e.g., `employees`, `users`)
- `mode` (path): Import mode - `create` or `update`

**Request Body**:
```http
Content-Type: multipart/form-data

file: [binary file]
```

**Response**:
```json
{
  "success": true,
  "message": "Import for [employees] has been queued. You'll be notified once it completes."
}
```

**Error Response**:
```json
{
  "success": false,
  "message": "Import failed for [employees]",
  "errors": {
    "error": "Detailed error message"
  }
}
```

**Code Reference**: `src/Import/Http/Controllers/ImportController.php:23`

---

### Export Endpoints

#### GET /api/v1/exports/{resource}

Initiates an export for a specified resource.

**Parameters**:
- `resource` (path): Plural model name (e.g., `employees`, `users`)
- `columns` (query, optional): Comma-separated column names
- `type` (query, optional): Export format (`xlsx` or `csv`, default: `xlsx`)
- Additional query params: Filter conditions

**Example**:
```http
GET /api/v1/exports/employees?columns=name,email,department_id&type=xlsx&department_id=5
```

**Response**:
```json
{
  "success": true,
  "message": "Export for [employees] has been queued. You'll be notified once it completes."
}
```

**Error Response**:
```json
{
  "success": false,
  "message": "Export failed for [employees]",
  "errors": {
    "error": "Detailed error message"
  }
}
```

**Code Reference**: `src/Export/Http/Controllers/ExportController.php:25`

---

### Progress Endpoints

#### GET /api/v1/imports/progress/{batchId}

Retrieves the progress status of an import batch.

**Parameters**:
- `batchId` (path): Unique batch identifier returned when import was queued

**Response**:
```json
{
  "success": true,
  "data": {
    "batch_id": "550e8400-e29b-41d4-a716-446655440000",
    "total_rows": 1000,
    "processed_rows": 750,
    "progress": 75.0,
    "status": "in_progress"
  }
}
```

**Status Values**:
- `pending`: Import has not started
- `in_progress`: Import is currently processing
- `completed`: Import has finished

**Error Response**:
```json
{
  "success": false,
  "message": "Import batch [550e8400-e29b-41d4-a716-446655440000] not found."
}
```

**Code Reference**: `src/Import/Http/Controllers/ProgressController.php:12`

---

## HasImport Trait

### Methods

#### import()

Queues an import job for the model.

```php
public static function import(
    string $filePath,
    bool $update,
    ?string $notifiableEmail = null,
    string $associationMethod = "sync"
): void
```

**Parameters**:
- `$filePath`: Path to the uploaded file
- `$update`: `true` for update mode, `false` for create mode
- `$notifiableEmail`: Email address for completion notification
- `$associationMethod`: Method for BelongsToMany relations (`sync`, `attach`, `detach`)

**Example**:
```php
Employee::import(
    'imports/uploads/employees.xlsx',
    false,  // create mode
    'user@example.com',
    'sync'
);
```

**Code Reference**: `src/Traits/HasImport.php:12`

---

#### getUniqueKeysForUpdate()

Returns the field(s) used to identify records during update operations.

```php
public static function getUniqueKeysForUpdate(): array|string
```

**Returns**:
- String for single-column uniqueness (e.g., `'email'`)
- Array for multi-column uniqueness (e.g., `['user_id', 'month', 'year']`)

**Example**:
```php
// Single key
public static function getUniqueKeysForUpdate(): array|string
{
    return 'email';
}

// Composite key
public static function getUniqueKeysForUpdate(): array|string
{
    return ['employee_id', 'month', 'year'];
}
```

**Code Reference**: `src/Traits/HasImport.php:88`

---

#### getUniqueKeyForImportExport()

Returns the column name used for relation lookups.

```php
public static function getUniqueKeyForImportExport(): string
```

**Returns**: Column name (e.g., `'email'`, `'employee_code'`, `'sku'`)

**Example**:
```php
public static function getUniqueKeyForImportExport(): string
{
    return 'employee_code';
}
```

**Code Reference**: `src/Traits/HasImport.php:102`

---

#### getListOfRelationDetails()

Returns foreign-key relation definitions.

```php
public static function getListOfRelationDetails(): array
```

**Returns**: Array of relation definitions

**Example**:
```php
public static function getListOfRelationDetails(): array
{
    return [
        'department' => [
            'type' => 'belongsTo',
            'model' => Department::class,
            'field' => 'department_id',
        ],
        'skills' => [
            'type' => 'belongsToMany',
            'model' => Skill::class,
        ],
    ];
}
```

**Code Reference**: `src/Traits/HasImport.php:118`

---

#### getImportValidators()

Maps operation names to FormRequest validator classes.

```php
public static function getImportValidators(): array
```

**Returns**: Array mapping operations to validator classes

**Example**:
```php
public static function getImportValidators(): array
{
    return [
        'create' => EmployeeCreateRequest::class,
        'update' => EmployeeUpdateRequest::class,
    ];
}
```

**Code Reference**: `src/Traits/HasImport.php:136`

---

#### applyImportContext()

Hook for setting context before saving the model.

```php
public function applyImportContext(array $context): void
```

**Parameters**:
- `$context`: Array with keys:
  - `notifiable_email`: Email for notifications
  - `source`: Import source (e.g., `'excel'`)
  - `batch_id`: Unique batch identifier
  - `is_update`: Whether this is an update operation
  - `provided_attributes`: Mapped data from Excel

**Example**:
```php
public function applyImportContext(array $context): void
{
    $this->created_by = $context['notifiable_email'] ?? null;
    $this->source = 'import';
    $this->imported_at = now();
}
```

**Code Reference**: `src/Traits/HasImport.php:47`

---

#### afterImportSave()

Hook for actions after the model is saved.

```php
public function afterImportSave(array $context): void
```

**Parameters**:
- `$context`: Array with keys:
  - `is_update`: Whether this was an update
  - `row_index`: Row number in the file

**Example**:
```php
public function afterImportSave(array $context): void
{
    if ($this->wasRecentlyCreated) {
        Mail::to($this->email)->queue(new WelcomeEmail());
    }
    
    Log::info("Imported record {$this->id}");
}
```

**Code Reference**: `src/Traits/HasImport.php:181`

---

## HasExport Trait

### Methods

#### export()

Queues an export job for the model.

```php
public static function export(
    array $exportableColumns,
    array $columns,
    array $filters,
    ?string $notifiableEmail = null,
    string $exportType = 'xlsx'
): void
```

**Parameters**:
- `$exportableColumns`: All columns available for export
- `$columns`: Columns to include in this export
- `$filters`: Filter conditions for the query
- `$notifiableEmail`: Email for completion notification
- `$exportType`: Export format (`xlsx` or `csv`)

**Example**:
```php
Employee::export(
    ['name', 'email', 'department_id'],
    ['name', 'email'],
    ['department_id' => 5],
    'user@example.com',
    'xlsx'
);
```

**Code Reference**: `src/Traits/HasExport.php:13`

---

#### getUniqueKeyForImportExport()

Returns the column name used for relation lookups.

```php
public static function getUniqueKeyForImportExport(): string
```

**Returns**: Column name (e.g., `'email'`, `'employee_code'`)

**Code Reference**: `src/Traits/HasExport.php:28`

---

#### getListOfRelationDetails()

Returns foreign-key relation definitions.

```php
public static function getListOfRelationDetails(): array
```

**Returns**: Array of relation definitions

**Code Reference**: `src/Traits/HasExport.php:38`

---

#### getColumnsToExport()

Returns columns available for export.

```php
public static function getColumnsToExport(): array
```

**Returns**: Array of column names

**Example**:
```php
public static function getColumnsToExport(): array
{
    return ['name', 'email', 'phone', 'department_id'];
}
```

**Code Reference**: `src/Traits/HasExport.php:48`

---

## Configuration

### Model Configuration Property

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
        'skills' => [
            'type' => 'belongsToMany',
            'model' => Skill::class,
        ],
    ],
    'validation' => EmployeeImportRequest::class,
    'columns' => ['name', 'email', 'phone', 'department_id'],
];
```

### Configuration Options

#### unique_key
- **Type**: `string` or `array`
- **Purpose**: Field(s) for identifying records during updates
- **Single key**: `'email'`, `'employee_code'`
- **Composite key**: `['user_id', 'month', 'year']`

#### relations
- **Type**: `array`
- **Purpose**: Define foreign-key relations
- **Structure**:
  ```php
  'relation_name' => [
      'type' => 'belongsTo|belongsToMany',
      'model' => Model::class,
      'field' => 'foreign_key_field',  // for belongsTo only
  ]
  ```

#### validation
- **Type**: `string` or `array`
- **Purpose**: FormRequest class for validation
- **Single validator**: `EmployeeImportRequest::class`
- **Separate validators**:
  ```php
  'validation' => [
      'create' => EmployeeCreateRequest::class,
      'update' => EmployeeUpdateRequest::class,
  ]
  ```

#### columns
- **Type**: `array`
- **Purpose**: Columns available for export
- **Default**: Model's fillable fields

### Package Configuration

Published config file: `config/omniporter.php`

```php
return [
    'import' => [
        'disk' => env('OMNIPORTER_IMPORT_DISK', 'local'),
        'chunk_size' => env('OMNIPORTER_CHUNK_SIZE', 500),
        'queue_connection' => env('OMNIPORTER_QUEUE_CONNECTION', 'sync'),
        'queue_name' => env('OMNIPORTER_QUEUE_NAME', 'imports'),
    ],
    'export' => [
        'disk' => env('OMNIPORTER_EXPORT_DISK', 'local'),
    ],
    'cache' => [
        'store' => env('OMNIPORTER_CACHE_STORE', 'redis'),
        'prefix' => env('OMNIPORTER_CACHE_PREFIX', 'omniporter'),
        'ttl' => env('OMNIPORTER_CACHE_TTL', 3600),
    ],
    'discovery' => [
        'model_paths' => [
            'app/**/Domain/**/Models/*.php',
        ],
    ],
];
```

---

## Event Hooks

### beforeImport()

Static method called before import processing begins.

```php
public static function beforeImport(array $context): void
```

**Parameters**:
- `$context`: Array with keys:
  - `batch_id`: Unique batch identifier
  - `file_path`: Path to uploaded file
  - `update`: Whether this is an update operation

**Example**:
```php
public static function beforeImport(array $context): void
{
    Log::info("Starting import batch {$context['batch_id']}");
    
    // Clear any temporary data
    Cache::forget('import_temp_data');
}
```

**Code Reference**: `src/Import/Imports/GenericImport.php:312`

---

### applyImportContext()

Instance method called before saving each row.

```php
public function applyImportContext(array $context): void
```

**Parameters**:
- `$context`: Array with import context data

**Example**:
```php
public function applyImportContext(array $context): void
{
    $this->created_by = $context['notifiable_email'];
    $this->source = 'import';
}
```

**Code Reference**: `src/Traits/HasImport.php:47`

---

### afterImportSave()

Instance method called after saving each row.

```php
public function afterImportSave(array $context): void
```

**Parameters**:
- `$context`: Array with keys:
  - `is_update`: Whether this was an update
  - `row_index`: Row number in the file

**Example**:
```php
public function afterImportSave(array $context): void
{
    if ($this->wasRecentlyCreated) {
        event(new EmployeeCreated($this));
    }
}
```

**Code Reference**: `src/Traits/HasImport.php:181`

---

## Console Commands

### omniporter:scan

Scans for importable/exportable models and updates the cache.

```bash
php artisan omniporter:scan
```

**Purpose**: Refresh the model discovery cache after adding new models.

**Code Reference**: `src/Import/Console/Commands/ScanImportableModelsCommand.php`

---

### omniporter:import

Run an import from the command line.

```bash
php artisan omniporter:import {model} {file} {mode}
```

**Parameters**:
- `model`: Model class name
- `file`: Path to Excel/CSV file
- `mode`: `create` or `update`

**Example**:
```bash
php artisan omniporter:import "App\Models\Employee" employees.xlsx create
```

**Code Reference**: `src/Import/Console/Commands/ImportCommand.php`

---

### omniporter:export

Run an export from the command line.

```bash
php artisan omniporter:export {model} {output} {--columns=*} {--type=xlsx}
```

**Parameters**:
- `model`: Model class name
- `output`: Output file path

**Options**:
- `--columns`: Columns to export (comma-separated)
- `--type`: Export format (`xlsx` or `csv`)

**Example**:
```bash
php artisan omniporter:export "App\Models\Employee" employees.xlsx --columns=name,email --type=xlsx
```

**Code Reference**: `src/Export/Console/Commands/ExportCommand.php`

---

### omniporter:clear-cache

Clear the OmniPorter cache.

```bash
php artisan omniporter:clear-cache
```

**Purpose**: Clear all cached import/export data.

**Code Reference**: `src/Console/Commands/ClearCacheCommand.php`

---

### omniporter:cleanup

Clean up old import batches.

```bash
php artisan omniporter:cleanup {--days=7}
```

**Options**:
- `--days`: Delete batches older than N days (default: 7)

**Purpose**: Remove old import files and cache entries.

**Code Reference**: `src/Import/Console/Commands/ImportBatchCleanupCommand.php`

---

### omniporter:scaffold

Generate a model stub with OmniPorter configuration.

```bash
php artisan omniporter:scaffold {name}
```

**Parameters**:
- `name`: Model name

**Example**:
```bash
php artisan omniporter:scaffold Employee
```

**Code Reference**: `src/Console/Commands/ScaffoldCommand.php`

---

## Contracts & Interfaces

### ImportValidationInterface

Interface that must be implemented by any validation class used by OmniPorter.

**Location**: `src/Contracts/ImportValidationInterface.php`

**Method**:
```php
public function rules($id = null, bool $isUpdate = false): array
```

**Parameters**:
- `$id`: The ID of the resource (for update operations)
- `$isUpdate`: Whether this is an update operation

**Returns**: Array of validation rules

**Example**:
```php
use OmniPorter\Contracts\ImportValidationInterface;
use Illuminate\Foundation\Http\FormRequest;

class EmployeeImportRequest extends FormRequest implements ImportValidationInterface
{
    public function rules($id = null, bool $isUpdate = false): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                $isUpdate
                    ? Rule::unique('employees', 'email')->ignore($id)
                    : Rule::unique('employees', 'email'),
            ],
        ];
    }
}
```

**Purpose**: Ensures validation classes provide the required `rules()` method with support for both create and update modes.

---

## Helper Classes

### ImportAttributeCaster

Handles type casting for import attributes.

**Location**: `src/Import/Helpers/ImportAttributeCaster.php`

**Static Method**:
```php
public static function castAttribute(Model $model, string $field, mixed $value): mixed
```

**Supported Casts**:
- Boolean: `bool`, `boolean`
- Integer: `int`, `integer`
- Float: `float`, `double`, `real`, `decimal`
- Date: `date`, `datetime`, `immutable_date`, `immutable_datetime`
- Enum: Any PHP enum with `tryFrom()` method
- String: `string`

---

### ImportDetailsCache

Manages import state across chunk processing.

**Location**: `src/Import/Helpers/ImportDetailsCache.php`

**Key Methods**:
```php
// Get or create instance
public static function getInstance(string $batchId, $model, bool $update): self

// Initialize field-heading mapping
public function initializeFieldHeadingMap($headings): void

// Persist to cache
public function persist(): void

// Delete from cache
public function delete(): void

// Progress tracking
public function setTotalRows(int $totalRows): void
public function setProcessedRows(int $processedRows): void
public function incrementProcessedRows(int $count = 1): void
```

---

### ExportDetailsCache

Manages export state and configuration.

**Location**: `src/Export/Helpers/ExportDetailsCache.php`

---

## Exceptions

### ImportException

Thrown when import validation fails.

**Location**: `src/Exceptions/ImportException.php`

**Constructor**:
```php
public function __construct($validator, int $rowIndex, string $message = '')
```

---

### ExportException

Thrown when export fails.

**Location**: `src/Exceptions/ExportException.php`

---

### OmniPorterException

Base exception for all OmniPorter errors.

**Location**: `src/Exceptions/OmniPorterException.php`

---

## Mail Classes

### ImportCompleteMail

Email notification sent when import completes.

**Location**: `src/Import/Mail/ImportCompleteMail.php`

**Constructor**:
```php
public function __construct(
    private string $filePath,
    private int $failedRows,
    private ?string $disk = null
)
```

**View**: `emails.import_complete`

---

### ExportCompleteMail

Email notification sent when export completes.

**Location**: `src/Export/Mail/ExportCompleteMail.php`

---

## Jobs

### DispatchCompleteImportNotificationJob

Queues email notification after import completion.

**Location**: `src/Import/Jobs/DispatchCompleteImportNotificationJob.php`

---

### DispatchCompleteExportNotificationJob

Queues email notification after export completion.

**Location**: `src/Export/Jobs/DispatchCompleteExportNotificationJob.php`

---

## Events

### ProgressUpdated

Event dispatched when import progress updates.

**Location**: `src/Shared/Events/ProgressUpdated.php`

**Properties**:
- `batchId`: Unique batch identifier
- `processedRows`: Number of rows processed
- `totalRows`: Total number of rows

---

## Service Provider

### OmniPorterServiceProvider

Registers OmniPorter with Laravel.

**Location**: `src/OmniPorterServiceProvider.php`

**Key Methods**:
```php
public function register(): void
public function boot(): void
```

**Responsibilities**:
- Merge configuration
- Load routes
- Auto-discover models
- Register commands
- Publish assets

---

## Routes

### Import Routes

**File**: `src/Import/Http/Routes/v1/imports.php`

```php
Route::prefix('imports')->group(function () {
    Route::post('/{resource}/{mode}', [ImportController::class, 'importResource']);
});
```

### Export Routes

**File**: `src/Export/Http/Routes/v1/exports.php`

```php
Route::prefix('exports')->group(function () {
    Route::get('/{resource}', [ExportController::class, 'exportResource']);
});
```

---

## Validation

### ImportRequest

Form request for import endpoint validation.

**Location**: `src/Import/Domain/Import/Validators/ImportRequest.php`

**Rules**:
```php
public function rules(): array
{
    return [
        'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
    ];
}
```

---

## Result Export

### ResultExport

Exports import results as Excel file.

**Location**: `src/Import/Results/ResultExport.php`

**Implements**:
- `FromCollection`
- `WithHeadings`
- `WithMapping`

**Columns**:
- `row`: Row number
- `status`: Success/error/partial_success
- `message`: Status message
- All original row data

---

## API Response Format

### Success Response

```json
{
  "success": true,
  "message": "Operation completed successfully"
}
```

### Error Response

```json
{
  "success": false,
  "message": "Operation failed",
  "errors": {
    "field": ["Error message"]
  }
}
```

### Not Found Response

```json
{
  "success": false,
  "message": "Resource not found"
}
```

---

## File Storage Paths

### Import Files

- **Upload**: `imports/uploads/{filename}`
- **Results**: `imports/results/{batchId}/`
- **Chunk Files**: `imports/results/{batchId}/chunk-{index}.jsonl`
- **Final Result**: `imports/results/{batchId}/final_result_{batchId}.xlsx`

### Export Files

- **Output**: `exports/{batchId}.{type}`

---

## Environment Variables

```env
# Import Configuration
OMNIPORTER_IMPORT_DISK=local
OMNIPORTER_CHUNK_SIZE=500
OMNIPORTER_QUEUE_CONNECTION=sync
OMNIPORTER_QUEUE_NAME=imports

# Export Configuration
OMNIPORTER_EXPORT_DISK=local

# Cache Configuration
OMNIPORTER_CACHE_STORE=redis
OMNIPORTER_CACHE_PREFIX=omniporter
OMNIPORTER_CACHE_TTL=3600
```
