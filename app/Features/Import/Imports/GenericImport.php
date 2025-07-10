<?php

namespace App\Features\Import\Imports;

use App\Features\Import\Domain\Import\Exceptions\ImportException;
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
        private $employee = null,
        private $batchId,
        private $filePath,
    ) {
        $this->instance = ImportDetailsCache::getInstance($this->batchId, $this->model, $this->update);
    }

    public function onRow(Row $row)
    {
        try {
            $rowIndex = $row->getIndex();
            $rawRow = $row->toArray();
            $row = $rawRow;

            DB::beginTransaction();
            $attributes = [];

            if (empty($this->instance->getFieldHeadingMap())) {
                    $this->instance = ImportDetailsCache::getInstance($this->batchId, $this->model, $this->update);
                    if (empty($this->instance->getFieldHeadingMap())) {
                        $this->instance->initializeFieldHeadingMap(array_keys($row));
                }
            }

            $modelInstance = new $this->model();

            foreach ($this->instance->getFieldHeadingMap() as $field => $heading) {
                $value = $row[$heading];
                $row[$heading] = in_array(strtolower($value), ['true', 'false']) ? strtolower($value) === 'true' : $value;
                $modelInstance->{$field} = $row[$heading];
                $attributes[$field] = $modelInstance->{$field};
            }

            foreach ($this->instance->getRelationDetailsList() as $method => $details) {
                $relationModel = $details['model'];
                if ($details['type'] == 'belongsTo') {
                    $relationField = $details['field'];
                    $relatedValue = isset($this->instance->getFieldHeadingMap()[$relationField]) ? $row[$this->instance->getFieldHeadingMap()[$relationField]] : null;
                    if(!$relatedValue) continue;
                    $relatedId = null;
                    if (isset($this->instance->getRelatedModelsCache()[$relationModel][$relatedValue]))
                        $relatedId = $this->instance->getRelatedModelsCache()[$relationModel][$relatedValue];
                    else {
                        $relatedId = $relationModel::where(
                            $relationModel::getUniqueKeyForImportExport(),
                            $relatedValue
                        )->value('id');
                        $this->instance->updateRelatedModelsCache($relationModel, $relatedValue, $relatedId);
                    }

                    $attributes[$relationField] = $relatedId ?? null;
                }
            }

            $modelInstance->applyImportContext(['importer_employee_id' => $this->employee->id, 'source' => 'excel', 'batch_id' => $this->batchId]);
            $attributes = array_merge($attributes, array_diff_key($modelInstance->getAttributes(), $attributes));

            if ($this->update) {
                $uniqueKeys = $this->model::getUniqueKeysForUpdate();
                if(is_array($uniqueKeys)) {
                    $conditions = [];
                    foreach ($uniqueKeys as $uniqueKey) {
                        $uniqueValue = $attributes[$uniqueKey] ?? null;
                        $conditions[$uniqueKey] = "$uniqueValue";
                    }

                    $cache = $this->instance->getExistingModelsCache();
                    foreach ($conditions as $field => $value) {
                        $cache = $cache->where($field, $value);
                    }
                    $modelInstance = $cache?->first();

                    $uniqueKeys = implode(",", array_keys($conditions));
                    $uniqueValue = implode(",", $conditions);
                } else {
                    $uniqueValue = $attributes[$uniqueKeys] ?? null;
                    $modelInstance = $this->instance->getExistingModelsCache()->where($uniqueKeys, $uniqueValue)?->first();
                }

                if (!$modelInstance)
                    throw new ImportException(null, $rowIndex, "Update failed: resource with {$uniqueKeys} = '{$uniqueValue}' does not exist");

                $modelInstance = $modelInstance->fill($attributes);
                $id = $modelInstance?->id ?? null;
                Log::info($modelInstance->id);
                $rules = $this->instance->getValidationFileInstance()->rules($id);
            } else {
                $rules = $this->instance->getValidationFileInstance()->rules();
                $modelInstance = new $this->model($attributes);
            }

            $validator = Validator::make($modelInstance->getAttributes(), $rules);
            if ($validator->fails()) {
                throw new ImportException($validator, $rowIndex);
            }

            $modelInstance->save();

            foreach ($this->instance->getBelongsToManyDetailsList() as $method => $details) {
                $relationModel = $details['model'];
                $relatedIds = [];
                foreach ($this->instance->getBelongsToManyDetailsList()[$relationModel]['headings'] as $heading) {
                    $relatedValues = array_map('trim', explode(',', $row[$heading]));
                    foreach ($relatedValues as $value) {
                        if (isset($this->instance->getRelatedModelsCache()[$relationModel][$value])) {
                            $relatedIds[] = $this->instance->getRelatedModelsCache()[$relationModel][$value];
                        } else {
                            if ($relatedId = $relationModel::where(
                                $relationModel::getUniqueKeyForImportExport(),
                                $value
                            )->first()?->id) {
                                $relatedIds[] = $relatedId;
                                $this->instance->updateRelatedModelsCache($relationModel, $value, $relatedId);
                            }
                        }
                    }
                }

                if (!empty($relatedIds)) $modelInstance->{$method}()->{$this->associationMethod}($relatedIds);
            }
            $this->recordResult($rowIndex, $rawRow, 'success', 'Imported successfully');
            DB::commit();
        } catch (UniqueConstraintViolationException|ImportException $e) {
            DB::rollBack();
            $this->recordResult($rowIndex, $rawRow, 'error', $e->getMessage());
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error while importing {$rowIndex}: {$e->getMessage()} {$e->getTraceAsString()}");
            $this->recordResult($rowIndex, $rawRow, 'error', "Failed to import");
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
        return 250;
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

        if ($this->employee?->work_email) {
            DispatchCompleteImportNotificationJob::dispatch($this->employee->work_email, Storage::path(self::getFinalExcelPath($this->batchId)), $failedRows);
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
