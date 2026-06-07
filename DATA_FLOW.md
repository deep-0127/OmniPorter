# OmniPorter Data Flow

## Complete Import Flow

This document describes the complete code flow from when an import is scheduled from the frontend to completion.

### High-Level Flow Diagram

```
Frontend → Controller → Model → Queue → GenericImport → Database → Notification
    ↓         ↓         ↓        ↓          ↓           ↓           ↓
  Upload   Validate   Queue   Process    Validate    Save      Email
  File     Request   Job     Chunks     Row Data    Record   Results
```

### Step-by-Step Import Flow

#### Step 1: Frontend Upload
**Location**: Frontend application

The frontend initiates an import by uploading a file:

```http
POST /api/v1/imports/{resource}/{mode}
Content-Type: multipart/form-data

file: [binary file]
```

**Parameters**:
- `resource`: Plural model name (e.g., `employees`, `users`)
- `mode`: Either `create` or `update`

**Example**:
```http
POST /api/v1/imports/employees/create
```

---

#### Step 2: ImportController Receives Request
**Location**: `src/Import/Http/Controllers/ImportController.php:23`

```php
public function importResource(ImportRequest $request, string $resource, string $mode)
```

**Actions**:
1. Validates mode is either `create` or `update`
2. Calls `import()` method

**Code Reference**: `src/Import/Http/Controllers/ImportController.php:23-31`

---

#### Step 3: ImportController::import()
**Location**: `src/Import/Http/Controllers/ImportController.php:33`

```php
public function import(ImportRequest $request, string $resource, bool $update)
```

**Actions**:
1. Singularizes resource name (`employees` → `employee`)
2. Looks up model in static class map
3. Stores uploaded file to configured disk
4. Calls `Model::import()` with file path and parameters

**Code Reference**: `src/Import/Http/Controllers/ImportController.php:33-61`

**Key Code**:
```php
$disk = config('omniporter.import.disk');
$storedFilePath = $request->file('file')->store('imports/uploads', $disk);

$importClass::import($storedFilePath, $update, auth()->user()?->email, 'sync');
```

---

#### Step 4: HasImport::import()
**Location**: `src/Traits/HasImport.php:12`

```php
public static function import(string $filePath, bool $update, ?string $notifiableEmail = null, string $associationMethod = "sync",): void
```

**Actions**:
1. Generates unique batch ID (UUID)
2. Queues GenericImport job via Maatwebsite/Excel

**Code Reference**: `src/Traits/HasImport.php:12-21`

**Key Code**:
```php
$batchId = Str::uuid()->toString();

Excel::queueImport(
    new GenericImport(static::class, $update, $associationMethod, $notifiableEmail, $batchId, $filePath),
    $filePath,
    config('omniporter.import.disk')
);
```

---

#### Step 5: GenericImport Constructor
**Location**: `src/Import/Imports/GenericImport.php:41`

```php
public function __construct(
    private $model,
    private bool $update,
    private string $associationMethod,
    private ?string $notifiableEmail,
    private string $batchId,
    private string $filePath,
)
```

**Actions**:
1. Stores import parameters
2. Instantiates ImportDetailsCache with batch ID
3. Logs import start

**Code Reference**: `src/Import/Imports/GenericImport.php:41-55`

---

#### Step 6: BeforeImport Event
**Location**: `src/Import/Imports/GenericImport.php:303`

```php
BeforeImport::class => function ($event) {
```

**Actions**:
1. Gets total row count from Excel reader
2. Sets total rows in ImportDetailsCache
3. Persists cache to Redis
4. Calls model's `beforeImport()` hook if exists

**Code Reference**: `src/Import/Imports/GenericImport.php:303-319`

**Key Code**:
```php
$totalRows = $event->getReader()->getTotalRows();
$this->instance->setTotalRows(max(0, $totalRowsCount));
$this->instance->persist();
```

---

#### Step 7: Chunked Processing Begins
**Location**: `src/Import/Imports/GenericImport.php:57`

```php
public function chunkSize(): int
{
    return config('omniporter.import.chunk_size', 500);
}
```

**Actions**:
1. Excel reads file in chunks (default: 500 rows)
2. Each chunk triggers `onRow()` for each row

---

#### Step 8: onRow() - Per Row Processing
**Location**: `src/Import/Imports/GenericImport.php:72`

```php
public function onRow(Row $row)
```

**Actions**:
1. Increments processed rows counter
2. Starts database transaction
3. Maps Excel headings to model fields
4. Casts attribute values
5. Resolves BelongsTo relations
6. Finds or creates model instance
7. Applies import context
8. Validates row data
9. Saves model to database
10. Processes BelongsToMany relations
11. Records result
12. Commits transaction
13. Calls `afterImportSave()` hook

