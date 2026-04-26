<?php

namespace OmniPorter\Import\Imports;

use OmniPorter\Exceptions\ImportException;
use OmniPorter\Import\Helpers\ImportAttributeCaster;
use OmniPorter\Import\Helpers\ImportDetailsCache;
use OmniPorter\Import\Jobs\DispatchCompleteImportNotificationJob;
use OmniPorter\Import\Results\ResultExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OmniPorter\Shared\Events\ProgressUpdated;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;


use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\RegistersEventListeners;
use Maatwebsite\Excel\Concerns\RemembersChunkOffset;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Events\AfterChunk;
use Maatwebsite\Excel\Events\AfterImport;
use Maatwebsite\Excel\Events\BeforeImport;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Row;

class GenericImport implements OnEachRow, WithHeadingRow, WithEvents, WithChunkReading, ShouldQueue
{
    use RemembersChunkOffset;

    public $connection;
    public $queue;

    private ImportDetailsCache $instance;
    private array $results = [];
    private ?int $customChunkSize = null;

    public function __construct(
        private $model,
        private bool $update,
        private string $associationMethod,
        private ?string $notifiableEmail,
        private string $batchId,
        private string $filePath,
        ?int $chunkSize = null,
    ) {
        $this->customChunkSize = $chunkSize;
        Log::info("GenericImport instantiated", [
            'model' => $this->model,
            'batchId' => $this->batchId,
            'filePath' => $this->filePath,
            'chunkSize' => $this->chunkSize()
        ]);
        $this->instance = ImportDetailsCache::getInstance($this->batchId, $this->model, $this->update);
        
        $this->connection = config('omniporter.import.queue_connection', 'sync');
        $this->queue = config('omniporter.import.queue_name', 'imports');
    }

    public function __wakeup()
    {
        // Reload instance from cache when job is picked up by a worker
        $this->instance = ImportDetailsCache::getInstance($this->batchId, $this->model, $this->update);
    }

    public function chunkSize(): int
    {
        return $this->customChunkSize ?? config('omniporter.import.chunk_size', 500);
    }



