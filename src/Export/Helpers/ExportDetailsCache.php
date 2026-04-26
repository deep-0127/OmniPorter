<?php

namespace OmniPorter\Export\Helpers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ExportDetailsCache
{
    private array $relationDetailsList = [];
    private array $relationColumns = [];
    private int $totalRows = 0;
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

        $cached = Cache::store(config('omniporter.cache.store'))->get(self::getCacheKey($batchId));
        if ($cached) {
            $instance = self::fromArray(json_decode($cached, true));
            self::$batchInstanceMap[$batchId] = $instance;
            return $instance;
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
            'totalRows' => $this->totalRows,
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
        $instance->totalRows = $data['totalRows'] ?? 0;

        return $instance;
    }

    public static function getCacheKey(string $batchId): string
    {
        $prefix = config('omniporter.cache.prefix', 'omniporter');
        return "{$prefix}:export_cache_{$batchId}";
    }

    public function persist(): void
    {
        $ttl = config('omniporter.cache.ttl', 86400);
        Cache::store(config('omniporter.cache.store'))
            ->put(self::getCacheKey($this->batchId), json_encode($this->toArray()), $ttl);
    }

    public function delete(): void
    {
        Cache::store(config('omniporter.cache.store'))->forget(self::getCacheKey($this->batchId));
        Cache::store(config('omniporter.cache.store'))->forget($this->getProcessedRowsCacheKey());
    }

    public function getColumns(): array { return $this->columns; }
    public function getFilters(): array { return $this->filters; }
    public function getRelationColumns(): array { return $this->relationColumns; }
    public function getModel() { return $this->model; }

    public function setTotalRows(int $totalRows): void { $this->totalRows = $totalRows; }
    public function getTotalRows(): int { return $this->totalRows; }
    
    public function setProcessedRows(int $processedRows): void 
    { 
        Cache::store(config('omniporter.cache.store'))
            ->put($this->getProcessedRowsCacheKey(), $processedRows, config('omniporter.cache.ttl', 86400));
    }

    public function getProcessedRows(): int 
    { 
        return (int) Cache::store(config('omniporter.cache.store'))
            ->get($this->getProcessedRowsCacheKey(), 0); 
    }

    public function incrementProcessedRows(int $count = 1): int 
    { 
        return (int) Cache::store(config('omniporter.cache.store'))
            ->increment($this->getProcessedRowsCacheKey(), $count); 
    }

    private function getProcessedRowsCacheKey(): string
    {
        return self::getCacheKey($this->batchId) . ':processed';
    }

}
