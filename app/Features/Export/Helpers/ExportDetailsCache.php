<?php

namespace App\Features\Export\Helpers;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ExportDetailsCache
{
    private array $relationDetailsList = [];
    private array $relationColumns = [];
    private static array $batchInstanceMap = [];

    public static function getInstance(
        string $batchId,
               $model,
        array $exportableColumns,
        array $columns,
        array $filters,
    ): self {
        if (isset(self::$batchInstanceMap[$batchId])) {
            return self::$batchInstanceMap[$batchId];
        }

        return new self($batchId, $model, $exportableColumns, $columns, $filters);
    }

    private function __construct(
        private string $batchId,
        private $model,
        private array $exportableColumns,
        private array $columns,
        private array $filters
    ) {
        $this->relationDetailsList = $this->model->getListOfRelationDetails();
        $this->buildRelationColumns();
        self::$batchInstanceMap[$batchId] = $this;
    }

    public function buildRelationColumns(): void
    {
        foreach ($this->columns as $index => $column) {
            foreach ($this->relationDetailsList as $relationModel => $details) {
                if ($details['method'] === $column || (isset($details['field']) && $details['field'] === $column)) {
                    unset($this->columns[$index]);
                    $this->relationColumns[$details['method']] = $relationModel;
                    break;
                }
            }
        }
    }

    public function toArray(): array
    {
        return [
            'batchId' => $this->batchId,
            'model' => $this->model::class,
            'exportableColumns' => $this->exportableColumns,
            'columns' => $this->columns,
            'filters' => $this->filters,
            'relationDetailsList' => $this->relationDetailsList,
            'relationColumns' => $this->relationColumns,
        ];
    }

    public static function fromArray(array $data): self
    {
        $modelInstance = new $data['model'];

        $instance = new self(
            $data['batchId'],
            $modelInstance,
            $data['exportableColumns'],
            $data['columns'],
            $data['filters']
        );

        $instance->relationDetailsList = $data['relationDetailsList'];
        $instance->relationColumns = $data['relationColumns'] ?? [];

        return $instance;
    }

    public static function getCacheKey(string $batchId): string
    {
        return "export_cache_{$batchId}";
    }

    public function persist(): void
    {
        Redis::set(self::getCacheKey($this->batchId), json_encode($this->toArray()));
    }

    public function delete(): void
    {
        Redis::del(self::getCacheKey($this->batchId));
    }

    public function getColumns(): array { return $this->columns; }
    public function getFilters(): array { return $this->filters; }
    public function getRelationColumns(): array { return $this->relationColumns; }
    public function getModel() { return $this->model; }
}
