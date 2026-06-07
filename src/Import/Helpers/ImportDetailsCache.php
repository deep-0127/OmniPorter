<?php

namespace OmniPorter\Import\Helpers;

use OmniPorter\Exceptions\ImportException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ImportDetailsCache
{
    private $fillableFields;
    private $relationDetailsList = [];
    private $validationFileInstance;
    private $belongsToOneDetailsList = [];
    private $belongsToManyDetailsList = [];
    private $relatedModelsCache = [];
    // private mixed $existingModelsCache = [];
    private $fieldHeadingMap = [];
    private $headings = [];
    private $headingsCopy = [];
    private $belongsToOneDetailsListCopy = [];
    private int $totalRows = 0;

    public static function getInstance(string $batchId, $model, bool $update): self
    {
        $cached = Cache::store(config('omniporter.cache.store'))->get(self::getCacheKey($batchId));
        if ($cached) {
            return self::fromArray(json_decode($cached, true));
        }
        $instance = new self($batchId, $model, $update);
        $instance->initialize();
        return $instance;
    }

    private function __construct(
        private $batchId,
        private $model,
        private bool $update,
    ) {}

    public function initialize()
    {
        $this->fillableFields = (new $this->model)->getFillable();
        $this->relationDetailsList = $this->model::getListOfRelationDetails();

        $validators = $this->model::getImportValidators();
        $file = $this->update ? ($validators['update'] ?? null) : ($validators['create'] ?? null);

        if ($file && class_exists($file)) {
            $this->validationFileInstance = new $file;
        } else {
            $mode = $this->update ? 'Update' : 'Create';
            $message = "Validator class for [$mode] mode not found in model configuration. Check getImportValidators().";
            Log::error($message);
            throw new ImportException(null, 0, $message);
        }

        foreach ($this->relationDetailsList as $method => $relationDetails) {
            $relationModel = $relationDetails['model'];
            $relationDetails['method'] = $method;
            $array = explode('\\', $relationModel);
            $relationDetails['pattern'] = strtolower(end($array));

            if (!isset($this->relatedModelsCache[$relationModel])) {
                $plucked = $relationModel::pluck(
                    'id',
                    $relationModel::getUniqueKeyForImportExport()
                );
                // Normalize keys by trimming to handle whitespace mismatches
                $normalized = [];
                foreach ($plucked as $key => $value) {
                    $normalized[is_string($key) ? trim($key) : $key] = $value;
                }
                $this->relatedModelsCache[$relationModel] = $normalized;
            }
            $this->relationDetailsList[$method] = $relationDetails;

            if ($relationDetails['type'] == 'belongsToMany') {
                $this->belongsToManyDetailsList[$method] = $relationDetails;
            } elseif ($relationDetails['type'] == 'belongsTo') {
                $this->belongsToOneDetailsList[$method] = $relationDetails;
            }
        }

        $this->belongsToOneDetailsListCopy = $this->belongsToOneDetailsList;

        // if ($this->update) {
        //     $this->existingModelsCache = $this->model::query();
        // }
    }

    private function reinitialize()
    {
        foreach ($this->relationDetailsList as $method => $relationDetails) {
            $relationModel = $relationDetails['model'];
            if (!isset($this->relatedModelsCache[$relationModel])) {
                $plucked = $relationModel::pluck(
                    'id',
                    $relationModel::getUniqueKeyForImportExport()
                );
                // Normalize keys by trimming to handle whitespace mismatches
                $normalized = [];
                foreach ($plucked as $key => $value) {
                    $normalized[is_string($key) ? trim($key) : $key] = $value;
                }
                $this->relatedModelsCache[$relationModel] = $normalized;
            }
        }

        // if ($this->update) {
        //     $this->existingModelsCache = $this->model::query();
        // }

        $this->belongsToOneDetailsListCopy = $this->belongsToOneDetailsList;
        $this->headingsCopy = $this->headings;
    }

    public function initializeFieldHeadingMap($headings)
    {
        if (!empty($this->fieldHeadingMap)) {
            return;
        }
        $this->headingsCopy = $this->headings = $headings;

        // Passing logic...
        foreach ($this->fillableFields as $fillableField) {
            $relationKey = $this->isRelationField($fillableField);
            if ($relationKey === null) continue;

            foreach ($this->headings as $headingIndex => $heading) {
                if (is_int($heading)) continue;
                if ($this->isHeadingAssociatedWithRelationModel($heading, $relationKey, false)) {
                    unset($this->headings[$headingIndex]);
                    $this->fieldHeadingMap[$fillableField] = $heading;
                    break;
                }
            }
        }

        foreach ($this->fillableFields as $fillableField) {
            if (isset($this->fieldHeadingMap[$fillableField])) continue;
            foreach ($this->headings as $headingIndex => $heading) {
                if (is_int($heading)) continue;
                if ($this->isExact($fillableField, $heading)) {
                    unset($this->headings[$headingIndex]);
                    $this->fieldHeadingMap[$fillableField] = $heading;
                    break;
                }
            }
        }

        foreach ($this->fillableFields as $fillableField) {
            if (isset($this->fieldHeadingMap[$fillableField])) continue;
            $relationKey = $this->isRelationField($fillableField);
            if ($relationKey === null) continue;
            foreach ($this->headings as $headingIndex => $heading) {
                if (is_int($heading)) continue;
                if ($this->isHeadingAssociatedWithRelationModel($heading, $relationKey, true)) {
                    unset($this->headings[$headingIndex]);
                    $this->fieldHeadingMap[$fillableField] = $heading;
                    break;
                }
            }
        }

        foreach ($this->fillableFields as $fillableField) {
            if (isset($this->fieldHeadingMap[$fillableField])) continue;
            foreach ($this->headings as $headingIndex => $heading) {
                if (is_int($heading)) continue;
                if ($this->isSimilar($fillableField, $heading)) {
                    unset($this->headings[$headingIndex]);
                    $this->fieldHeadingMap[$fillableField] = $heading;
                    break;
                }
            }
        }

        $this->mapBelongsToManyHeadings();
    }

    public function normalize(string $key): string
    {
        return Str::singular(strtolower(preg_replace('/[^a-z0-9]/i', '', $key)));
    }

    public function isExact(string $str1, string $str2): bool
    {
        return $this->normalize($str1) === $this->normalize($str2);
    }

    public function isSimilar(string $str1, string $str2): bool
    {
        $str1 = $this->normalize($str1);
        $str2 = $this->normalize($str2);
        return (strlen($str1) >= strlen($str2)) ? str_contains($str1, $str2) : str_contains($str2, $str1);
    }

    public function isRelationField(string $field): ?string
    {
        foreach ($this->belongsToOneDetailsList as $relationKey => $details) {
            if (isset($details["field"]) && $field === $details["field"]) {
                return $relationKey;
            }
        }
        return null;
    }

    public function mapBelongsToManyHeadings()
    {
        foreach ($this->belongsToManyDetailsList as $relationKey => $details) {
            foreach ($this->headings as $index => $heading) {
                if (!is_string($heading)) continue;
                if ($this->isHeadingAssociatedWithRelationModel($heading, $relationKey, false)) {
                    unset($this->headings[$index]);
                    $this->belongsToManyDetailsList[$relationKey]['headings'][] = $heading;
                }
            }
        }
        foreach ($this->belongsToManyDetailsList as $relationKey => $details) {
            foreach ($this->headings as $index => $heading) {
                if (!is_string($heading)) continue;
                if ($this->isHeadingAssociatedWithRelationModel($heading, $relationKey, true)) {
                    unset($this->headings[$index]);
                    $this->belongsToManyDetailsList[$relationKey]['headings'][] = $heading;
                }
            }
        }
    }

    public function isHeadingAssociatedWithRelationModel($heading, $relationKey, bool $fuzzy = true): bool
    {
        $details = $this->relationDetailsList[$relationKey] ?? null;
        if (!$details) return false;
        $check = $fuzzy ? 'isSimilar' : 'isExact';
        return (
            (isset($details['field']) && $this->$check($heading, $details['field'])) ||
            $this->$check($heading, $details['pattern'] ?? '') ||
            $this->$check($heading, $details['method'] ?? '')
        );
    }

    public function toArray(): array
    {
        return [
            'batchId' => $this->batchId,
            'model' => is_string($this->model) ? $this->model : $this->model::class,
            'update' => $this->update,
            'fillableFields' => $this->fillableFields,
            'relationDetailsList' => $this->relationDetailsList,
            'belongsToOneDetailsList' => $this->belongsToOneDetailsListCopy,
            'belongsToManyDetailsList' => $this->belongsToManyDetailsList,
            'fieldHeadingMap' => $this->fieldHeadingMap,
            'headings' => $this->headingsCopy,
            'validationFilePath' => $this->validationFileInstance ? $this->validationFileInstance::class : null,
            'totalRows' => $this->totalRows,
            'relatedModelsCache' => $this->relatedModelsCache,
        ];
    }

    public static function fromArray(array $data): self
    {
        $instance = new self($data['batchId'], $data['model'], $data['update']);
        $instance->fillableFields = $data['fillableFields'] ?? [];
        $instance->relationDetailsList = $data['relationDetailsList'] ?? [];
        $instance->belongsToOneDetailsList = $data['belongsToOneDetailsList'] ?? [];
        $instance->belongsToManyDetailsList = $data['belongsToManyDetailsList'] ?? [];
        $instance->fieldHeadingMap = $data['fieldHeadingMap'] ?? [];
        $instance->headings = $data['headings'] ?? [];
        $instance->totalRows = $data['totalRows'] ?? 0;
        $instance->relatedModelsCache = $data['relatedModelsCache'] ?? [];
        if (!empty($data['validationFilePath'])) {
            $instance->validationFileInstance = new $data['validationFilePath'];
        }
        $instance->reinitialize();
        return $instance;
    }

    public static function getCacheKey($batchId): string
    {
        $prefix = config('omniporter.cache.prefix', 'omniporter');
        return "{$prefix}_import_{$batchId}";
    }

    public static function getProcessedRowsCacheKey($batchId): string
    {
        return self::getCacheKey($batchId) . ":processed";
    }

    public function persist()
    {
        Cache::store(config('omniporter.cache.store'))->put(
            self::getCacheKey($this->batchId),
            json_encode($this->toArray()),
            config('omniporter.cache.ttl', 3600)
        );
    }

    public function delete()
    {
        Cache::store(config('omniporter.cache.store'))->forget(self::getCacheKey($this->batchId));
        Cache::store(config('omniporter.cache.store'))->forget(self::getProcessedRowsCacheKey($this->batchId));
    }

    public function setTotalRows(int $totalRows): void { $this->totalRows = $totalRows; }
    public function getTotalRows(): int { return $this->totalRows; }

    public function setProcessedRows(int $processedRows): void
    {
        Cache::store(config('omniporter.cache.store'))->put(
            self::getProcessedRowsCacheKey($this->batchId),
            $processedRows,
            config('omniporter.cache.ttl', 3600)
        );
    }

    public function getProcessedRows(): int
    {
        return (int) Cache::store(config('omniporter.cache.store'))->get(
            self::getProcessedRowsCacheKey($this->batchId),
            0
        );
    }

    public function incrementProcessedRows(int $count = 1): int
    {
        $processed = Cache::store(config('omniporter.cache.store'))->increment(
            self::getProcessedRowsCacheKey($this->batchId),
            $count
        );

        // Ensure TTL is refreshed or set if it's the first increment
        if ($processed === $count) {
            Cache::store(config('omniporter.cache.store'))->put(
                self::getProcessedRowsCacheKey($this->batchId),
                $processed,
                config('omniporter.cache.ttl', 3600)
            );
        }

        return $processed;
    }

    public function getFillableFields(): array { return $this->fillableFields; }
    public function getRelationDetailsList(): array { return $this->relationDetailsList; }
    public function getValidationFileInstance() { return $this->validationFileInstance; }
    public function getBelongsToOneDetailsList(): array { return $this->belongsToOneDetailsList; }
    public function getBelongsToManyDetailsList(): array { return $this->belongsToManyDetailsList; }
    public function getRelatedModelsCache(): array { return $this->relatedModelsCache; }
    public function getExistingModelsCache(): mixed { return $this->model::query(); }
    public function getFieldHeadingMap(): array { return $this->fieldHeadingMap; }
    public function updateRelatedModelsCache($relationModel, $relatedValue, $relatedId) {
        if (!is_scalar($relatedValue)) {
            return;
        }
        $relationModelStr = is_object($relationModel) ? get_class($relationModel) : $relationModel;
        $trimmedValue = is_string($relatedValue) ? trim($relatedValue) : $relatedValue;
        $this->relatedModelsCache[$relationModelStr][$trimmedValue] = $relatedId;
    }
}
