<?php

namespace App\Features\Import\Helpers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ImportDetailsCache
{
    private $fillableFields;
    private $relationDetailsList = [];
    private $validationFileInstance;
    private $belongsToOneDetailsList = [];
    private $belongsToManyDetailsList = [];
    private $relatedModelsCache = [];
    private $existingModelsCache = [];
    private $fieldHeadingMap = [];
    private $headings = [];
    private $headingsCopy = [];
    private $belongsToOneDetailsListCopy = [];

    public static function getInstance(
        string $batchId,
        $model,
        bool $update,
    ): self {
        $cached = Redis::get(self::getCacheKey($batchId));
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

    private function initialize() {
        $this->fillableFields = $this->model->getFillable();
        $this->relationDetailsList = $this->model->getListOfRelationDetails();

        $validators = $this->model::getImportValidators();

        $file = $this->update
            ? ($validators['update'] ?? null)
            : ($validators['create'] ?? null);

        if($file && class_exists($file)) {
            $this->validationFileInstance = new $file;
        } else {
            $mode = $this->update ? 'Update' : 'Create';
            Log::error("Validator class for [$mode] not found in model configuration.");
            throw new \Exception("Something went wrong with the import, please contact admin!", 500);
        }

        foreach ($this->relationDetailsList as $method => $relationDetails) {
            unset($this->relationDetailsList[$method]);
            $method = strtolower($method);
            $relationModel = $relationDetails['model'];
            $array = explode('\\', $relationModel);
            $relationDetails['pattern'] = strtolower(end($array));

            if(!isset($this->relatedModelsCache[$relationModel])) {
                $this->relatedModelsCache[$relationModel] = $relationModel::pluck(
                    'id',
                    $relationModel::getUniqueKeyForImportExport()
                );
            }
            $this->relationDetailsList[$method] = $relationDetails;

            if($relationDetails['type'] == 'belongsToMany')
                $this->belongsToManyDetailsList[$method] = $relationDetails;
            else
                $this->belongsToOneDetailsList[$method] = $relationDetails;
        }

        $this->belongsToOneDetailsListCopy = $this->belongsToOneDetailsList;

        if($this->update) {
            $this->existingModelsCache = $this->model::all();
        }
    }

    private function reinitialize()
    {
        foreach ($this->relationDetailsList as $relationModel => $relationDetails) {
            $this->relatedModelsCache[$relationModel] = $relationModel::pluck(
                'id',
                $relationModel::getUniqueKeyForImportExport()
            );
            $this->relationDetailsList[$relationModel] = $relationDetails;
        }

        if($this->update) {
            $this->existingModelsCache = $this->model::all();
        }

        $this->belongsToOneDetailsListCopy = $this->belongsToOneDetailsList;
        $this->headingsCopy = $this->headings;
    }

    public function initializeFieldHeadingMap($headings)
    {
        if(!empty($this->fieldHeadingMap)) {
            return;
        }
         $this->headingsCopy = $this->headings = $headings;
        foreach ($this->fillableFields as $fillableField) {
            $isRelationField = ($relationModel = $this->isRelationField($fillableField)) !== null;

            foreach ($this->headings as $headingIndex => $heading) {
                if (is_int($heading)) continue;

                if(($isRelationField && $this->isHeadingAssociatedWithRelationModel($heading, $relationModel)) || $this->isSimilar($fillableField, $heading)) {
                    unset($this->headings[$headingIndex]);
                    $this->fieldHeadingMap[$fillableField] = $heading;
                    break;
                }
            }
        }
        $this->mapBelongsToManyHeadings();
    }

    public function normalize(string $key): string {
        return Str::singular(strtolower(preg_replace('/[^a-z]/i', '', $key)));
    }

    public function isSimilar(string $str1, string $str2): bool
    {
        $str1 = $this->normalize($str1);
        $str2 = $this->normalize($str2);

        return (strlen($str1) >= strlen($str2)) ? str_contains($str1, $str2) : str_contains($str2, $str1);
    }

    public function isRelationField(string $field): ?string {
        foreach ($this->belongsToOneDetailsList as $relationModel => $details) {
            if (isset($details["field"]) && $field === $details["field"]) {
                unset($this->belongsToOneDetailsList[$relationModel]);
                return $relationModel;
            }
        }
        return null;
    }

    public function mapBelongsToManyHeadings() {
        foreach ($this->belongsToManyDetailsList as $relationModel => $details) {
            foreach ($this->headings as $index => $heading) {
                if($this->isHeadingAssociatedWithRelationModel($heading, $relationModel)) {
                    unset($this->headings[$index]);
                    $this->belongsToManyDetailsList[$relationModel]['headings'][] = $heading;
                }
            }
        }
    }

    public function isHeadingAssociatedWithRelationModel($heading, $relationModel): bool {
        return ((isset($this->relationDetailsList[$relationModel]['field']) && ($this->isSimilar($heading, $this->relationDetailsList[$relationModel]['field'])))||
            $this->isSimilar($heading, $this->relationDetailsList[$relationModel]['pattern']) ||
            $this->isSimilar($heading, $this->relationDetailsList[$relationModel]['method']));
    }

    public function getFillableFields(): array
    {
        return $this->fillableFields;
    }

    public function getRelationDetailsList(): array
    {
        return $this->relationDetailsList;
    }

    public function getValidationFileInstance()
    {
        return $this->validationFileInstance;
    }

    public function getBelongsToOneDetailsList(): array
    {
        return $this->belongsToOneDetailsList;
    }

    public function getBelongsToManyDetailsList(): array
    {
        return $this->belongsToManyDetailsList;
    }

    public function getRelatedModelsCache(): array
    {
        return $this->relatedModelsCache;
    }

    public function getExistingModelsCache(): array
    {
        return $this->existingModelsCache;
    }

    public function getFieldHeadingMap(): array
    {
        return $this->fieldHeadingMap;
    }

    public function updateRelatedModelsCache($relationModel, $relatedValue, $relatedId) {
        $this->relatedModelsCache[$relationModel][$relatedValue] = $relatedId;
    }

    public function toArray(): array
    {
        return [
            'batchId' => $this->batchId,
            'update' => $this->update,
            'fillableFields' => $this->fillableFields,
            'relationDetailsList' => $this->relationDetailsList,
            'belongsToOneDetailsList' => $this->belongsToOneDetailsListCopy,
            'belongsToManyDetailsList' => $this->belongsToManyDetailsList,
            'fieldHeadingMap' => $this->fieldHeadingMap,
            'headings' => $this->headingsCopy,
            'model' => $this->model::class,
            'validationFilePath' => ($this->validationFileInstance)::class,
        ];
    }

    public static function fromArray(array $data): self
    {
        $instance = new self($data['batchId'], new $data['model'], $data['update']);

        $instance->fillableFields = $data['fillableFields'];
        $instance->relationDetailsList = $data['relationDetailsList'];
        $instance->belongsToOneDetailsList = $data['belongsToOneDetailsList'];
        $instance->belongsToManyDetailsList = $data['belongsToManyDetailsList'];
        $instance->fieldHeadingMap = $data['fieldHeadingMap'];
        $instance->headings = $data['headings'];
        $instance->validationFileInstance = new $data['validationFilePath'];

        $instance->reinitialize();

        return $instance;
    }

    private static function getCacheKey($batchId)
    {
        return "import_cache_{$batchId}";
    }

    public function persist() {
        Redis::set(self::getCacheKey($this->batchId), json_encode($this->toArray()));
    }

    public function delete() {
        Redis::del(self::getCacheKey($this->batchId));
    }

}