**Code Reference**: `src/Import/Imports/GenericImport.php:72-230`

---

#### Step 9: Field Heading Mapping
**Location**: `src/Import/Helpers/ImportDetailsCache.php:108`

```php
public function initializeFieldHeadingMap($headings)
```

**Actions**:
1. Maps Excel column headings to model fillable fields
2. Priority order:
   - Exact match with relation field
   - Exact match with field name
   - Fuzzy match with relation field
   - Fuzzy match with field name
3. Stores mapping in cache

**Code Reference**: `src/Import/Helpers/ImportDetailsCache.php:108-169`

---

#### Step 10: Attribute Casting
**Location**: `src/Import/Helpers/ImportAttributeCaster.php:12`

```php
public static function castAttribute(Model $model, string $field, mixed $value): mixed
```

**Actions**:
1. Gets cast definition from model
2. Applies appropriate casting:
   - Boolean: `filter_var($value, FILTER_VALIDATE_BOOLEAN)`
   - Integer: `filter_var($value, FILTER_VALIDATE_INT)`
   - Float: `filter_var($value, FILTER_VALIDATE_FLOAT)`
   - Date/DateTime: Excel serial date or string parsing
   - Enum: Value or case name matching
   - String: Trim whitespace

**Code Reference**: `src/Import/Helpers/ImportAttributeCaster.php:12-79`

---

#### Step 11: BelongsTo Relation Resolution
**Location**: `src/Import/Imports/GenericImport.php:114`

```php
foreach ($this->instance->getRelationDetailsList() as $method => $details) {
    if ($details['type'] !== 'belongsTo') continue;
```

**Actions**:
1. For each BelongsTo relation:
   - Gets related value from Excel
   - Looks up in cached related models
   - If not found, queries database
   - Updates cache with found ID
   - Sets foreign key on model

**Code Reference**: `src/Import/Imports/GenericImport.php:114-144`

**Key Code**:
```php
$relatedId = $relationModel::where(
    $relationModel::getUniqueKeyForImportExport(),
    $relatedValue
)->value('id');

$mappedData[$relationField] = $relatedId;
```

---

#### Step 12: Model Instance Resolution
**Location**: `src/Import/Imports/GenericImport.php:146`

```php
if ($this->update) {
    $uniqueKeys = $this->model::getUniqueKeysForUpdate();
    $modelInstance = $this->findExistingModel($uniqueKeys, $mappedData);
} else {
    $modelInstance = new $this->model();
}
```

**Actions**:
- **Update mode**: Finds existing record by unique keys
- **Create mode**: Creates new model instance

**Code Reference**: `src/Import/Imports/GenericImport.php:146-157`

---

#### Step 13: Apply Import Context
**Location**: `src/Import/Imports/GenericImport.php:160`

```php
$modelInstance->applyImportContext([
    'notifiable_email' => $this->notifiableEmail,
    'source' => 'excel',
    'batch_id' => $this->batchId,
    'is_update' => $this->update,
    'provided_attributes' => $mappedData
]);
```

**Actions**:
1. Calls model's `applyImportContext()` hook
2. Allows model to set default values (e.g., `created_by`, `organization_id`)

**Code Reference**: `src/Import/Imports/GenericImport.php:160-166`

---

#### Step 14: Validation
**Location**: `src/Import/Imports/GenericImport.php:169`

```php
$validationFile = $this->instance->getValidationFileInstance();
$attributes = $modelInstance->getAttributes();
$validationData = array_merge($attributes, $mappedData);

$validatorRules = $this->update 
    ? $validationFile->rules($modelInstance->id, true) 
    : $validationFile->rules(true);

$validator = Validator::make($validationData, $validatorRules);

if ($validator->fails()) {
    throw new ImportException($validator, $rowIndex);
}
```

**Actions**:
1. Gets FormRequest validator from model config
2. Merges existing attributes with new data
3. Gets rules for create or update mode
4. Validates data
5. Throws ImportException on failure

**Code Reference**: `src/Import/Imports/GenericImport.php:169-187`

---

#### Step 15: Save Model
**Location**: `src/Import/Imports/GenericImport.php:192`

```php
$modelInstance->fill($mappedData);
$modelInstance->save();
```

**Actions**:
1. Fills model with mapped data
2. Saves to database
3. Generates ID for new records

**Code Reference**: `src/Import/Imports/GenericImport.php:192-198`

---

#### Step 16: BelongsToMany Relation Processing
**Location**: `src/Import/Imports/GenericImport.php:257`

```php
private function processBelongsToMany($modelInstance, $row)
```

