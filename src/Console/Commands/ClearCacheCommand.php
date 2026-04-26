<?php

namespace OmniPorter\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearCacheCommand extends Command
{
    protected $signature = 'omniporter:clear-cache';
    protected $description = 'Clear the OmniPorter cache';

    public function handle()
    {
        $prefix = config('omniporter.cache.prefix', 'omniporter');
        $store = config('omniporter.cache.store');

        $this->info("OmniPorter Cache Configuration:");
        $this->line("- Store: <comment>{$store}</comment>");
        $this->line("- Prefix: <comment>{$prefix}</comment>");

        $this->newLine();
        $this->info("Available Actions:");
        $this->line("1. <info>discovery</info> - Delete the model discovery cache file (bootstrap/cache/omniporter_models.php)");
        $this->line("2. <info>flush</info>     - Flush the entire cache store (DANGEROUS)");
        $this->line("3. <info>cleanup</info>   - Suggestion: Use 'php artisan omniporter:cleanup' for surgical batch cleanup");

        $choice = $this->choice('What would you like to clear?', ['discovery', 'flush', 'cancel'], 2);

        if ($choice === 'discovery') {
            $this->clearDiscoveryCache();
        } elseif ($choice === 'flush') {
            $this->flushCacheStore($store);
        } else {
            $this->info('Operation cancelled.');
        }
    }

    private function clearDiscoveryCache(): void
    {
        $cachePath = base_path('bootstrap/cache/omniporter_models.php');
        if (file_exists($cachePath)) {
            unlink($cachePath);
            $this->info('Model discovery cache file deleted successfully.');
            $this->comment('Run "php artisan omniporter:discover" to regenerate it.');
        } else {
            $this->line('No discovery cache file found.');
        }
    }

    private function flushCacheStore(string $store): void
    {
        $this->warn("\n[WARNING] Laravel's standard cache driver does not support clearing by prefix easily.");
        $this->warn("If your '{$store}' store is shared with your main application, flushing it will log out users and clear other app data!");

        if ($this->confirm('Are you sure you want to flush the entire cache store?', false)) {
            try {
                Cache::store($store)->flush();
                $this->info('Cache store flushed successfully.');
            } catch (\Exception $e) {
                $this->error('Failed to flush cache: ' . $e->getMessage());
            }
        }
    }
}