    public function onRow(Row $row)
    {
        $rowIndex = $row->getIndex();
        $rawRow = $row->toArray();
        Log::info("Processing row {$rowIndex}", ['rawRow' => $rawRow]);
        $mappedData = [];
        $warnings = [];

        $processed = $this->instance->incrementProcessedRows();
        $totalRows = $this->instance->getTotalRows();

        if ($totalRows > 0 && ($processed % $this->chunkSize() === 0 || $processed === $totalRows)) {
            $progress = (int) (($processed / $totalRows) * 100);
            Log::info("OmniPorter Import Progress Update", [
                'batch_id' => $this->batchId,
                'processed' => $processed,
                'total' => $totalRows,
                'progress' => $progress
            ]);

            event(new ProgressUpdated(
                $this->batchId,
                'import',
                $progress,
                $totalRows,
                $processed
            ));
        }


        DB::beginTransaction();
        try {
            if (empty($this->instance->getFieldHeadingMap())) {
                Log::info("FieldHeadingMap is empty, initializing...");
                $this->instance = ImportDetailsCache::getInstance($this->batchId, $this->model, $this->update);
                if (empty($this->instance->getFieldHeadingMap())) {
                    $this->instance->initializeFieldHeadingMap(array_keys($rawRow));
                    Log::info("Initialized FieldHeadingMap", ['map' => $this->instance->getFieldHeadingMap()]);
                }
            }

            // 1. Data Mapping & Casting
            // We use a temporary instance just for metadata (casts/fillable) during casting
            $tempInstance = new $this->model();
            foreach ($this->instance->getFieldHeadingMap() as $field => $heading) {
                $value = $rawRow[$heading] ?? null;

                if ($value === null || $value === "") {
                    $mappedData[$field] = null;
                    continue;
                }

                $mappedData[$field] = ImportAttributeCaster::castAttribute($tempInstance, $field, $value);
            }

            Log::info("Row processing debug", [
                'rowIndex' => $rowIndex,
                'rawRow' => $rawRow,
                'mappedData' => $mappedData
            ]);

            // 2. Handle BelongsTo Relations
            foreach ($this->instance->getRelationDetailsList() as $method => $details) {
                if ($details['type'] !== 'belongsTo') continue;

                $relationModel = $details['model'];
                $relationField = $details['field'];

                $heading = $this->instance->getFieldHeadingMap()[$relationField] ?? null;
                $relatedValue = $heading ? ($rawRow[$heading] ?? null) : null;

                if (!$relatedValue) {
                    continue;
                }

                // Trim trailing/leading spaces from Excel data
                $relatedValue = is_string($relatedValue) ? trim($relatedValue) : $relatedValue;

                if (isset($this->instance->getRelatedModelsCache()[$relationModel][$relatedValue])) {
                    $mappedData[$relationField] = $this->instance->getRelatedModelsCache()[$relationModel][$relatedValue];
                } else {
                    $relationModelInstance = new $relationModel();
                    $relatedId = $relationModel::where(
                        $relationModel::getUniqueKeyForImportExport(),
                        $relatedValue
                    )->value($relationModelInstance->getKeyName());

                    if ($relatedId) {
                        $this->instance->updateRelatedModelsCache($relationModel, $relatedValue, $relatedId);
                        $mappedData[$relationField] = $relatedId;
                    } elseif (method_exists($relationModel, 'getAutoCreateAttributesOnImport')) {
                        // Auto-create the missing related record
                        $autoAttributes = $relationModel::getAutoCreateAttributesOnImport($relatedValue);
                        if (is_array($autoAttributes) && !empty($autoAttributes)) {
                            try {
                                $newRelated = $relationModel::create($autoAttributes);
                                $this->instance->updateRelatedModelsCache($relationModel, $relatedValue, $newRelated->getKey());
                                $mappedData[$relationField] = $newRelated->getKey();
                                $warnings[] = "Auto-created '{$heading}': {$relatedValue}";
                                Log::info("Auto-created related record", [
                                    'model' => $relationModel,
                                    'value' => $relatedValue,
                                    'id' => $newRelated->getKey()
                                ]);
                            } catch (\Exception $e) {
                                $mappedData[$relationField] = null;
                                $warnings[] = "Failed to auto-create '{$heading}' ({$relatedValue}): {$e->getMessage()}";
                                Log::warning("Auto-create failed", ['model' => $relationModel, 'value' => $relatedValue, 'error' => $e->getMessage()]);
                            }
                        } else {
                            $mappedData[$relationField] = null;
                            $warnings[] = "Relation '{$heading}' ({$relatedValue}) not found";
                        }
                    } else {
                        $mappedData[$relationField] = null;
                        $warnings[] = "Relation '{$heading}' ({$relatedValue}) not found";
                    }
                }
            }

            // 3. Resolve Model Instance
            $modelInstance = null;
            if ($this->update) {
                $uniqueKeys = $this->model::getUniqueKeysForUpdate();
                $modelInstance = $this->findExistingModel($uniqueKeys, $mappedData);

                if (!$modelInstance) {
                    throw new ImportException(null, $rowIndex, "Update failed: Record not found for provided unique keys.");
                }
            } else {
                $modelInstance = new $this->model();
            }

            // 4. Fill and Apply Context
            // We fill first so applyImportContext can see the provided data and apply fallbacks
            $modelInstance->fill($mappedData);
            
            $modelInstance->applyImportContext([
                'notifiable_email' => $this->notifiableEmail,
                'source' => 'excel',
                'batch_id' => $this->batchId,
                'is_update' => $this->update,
                'provided_attributes' => $mappedData
            ]);

            // Run beforeImportValidation hook
            $modelInstance->beforeImportValidation($mappedData);
            // Sync the model with the potentially modified mappedData
            $modelInstance->fill($mappedData);

            // 5. Validation
            $validationFile = $this->instance->getValidationFileInstance();
            
            // We validate the actual attributes of the model instance after filling and context application
            $validationData = $modelInstance->getAttributes();
            
            // Remove internal fields that shouldn't be validated
            unset($validationData['password'], $validationData['remember_token'], $validationData['created_at'], $validationData['updated_at'], $validationData['deleted_at']);
            
            $validatorRules = $this->update 
                ? $validationFile->rules($modelInstance->id, true) 
                : $validationFile->rules(true);

            $validator = Validator::make($validationData, $validatorRules);

            if ($validator->fails()) {
                Log::error("Validation failed for row {$rowIndex}", [
                    'errors' => $validator->errors()->toArray(),
                    'data' => $validationData
                ]);
                throw new ImportException($validator, $rowIndex);
            }

            // 6. Save
            Log::info("Saving model instance for row {$rowIndex}", [
                'modelAttributes' => $modelInstance->getAttributes()
            ]);
            $modelInstance->save();
            Log::info("Saved model instance for row {$rowIndex}, ID: {$modelInstance->id}");

            // Cache the newly saved model for subsequent rows/chunks to avoid DB queries and potential visibility issues
            $uniqueKey = $this->model::getUniqueKeyForImportExport();
            $uniqueValue = $modelInstance->{$uniqueKey};
            if ($uniqueValue) {
                $this->instance->updateRelatedModelsCache($this->model, $uniqueValue, $modelInstance->id);
            }

            $this->processBelongsToMany($modelInstance, $rawRow);

            if (!empty($warnings)) {
                $rowStatus = 'partial_success';
                $finalMessage = 'Imported with warnings: ' . implode(', ', $warnings);
            } else {
                $rowStatus = 'success';
                $finalMessage = $this->update ? 'Updated successfully' : 'Imported successfully';
            }

            $this->recordResult($rowIndex, $rawRow, $rowStatus, $finalMessage);
            DB::commit();

            // Persist the updated cache so other chunks/rows can see it
            $this->instance->persist();

        } catch (UniqueConstraintViolationException $e) {
            DB::rollBack();
            $this->recordResult($rowIndex, $rawRow, 'error', "Duplicate Entry: " . $e->getMessage());
        } catch (ImportException $e) {
            DB::rollBack();
            $this->recordResult($rowIndex, $rawRow, 'error', $e->getMessage());
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Import Error Row {$rowIndex}: " . $e->getMessage());
            $this->recordResult($rowIndex, $rawRow, 'error', "System Error");
        }

        // 7. Post-save Hook (runs outside transaction to prevent email/notification
        //    failures from rolling back already-committed data)
        if (isset($modelInstance) && $modelInstance->exists) {
            try {
                $modelInstance->afterImportSave([
                    'is_update' => $this->update,
                    'row_index' => $rowIndex,
                ]);
            } catch (\Exception $e) {
                Log::warning("Post-save hook failed for row {$rowIndex}: " . $e->getMessage());
            }
        }
    }

