<?php

namespace OmniPorter\Import\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use OmniPorter\Import\Helpers\ImportDetailsCache;

class ImportBatchCleanupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'omniporter:cleanup 
                            {batch_id? : The ID of the import batch to cleanup} 
                            {--all : Cleanup all batches (removes the entire imports/results directory)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup temporary files and cache for import batches';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $disk = config('omniporter.import.disk');
        $batchId = $this->argument('batch_id');
        $all = $this->option('all');

        if (!$batchId && !$all) {
            $this->error('Please provide a batch_id or use the --all flag.');
            return 1;
        }

        if ($all) {
            return $this->cleanupAll($disk);
        }

        return $this->cleanupBatch($batchId, $disk);
    }

    /**
     * Cleanup a specific batch.
     */
    private function cleanupBatch(string $batchId, string $disk): int
    {
        $this->info("Cleaning up batch: [{$batchId}]");

        // 1. Clear Cache
        $cacheKey = ImportDetailsCache::getCacheKey($batchId);
        $cacheStore = config('omniporter.cache.store');
        
        if (\Illuminate\Support\Facades\Cache::store($cacheStore)->forget($cacheKey)) {
            $this->line("- Cache cleared for key: <comment>{$cacheKey}</comment>");
        } else {
            $this->line("- No cache found for batch.");
        }

        // 2. Clear Results Directory
        $directory = "imports/results/{$batchId}";
        if (Storage::disk($disk)->exists($directory)) {
            Storage::disk($disk)->deleteDirectory($directory);
            $this->line("- Results directory deleted: <comment>{$directory}</comment>");
        } else {
            $this->line("- No results directory found.");
        }

        $this->info("Cleanup complete for batch {$batchId}.");
        return 0;
    }

    /**
     * Cleanup everything.
     */
    private function cleanupAll(string $disk): int
    {
        if (!$this->confirm('This will delete ALL import results and cached progress. Are you sure?', false)) {
            $this->info('Cleanup cancelled.');
            return 0;
        }

        $this->info("Cleaning up all OmniPorter import data...");

        // Note: Global cache clearing is handled by omniporter:clear-cache
        // but here we just delete the files.
        $directory = "imports/results";
        if (Storage::disk($disk)->exists($directory)) {
            Storage::disk($disk)->deleteDirectory($directory);
            $this->line("- Root results directory deleted: <comment>{$directory}</comment>");
        }

        $this->warn("Note: Active batch progress in cache was NOT cleared. Use 'php artisan omniporter:clear-cache' if needed.");
        
        $this->info('Global file cleanup complete.');
        return 0;
    }
}
