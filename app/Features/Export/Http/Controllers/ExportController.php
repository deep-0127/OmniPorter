<?php

namespace App\Features\Export\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use function PHPUnit\Framework\isEmpty;

class ExportController extends Controller
{
    private static $classMap = [];

    public static function addToClassMap(string $className, string $classPath): void
    {
        self::$classMap[$className] = $classPath;
    }

    public function exportResource(Request $request, string $resource)
    {
        $inputResource = $resource;
        $resource = Str::singular($resource);

        if (!isset(self::$classMap[$resource])) {
            return ApiResponse::notFound("Export resource [$resource] not found. Ensure the model uses the HasExport trait and is registered.");
        }
        $exportClass = new self::$classMap[$resource];

        $exportableColumns = $exportClass::getColumnsToExport();

        $columns = $request->input('columns') ? explode(',', $request->input('columns')) : $exportableColumns;
        if(!isEmpty(array_diff($columns, $exportableColumns))) {
            return ApiResponse::error("Bad Request", ['columns' => "Columns do not exist or cannot be exported."], 400);
        }

        $exportType = $request->input('type');
        $exportType = in_array($exportType, ['xlsx', 'csv']) ? $exportType : 'xlsx';

        $filters = $request->except(['columns', 'type']);

        try {
            $exportClass::export($exportableColumns, $columns, $filters, auth()->user(), $exportType);
            return ApiResponse::success("Export for [$inputResource] has been queued. You’ll be notified once it completes.");
        } catch (\Exception $e) {
            Log::error("Export failed for [$inputResource]: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString()
            ]);

            return ApiResponse::error("Export failed for [$inputResource]", $e->getMessage(), 500);
        }
    }
}

