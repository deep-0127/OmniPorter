<?php

namespace App\Features\Export\Exports;

use App\Features\Export\Helpers\ExportDetailsCache;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterChunk;

class GenericExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, ShouldQueue
{
    private ExportDetailsCache $instance;

    public function __construct(
        private $model,
        private $batchId,
        private $exportableColumns,
        private $columns,
        private $filters,
    ) {
        $this->instance = ExportDetailsCache::getInstance(
            $this->batchId,
            $this->model,
            $this->exportableColumns,
            $this->columns,
            $this->filters,
        );
    }

    public function query()
    {
        $query = $this->buildQuery();

        $relationsToLoad = [];
        foreach ($this->instance->getRelationColumns() as $column => $relatedModel) {
            $relationDetails = $this->model->getListOfRelationDetails()[$relatedModel] ?? null;
            if ($relationDetails && isset($relationDetails['method'])) {
                $relationsToLoad[] = $relationDetails['method'];
            }
        }

        return $query->with($relationsToLoad);
    }

    public function headings(): array
    {
        $headings = [];

        foreach ($this->instance->getColumns() as $column) {
            $headings[] = ucwords(str_replace('_', ' ', $column));
        }

        foreach (array_keys($this->instance->getRelationColumns()) as $column) {
            $headings[] = ucwords(str_replace('_', ' ', $column));
        }

        return $headings;
    }

    public function map($row): array
    {
        $casts = $row->getCasts();
        $map = [];

        foreach ($this->instance->getColumns() as $column) {
            $value = $row->{$column};
            $castType = $casts[$column] ?? null;

            switch (true) {
                case $castType === 'boolean':
                    $map[$column] = $value ? 'true' : 'false';
                    break;

                case $castType === 'integer':
                    $map[$column] = (int) $value;
                    break;

                case $castType === 'float':
                    $map[$column] = (float) $value;
                    break;

                case in_array($castType, ['array', 'json']):
                    $map[$column] = json_encode($value);
                    break;

                case $castType === 'date' && $value instanceof Carbon:
                    $map[$column] = $value->toDateString();
                    break;

                case $castType === 'datetime' && $value instanceof Carbon:
                    $map[$column] = $value->toDateTimeString();
                    break;

                case class_exists($castType) && enum_exists($castType):
                    $map[$column] = $value instanceof \BackedEnum ? $value->value : (string) $value;
                    break;

                case $value instanceof Collection:
                    $map[$column] = $value->toJson();
                    break;

                default:
                    $map[$column] = $value;
                    break;
            }
        }

        foreach ($this->instance->getRelationColumns() as $column => $relatedModel) {
            $relationDetails = $this->model->getListOfRelationDetails()[$relatedModel] ?? null;

            if (!$relationDetails) continue;

            $relationMethod = $relationDetails['method'];
            $relationType = $relationDetails['type'];
            $uniqueKey = $relatedModel::getUniqueKeyForImportExport();

            if ($relationType === 'belongsTo') {
                $map[$column] = $row->{$relationMethod}?->{$uniqueKey} ?? null;
            }

            if ($relationType === 'belongsToMany') {
                $relatedItems = $row->{$relationMethod};
                $map[$column] = $relatedItems->pluck($uniqueKey)->implode(', ');
            }
        }

        return $map;
    }

    private array $operators = [
        '_eq' => '=',
        '_ne' => '!=',
        '_gt' => '>',
        '_gte' => '>=',
        '_lt' => '<',
        '_lte' => '<=',
        '_like' => 'LIKE',
        '_nlike' => 'NOT LIKE',
        '_in' => 'IN',
        '_nin' => 'NOT IN',
        '_null' => 'IS NULL',
        '_nnull' => 'IS NOT NULL',
    ];

    public function buildQuery()
    {
        $query = $this->model->newQuery();

        foreach ($this->instance->getFilters() as $filterKey => $filterValue) {
            if (empty($filterValue) && !Str::endsWith($filterKey, 'null')) {
                continue;
            }

            $column = $filterKey;
            $operator = '=';

            foreach ($this->operators as $suffix => $op) {
                if (Str::endsWith($filterKey, $suffix)) {
                    $column = Str::beforeLast($filterKey, $suffix);
                    $operator = $op;
                    break;
                }
            }

            if (!in_array($column, $this->exportableColumns)) continue;

            if (in_array($operator, ['LIKE', 'NOT LIKE'])) {
                $query->where($column, $operator, "%{$filterValue}%");
            } elseif (in_array($operator, ['IN', 'NOT IN'])) {
                $values = explode(',', $filterValue);
                $query->whereIn($column, $values, 'and', $operator === 'NOT IN');
            } elseif ($operator === 'IS NULL') {
                $query->whereNull($column);
            } elseif ($operator === 'IS NOT NULL') {
                $query->whereNotNull($column);
            } else {
                $query->where($column, $operator, $filterValue);
            }

            Log::debug("Filter: Column '$column', Operator '$operator', Value '$filterValue'");
        }

        return $query;
    }
}
