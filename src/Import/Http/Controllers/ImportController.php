<?php

namespace OmniPorter\Import\Http\Controllers;

use OmniPorter\Import\Domain\Import\Validators\ImportRequest;
use Illuminate\Routing\Controller;
use OmniPorter\Helpers\ApiResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImportController extends Controller
{
    private static $classMap = [];

    public static function addToClassMap(string $className, string $classPath) {
        ImportController::$classMap[$className] = $classPath;
    }

    public static function getClassMap(): array {
        return ImportController::$classMap;
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
            $disk = config('omniporter.import.disk');
            $storedFilePath = $request->file('file')->store('imports/uploads', $disk);

            if (!$storedFilePath) {
                return ApiResponse::error('Failed to store the uploaded file, please try again.', 500);
            }

            // BUG FIX: was passing auth()->user() object — now passes the email string
            $importClass::import($storedFilePath, $update, auth()->user()?->email);

            return ApiResponse::success("Import for [$inputResource] has been queued. You'll be notified once it completes.");
        } catch (\Exception $e) {
            Log::error("Import failed for [$inputResource]: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString()
            ]);

            $errorMessage = config('app.debug') ? $e->getMessage() : "An internal error occurred.";
            return ApiResponse::error("Import failed for [$inputResource]", ['error' => $errorMessage], 500);
        }
    }
}
