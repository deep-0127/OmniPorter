<?php

namespace OmniPorter\Import\Http\Controllers;

use Illuminate\Routing\Controller;
use OmniPorter\Helpers\ApiResponse;
use Illuminate\Support\Facades\Cache;
use OmniPorter\Import\Helpers\ImportDetailsCache;

class ProgressController extends Controller
{
    public function show(string $batchId)
    {
        $cacheKey = ImportDetailsCache::getCacheKey($batchId);
        $data = Cache::store(config('omniporter.cache.store'))->get($cacheKey);

        if (!$data) {
            return ApiResponse::notFound("Import batch [{$batchId}] not found.");
        }

        $decoded = json_decode($data, true);
        $processedRows = (int) Cache::store(config('omniporter.cache.store'))->get(
            ImportDetailsCache::getProcessedRowsCacheKey($batchId),
            0
        );

        return ApiResponse::success([
            'batch_id' => $batchId,
            'total_rows' => $decoded['total_rows'] ?? 0,
            'processed_rows' => $processedRows,
            'progress' => $this->calculateProgress($decoded, $processedRows),
            'status' => $this->determineStatus($decoded, $processedRows),
        ]);
    }

    private function calculateProgress(array $data, int $processed): float
    {
        $total = $data['total_rows'] ?? 0;

        if ($total === 0) {
            return 0.0;
        }

        return round(($processed / $total) * 100, 2);
    }

    private function determineStatus(array $data, int $processed): string
    {
        $total = $data['total_rows'] ?? 0;

        if ($total === 0) {
            return 'pending';
        }

        if ($processed >= $total) {
            return 'completed';
        }

        return 'in_progress';
    }
}