    private function findExistingModel($uniqueKeys, $attributes)
    {
        // BUG FIX: existingModelsCache is now a query builder (not a Collection)
        // to avoid loading the entire table into RAM.
        $query = $this->instance->getExistingModelsCache();

        Log::info("Finding existing model", [
            'uniqueKeys' => $uniqueKeys,
            'attributes_subset' => array_intersect_key($attributes, array_flip((array)$uniqueKeys))
        ]);

        if (is_array($uniqueKeys)) {
            foreach ($uniqueKeys as $key) {
                $query->where($key, $attributes[$key] ?? null);
            }
            $result = $query->first();
            Log::info("Find result", ['found' => $result ? $result->id : 'null']);
            return $result;
        }

        $result = $query->where($uniqueKeys, $attributes[$uniqueKeys] ?? null)->first();
        Log::info("Find result (string key)", ['found' => $result ? $result->id : 'null']);
        return $result;
    }

    private function processBelongsToMany($modelInstance, $row)
    {
        foreach ($this->instance->getBelongsToManyDetailsList() as $method => $details) {
            $relationModel = $details['model'];
            $relatedIds = [];

            if (!isset($details['headings'])) continue;
            foreach ($details['headings'] as $heading) {
                if(empty($row[$heading])) continue;

                $relatedValues = array_map('trim', explode(',', $row[$heading]));

                foreach ($relatedValues as $value) {
                    // Trim trailing/leading spaces from Excel data
                    $value = trim($value);
                    if (empty($value)) continue;

                    if (isset($this->instance->getRelatedModelsCache()[$relationModel][$value])) {
                        $relatedIds[] = $this->instance->getRelatedModelsCache()[$relationModel][$value];
                    } else {
                        $relationModelInstance = new $relationModel();
                        $foundId = $relationModel::where(
                            $relationModel::getUniqueKeyForImportExport(),
                            $value
                        )->value($relationModelInstance->getKeyName());

                        if ($foundId) {
                            $relatedIds[] = $foundId;
                            $this->instance->updateRelatedModelsCache($relationModel, $value, $foundId);
                        } elseif (method_exists($relationModel, 'getAutoCreateAttributesOnImport')) {
                            // Auto-create the missing related record for many-to-many
                            $autoAttributes = $relationModel::getAutoCreateAttributesOnImport($value);
                            if (is_array($autoAttributes) && !empty($autoAttributes)) {
                                try {
                                    $newRelated = $relationModel::create($autoAttributes);
                                    $relatedIds[] = $newRelated->getKey();
                                    $this->instance->updateRelatedModelsCache($relationModel, $value, $newRelated->getKey());
                                    Log::info("Auto-created BelongsToMany related record", [
                                        'model' => $relationModel,
                                        'value' => $value,
                                        'id' => $newRelated->getKey()
                                    ]);
                                } catch (\Exception $e) {
                                    Log::warning("BelongsToMany auto-create failed", ['model' => $relationModel, 'value' => $value, 'error' => $e->getMessage()]);
                                }
                            }
                        }
                    }
                }
            }
            if (!empty($relatedIds)) {
                $modelInstance->{$method}()->{$this->associationMethod}($relatedIds);
            }
        }
    }