**Actions**:
1. For each BelongsToMany relation:
   - Parses comma-separated values from Excel
   - Looks up each related model
   - Syncs/attaches related IDs to model

**Code Reference**: `src/Import/Imports/GenericImport.php:257-289`

**Key Code**:
```php
$relatedValues = array_map('trim', explode(',', $row[$heading]));
$modelInstance->{$method}()->{$this->associationMethod}($relatedIds);
```

---

#### Step 17: AfterImportSave Hook
**Location**: `src/Import/Imports/GenericImport.php:214`

```php
$modelInstance->afterImportSave([
    'is_update' => $this->update,
    'row_index' => $rowIndex,
]);
```

**Actions**:
1. Calls model's `afterImportSave()` hook
2. Allows model to trigger side effects (events, notifications, audit logs)

**Code Reference**: `src/Import/Imports/GenericImport.php:214-217`

---

#### Step 18: Record Result
**Location**: `src/Import/Imports/GenericImport.php:291`

```php
private function recordResult(int $rowIndex, array $rowData, string $status, string $message): void
```

**Actions**:
1. Stores row result with status and message
2. Statuses: `success`, `partial_success`, `error`

**Code Reference**: `src/Import/Imports/GenericImport.php:291-298`

---

#### Step 19: AfterChunk Event
**Location**: `src/Import/Imports/GenericImport.php:320`

```php
AfterChunk::class => function ($event) {
```

**Actions**:
1. Writes chunk results to JSONL file
2. Clears results array for next chunk

**Code Reference**: `src/Import/Imports/GenericImport.php:320-332`

**Key Code**:
```php
$filePath = self::getChunkFilePath($this->batchId, $chunkIndex);
$content = '';
foreach ($this->results as $result) {
    $content .= json_encode($result) . "\n";
}
Storage::disk($disk)->put($filePath, $content);
```

---

#### Step 20: AfterImport Event
**Location**: `src/Import/Imports/GenericImport.php:333`

```php
AfterImport::class => function ($event) {
```

**Actions**:
1. Deletes ImportDetailsCache from Redis
2. Deletes uploaded file
3. Merges all chunk result files
4. Counts failed rows
5. Generates final Excel result file
6. Queues email notification job

**Code Reference**: `src/Import/Imports/GenericImport.php:333-368`

**Key Code**:
```php
$this->instance->delete();
Storage::disk($disk)->delete($this->filePath);

$finalExcelPath = $this->getFinalExcelPath($batchId);
$export = new ResultExport($merged);
Excel::store($export, $finalExcelPath, $disk);

if ($this->notifiableEmail) {
    DispatchCompleteImportNotificationJob::dispatch($this->notifiableEmail, $finalExcelPath, $failedRows, $disk);
}
```

---

#### Step 21: Email Notification
**Location**: `src/Import/Jobs/DispatchCompleteImportNotificationJob.php`

**Actions**:
1. Sends email to notifiable address
2. Attaches result Excel file
3. Includes failed row count

**Code Reference**: `src/Import/Jobs/DispatchCompleteImportNotificationJob.php`

---

## Complete Export Flow

### High-Level Flow Diagram

```
Frontend → Controller → Model → Queue → GenericExport → File → Notification
    ↓         ↓         ↓        ↓          ↓          ↓          ↓
 Request   Validate   Queue   Process    Generate   Store     Email
 Params   Columns    Job     Query     Excel     File     Results
```

### Step-by-Step Export Flow

#### Step 1: Frontend Request
**Location**: Frontend application

```http
GET /api/v1/exports/{resource}?columns=name,email&type=xlsx
```

**Parameters**:
- `resource`: Plural model name
- `columns`: Comma-separated column names (optional)
- `type`: Export format (`xlsx` or `csv`, default: `xlsx`)
- Additional params: Filter conditions

---

#### Step 2: ExportController::exportResource()
**Location**: `src/Export/Http/Controllers/ExportController.php:25`

```php
public function exportResource(Request $request, string $resource)
```

**Actions**:
1. Singularizes resource name
2. Looks up model in class map
3. Validates requested columns
4. Gets exportable columns from model
5. Calls `Model::export()`

**Code Reference**: `src/Export/Http/Controllers/ExportController.php:25-61`

---

#### Step 3: HasExport::export()
**Location**: `src/Traits/HasExport.php:13`

```php
public static function export(array $exportableColumns, array $columns, array $filters, ?string $notifiableEmail = null, string $exportType = 'xlsx'): void
```

**Actions**:
1. Generates unique batch ID
2. Queues GenericExport job
3. Chains notification job

**Code Reference**: `src/Traits/HasExport.php:13-26`

