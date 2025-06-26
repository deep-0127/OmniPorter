<?php

namespace App\Features\Import\Http\Controllers;

use App\Features\Import\Domain\Import\Validators\ImportRequest;
use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImportController extends Controller
{
    private static $classMap = [];

    public static function addToClassMap(string $className, string $classPath) {
        ImportController::$classMap[$className] = $classPath;
    }

    public function importResource(ImportRequest $request, string $resource, string $mode)
    {
        if (!isset($mode) || !in_array($mode, ['create', 'update'])) {
            return ApiResponse::validationError([
                'mode' => ['Invalid import mode. Only "create" or "update" are allowed.']
            ]);
        }
        return $this->import($request, $resource, $mode === "update");
    }

    public function import(ImportRequest $request, string $resource, bool $update)
    {
        $inputResource = $resource;
        $resource = Str::singular($resource);
        if (!isset(self::$classMap[$resource]))
            return ApiResponse::notFound("Import resource [$resource] not found.");
        $importClass = self::$classMap[$resource];

        try {
            $storedFilePath = $request->file('file')->store('imports/uploads');

            if (!$storedFilePath) {
                return ApiResponse::error('Failed to store the uploaded file, please try again.', 500);
            }

            $importClass::import($storedFilePath, $update, auth()->user(), 'sync');

            return ApiResponse::success("Import for [$inputResource] has been queued. You’ll be notified once it completes.");
        } catch (\Exception $e) {
            Log::error("Import failed for [$inputResource]: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString()
            ]);

            return ApiResponse::error("Import failed for [$inputResource]", $e->getMessage(), 500);
        }
    }
}
