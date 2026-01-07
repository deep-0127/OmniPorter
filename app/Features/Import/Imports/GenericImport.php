<?php

namespace App\Features\Import\Imports;

use App\Features\Import\Domain\Import\Exceptions\ImportException;
use App\Features\Import\Helpers\ImportAttributeCaster;
use App\Features\Import\Helpers\ImportDetailsCache;
use App\Features\Import\Jobs\DispatchCompleteImportNotificationJob;
use App\Features\Import\Results\ResultExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\RegistersEventListeners;
use Maatwebsite\Excel\Concerns\RemembersChunkOffset;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Events\AfterChunk;
use Maatwebsite\Excel\Events\AfterImport;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Row;

class GenericImport implements OnEachRow, WithHeadingRow, WithChunkReading, ShouldQueue, WithEvents
{
    use RegistersEventListeners, RemembersChunkOffset;
    private ImportDetailsCache $instance;
    private array $results = [];

    public function __construct(
        private $model,
        private bool $update,
        private string $associationMethod,
        private ?int $employeeId,
        private string $batchId,
        private string $filePath,
    ) {
        $this->instance = ImportDetailsCache::getInstance($this->batchId, $this->model, $this->update);
    }

    public function onRow(Row $row)
    {
        $rowIndex = $row->getIndex();
        $rawRow = $row->toArray();
        $mappedData = [];
        $warnings = [];

        DB::beginTransaction();
        try {
            if (empty($this->instance->getFieldHeadingMap())) {
                $this->instance = ImportDetailsCache::getInstance($this->batchId, $this->model, $this->update);
                if (empty($this->instance->getFieldHeadingMap())) {
                    $this->instance->initializeFieldHeadingMap(array_keys($rawRow));
                }
            }

            $modelInstance = new $this->model();

            foreach ($this->instance->getFieldHeadingMap() as $field => $heading) {
                $value = $rawRow[$heading];

                if ($value === null || $value === '') {
                    $mappedData[$field] = null;
                    continue;
                }

                $mappedData[$field] = ImportAttributeCaster::castAttribute($modelInstance, $field ,$value);
            }

            foreach ($this->instance->getRelationDetailsList() as $method => $details) {
                if ($details['type'] !== 'belongsTo') continue;

                $relationModel = $details['model'];
                $relationField = $details['field'];

                $heading = $this->instance->getFieldHeadingMap()[$relationField] ?? null;
                $relatedValue = $heading ? ($rawRow[$heading] ?? null) : null;

                if (!$relatedValue) continue;

                if (isset($this->instance->getRelatedModelsCache()[$relationModel][$relatedValue])) {
                    $mappedData[$relationField] = $this->instance->getRelatedModelsCache()[$relationModel][$relatedValue];
                } else {
                    $relatedId = $relationModel::where(
                        $relationModel::getUniqueKeyForImportExport(),
                        $relatedValue
                    )->value('id');

                    if ($relatedId) {
                        $this->instance->updateRelatedModelsCache($relationModel, $relatedValue, $relatedId);
                        $mappedData[$relationField] = $relatedId;
                    } else {
                        $mappedData[$relationField] = null;
                        $warnings[] = "Relation '{$heading}' ({$relatedValue}) not found";
                    }
                }
            }

            $modelInstance->applyImportContext([
                'importer_employee_id' => $this->employeeId,
                'source' => 'excel',
                'batch_id' => $this->batchId,
                'is_update' => $this->update,
                'provided_attributes' => $mappedData
            ]);

            $finalAttributes = array_merge($modelInstance->getAttributes(), $mappedData);
            $validationFile = $this->instance->getValidationFileInstance();

            if ($this->update) {
                $uniqueKeys = $this->model::getUniqueKeysForUpdate();
                $modelInstance = $this->findExistingModel($uniqueKeys, $finalAttributes);

                if (!$modelInstance) {
                    throw new ImportException(null, $rowIndex, "Update failed: Record not found.");
                }

                $validatorRules = $validationFile->rules($modelInstance->id, true);
            } else {
                $validatorRules = $validationFile->rules(true);
            }

            $validator = Validator::make($finalAttributes, $validatorRules);

            if ($validator->fails()) {
                throw new ImportException($validator, $rowIndex);
            }

            $modelInstance->fill($finalAttributes);
            $modelInstance->save();

            $this->processBelongsToMany($modelInstance, $rawRow);

            if (!empty($warnings)) {
                $rowStatus = 'partial_success';
                $finalMessage = 'Imported with warnings: ' . implode(', ', $warnings);
            } else {
                $rowStatus = 'success';
                $finalMessage = 'Imported successfully';
            }

            $this->recordResult($rowIndex, $rawRow, $rowStatus, $finalMessage);
            DB::commit();
            $modelInstance->afterImportSave([
                'is_update' => $this->update,
                'row_index' => $rowIndex,
            ]);
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
    }

    private function findExistingModel($uniqueKeys, $attributes)
    {
        $query = $this->instance->getExistingModelsCache();

        if (is_array($uniqueKeys)) {
            foreach ($uniqueKeys as $key) {
                $query = $query->where($key, $attributes[$key] ?? null);
            }
            return $query->first();
        }

        return $query->where($uniqueKeys, $attributes[$uniqueKeys] ?? null)->first();
    }

    private function processBelongsToMany($modelInstance, $row)
    {
        foreach ($this->instance->getBelongsToManyDetailsList() as $method => $details) {
            $relationModel = $details['model'];
            $relatedIds = [];

            if (!isset($this->instance->getBelongsToManyDetailsList()[$relationModel]['headings'])) continue;

            foreach ($this->instance->getBelongsToManyDetailsList()[$relationModel]['headings'] as $heading) {
                if(empty($row[$heading])) continue;

                $relatedValues = array_map('trim', explode(',', $row[$heading]));

                foreach ($relatedValues as $value) {
                    if (isset($this->instance->getRelatedModelsCache()[$relationModel][$value])) {
                        $relatedIds[] = $this->instance->getRelatedModelsCache()[$relationModel][$value];
                    } else {
                        $foundId = $relationModel::where(
                            $relationModel::getUniqueKeyForImportExport(),
                            $value
                        )->value('id');

                        if ($foundId) {
                            $relatedIds[] = $foundId;
                            $this->instance->updateRelatedModelsCache($relationModel, $value, $foundId);
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

    public function chunkSize(): int
    {
        return 250 ;
    }

    public static function getChunkFilePath(string $batchId, int $chunkIndex): string
    {
        return "imports/results/{$batchId}/chunk-{$chunkIndex}.jsonl";
    }

    public static function getFinalExcelPath(string $batchId): string
    {
        return "imports/results/{$batchId}/final.xlsx";
    }

    public function afterImport(AfterImport $event) {
        $this->instance->delete();
        File::delete(Storage::path($this->filePath));
        $batchId = $this->batchId;
        $merged = [];

        $chunkFiles = glob(Storage::path("imports/results/{$batchId}/chunk-*.jsonl"));
        $failedRows = 0;
        foreach ($chunkFiles as $file) {
            foreach (File::lines($file)->toArray() as $line) {
                $row = json_decode($line, true);
                if (($row['status'] ?? null) === 'error') {
                    $failedRows++;
                }
                $merged[] = $row;
            }
            File::delete($file);
        }

        $finalExcelPath = $this->getFinalExcelPath($batchId);
        $export = new ResultExport($merged);
        Excel::store($export, $finalExcelPath);

        if ($this->employeeId) {
            DispatchCompleteImportNotificationJob::dispatch($this->employeeId, Storage::path(self::getFinalExcelPath($this->batchId)), $failedRows);
        }
    }

    public function afterChunk(AfterChunk $event) {
        $this->instance->persist();
        $chunkIndex = $this->getChunkOffset() / $this->chunkSize();
        $chunkFile = self::getChunkFilePath($this->batchId, $chunkIndex);

        foreach ($this->results as $resultRow) {
            Storage::append($chunkFile, json_encode($resultRow));
        }
    }
}