    private function recordResult(int $rowIndex, array $rowData, string $status, string $message): void
    {
        $rowData['row'] = $rowIndex;
        $rowData['status'] = $status;
        $rowData['message'] = $message;

        $this->results[] = $rowData;
    }

    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function ($event) {
                Log::info("Starting Import Batch [{$this->batchId}] for model [{$this->model}].");
                $totalRows = $event->getReader()->getTotalRows();
                $totalRowsCount = array_sum($totalRows) - count($totalRows);
                Log::info("Total rows detected", ['count' => $totalRowsCount]);
                
                $this->instance->setTotalRows(max(0, $totalRowsCount));
                $this->instance->persist();

                if (method_exists($this->model, 'beforeImport')) {
                    $this->model::beforeImport([
                        'batch_id' => $this->batchId,
                        'file_path' => $this->filePath,
                        'update' => $this->update,
                    ]);
                }
            },
            AfterChunk::class => function ($event) {
                $chunkIndex = $this->getChunkOffset() / $this->chunkSize();
                $disk = config('omniporter.import.disk');
                $filePath = self::getChunkFilePath($this->batchId, $chunkIndex);
                
                $content = '';
                foreach ($this->results as $result) {
                    $content .= json_encode($result) . "\n";
                }
                Storage::disk($disk)->put($filePath, $content);
                
                $this->results = []; // Clear for next chunk
            },
            AfterImport::class => function ($event) {
                $this->instance->delete();
                $disk = config('omniporter.import.disk');
                Storage::disk($disk)->delete($this->filePath);
                $batchId = $this->batchId;
                $merged = [];

                $allFiles = Storage::disk($disk)->files("imports/results/{$batchId}");
                $chunkFiles = array_filter($allFiles, fn($f) => Str::contains($f, 'chunk-') && Str::endsWith($f, '.jsonl'));

                $failedRows = 0;
                foreach ($chunkFiles as $file) {
                    $content = Storage::disk($disk)->get($file);
                    $lines = explode("\n", trim($content));
                    foreach ($lines as $line) {
                        if (empty($line)) continue;
                        $row = json_decode($line, true);
                        if (($row['status'] ?? null) === 'error') {
                            $failedRows++;
                        }
                        $merged[] = $row;
                    }
                    Storage::disk($disk)->delete($file);
                }

                $finalExcelPath = $this->getFinalExcelPath($batchId);
                $export = new ResultExport($merged);
                Excel::store($export, $finalExcelPath, $disk);

                if ($this->notifiableEmail) {
                    DispatchCompleteImportNotificationJob::dispatch($this->notifiableEmail, $finalExcelPath, $failedRows, $disk);
                }

                Log::info("Completed Import Batch [{$batchId}]. Failed rows: {$failedRows}. Result stored at: {$finalExcelPath}");
            },
        ];
    }
    public static function getChunkFilePath(string $batchId, int $chunkIndex): string
    {
        return "imports/results/{$batchId}/chunk-{$chunkIndex}.jsonl";
    }

    private function getFinalExcelPath(string $batchId): string
    {
        return "imports/results/{$batchId}/final_result_{$batchId}.xlsx";
    }
}
