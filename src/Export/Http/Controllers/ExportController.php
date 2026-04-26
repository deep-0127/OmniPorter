<?php

namespace OmniPorter\Export\Http\Controllers;

use Illuminate\Routing\Controller;
use OmniPorter\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportController extends Controller
{
    private static $classMap = [];

    public static function addToClassMap(string $className, string $classPath): void
    {
        self::$classMap[$className] = $classPath;
    }

    public static function getClassMap(): array
    {
        return self::$classMap;
    }

    public function exportResource(Request $request, string $resource)
    {
        $inputResource = $resource;
        $resource = Str::singular($resource);

        if (!isset(self::$classMap[$resource])) {
            return ApiResponse::notFound("Export resource [$resource] not found. Ensure the model uses the HasExport trait and is registered.");
        }
        $exportClass = self::$classMap[$resource];

        $exportableColumns = $exportClass::getColumnsToExport();

        $columns = $request->input('columns') ? explode(',', $request->input('columns')) : $exportableColumns;

        if (!empty(array_diff($columns, $exportableColumns))) {
            return ApiResponse::error("Bad Request", ['columns' => "Columns do not exist or cannot be exported."], 400);
        }

        $exportType = $request->input('type');
        $exportType = in_array($exportType, ['xlsx', 'csv', 'pdf']) ? $exportType : 'xlsx';

        $filters = $request->except(['columns', 'type']);

        try {
            $exportClass::export($exportableColumns, $columns, $filters, auth()->user()?->email, $exportType);
            return ApiResponse::success("Export for [$inputResource] has been queued. You'll be notified once it completes.");
        } catch (\Exception $e) {
            Log::error("Export failed for [$inputResource]: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString()
            ]);

            $errorMessage = config('app.debug') ? $e->getMessage() : "An internal error occurred.";
            return ApiResponse::error("Export failed for [$inputResource]", ['error' => $errorMessage], 500);
        }
    }

    public function download(Request $request, string $path)
    {
        if (!$request->hasValidSignature()) {
            abort(403, 'Invalid or expired download link.');
        }

        $disk = config('omniporter.export.disk', 'local');
        
        if (!Storage::disk($disk)->exists($path)) {
            abort(404, 'The requested file does not exist.');
        }

        return Storage::disk($disk)->download($path);
    }
}