---

#### Step 4: GenericExport Processing
**Location**: `src/Export/Exports/GenericExport.php`

**Actions**:
1. Builds query with filters
2. Eager loads relations
3. Maps model attributes to export format
4. Generates Excel file
5. Stores to disk
6. Triggers notification

---

## Sequence Diagram: Import Flow

```
Frontend    Controller    Model    Queue    GenericImport    Cache    DB    Notification
   |            |           |         |           |          |      |          |
   |--Upload-->|           |         |           |          |      |          |
   |            |--Store-->|         |           |          |      |          |
   |            |--Queue-->|         |           |          |      |          |
   |            |           |         |--Job---->|          |      |          |
   |            |           |         |           |--Init-->|      |          |
   |            |           |         |           |<--Cache--|      |          |
   |            |           |         |           |--Read-->|      |          |
   |            |           |         |           |<--Data---|      |          |
   |            |           |         |           |--Map----|      |          |
   |            |           |         |           |--Cast---|      |          |
   |            |           |         |           |--Validate|      |          |
   |            |           |         |           |--Save-->|      |          |
   |            |           |         |           |<--ID-----|      |          |
   |            |           |         |           |--Persist|      |          |
   |            |           |         |           |          |      |          |
   |            |           |         |           |--Notify-------->          |
   |            |           |         |           |          |      |          |
   |<--Response-|           |         |           |          |      |          |
```

## Progress Tracking

OmniPorter provides real-time progress tracking for imports via the `ProgressUpdated` event and `ProgressController`.

### ProgressUpdated Event
**Location**: `src/Shared/Events/ProgressUpdated.php`

Broadcasts progress updates via Laravel's broadcasting system:
- `batchId`: Unique batch identifier
- `type`: Import or export
- `progress`: Percentage complete (0-100)
- `totalRows`: Total number of rows
- `processedRows`: Number of rows processed

**Channel**: `omniporter-progress.{batchId}`

### Progress Polling
If WebSocket broadcasting is not available, frontend can poll for progress:

```http
GET /api/v1/imports/progress/{batchId}
```

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

**Code Reference**: `src/Import/Http/Controllers/ProgressController.php:12`

---

## Key Decision Points

### 1. Create vs Update Mode
**Decision Point**: `ImportController::importResource()`

- **Create**: Inserts new records
- **Update**: Updates existing records by unique keys
- **Validation**: Different FormRequest classes for each mode

### 2. Relation Resolution Strategy
**Decision Point**: `GenericImport::onRow()`

- **BelongsTo**: Look up by unique key, set foreign key
- **BelongsToMany**: Parse CSV, look up each, sync/attach
- **Caching**: Related models cached to reduce queries

### 3. Error Handling Strategy
**Decision Point**: `GenericImport::onRow()`

- **Validation Errors**: Record error, continue to next row
- **Database Errors**: Rollback transaction, record error
- **System Errors**: Log full trace, record generic error

### 4. Chunk Size Selection
**Decision Point**: `GenericImport::chunkSize()`

- **Default**: 500 rows
- **Configurable**: Via `omniporter.import.chunk_size`
- **Trade-off**: Larger chunks = fewer queue jobs, more memory

### 5. Result Storage Strategy
**Decision Point**: `AfterChunk` and `AfterImport` events

- **Per Chunk**: JSONL files (one line per row)
- **Final**: Excel file with all results
- **Cleanup**: Chunk files deleted after merge

## Data Transformations

### Excel → Model
1. **Heading Mapping**: Excel columns → Model fields
2. **Type Casting**: String values → Typed values
3. **Relation Resolution**: Names/IDs → Foreign keys
4. **Validation**: Raw data → Validated data
5. **Context Application**: Validated data → Contextualized data

### Model → Result
1. **Status Assignment**: Success/Error/Partial
2. **Message Generation**: Descriptive status messages
3. **Row Index**: Original row number
4. **Data Preservation**: Original row data for reference

## Performance Considerations

### Memory Management
- Chunked processing prevents memory overflow
- Results cleared after each chunk
- Relation models cached, not entire tables

### Database Efficiency
- One query per row for relations (cached)
- Transaction per row for atomicity
- Batch operations for BelongsToMany

### Queue Performance
- Multiple workers can process chunks in parallel
- Redis cache shared across workers
- Result files written to storage, not memory

## Error Recovery

### Chunk Failure
- Failed chunk is re-queued by Laravel
- Previous chunks' results preserved
- Cache persists across retries

### Row Failure
- Transaction rolled back
- Error recorded in results
- Next row processed independently

### Import Failure
- Partial results preserved in result file
- Error details in logs
- User notified with failure count
