<?php

declare(strict_types=1);

namespace OmniPorter;

use OmniPorter\Export\Http\Controllers\ExportController;
use OmniPorter\Import\Http\Controllers\ImportController;
use Illuminate\Support\ServiceProvider;

/**
 * OmniPorterServiceProvider
 *
 * Registers OmniPorter's configuration, routes, and auto-discovery of
 * importable / exportable models into the host Laravel application.
 *
 * Auto-discovery: When a consuming application registers this provider
 * (or it is discovered via `extra.laravel.providers` in composer.json),
 * it will scan `app_path('**\/Domain\/**\/Models\/*.php')` for models that
 * use the `HasImport` or `HasExport` traits and wire them into the controller
 * class-maps automatically.
 *
 * Publishing:
 *   php artisan vendor:publish --tag=omniporter-config
 *   php artisan vendor:publish --tag=omniporter-views
 *   php artisan vendor:publish --tag=omniporter-migrations
 */
class OmniPorterServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/omniporter.php',
            'omniporter',
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->publishAssets();
        $this->loadRoutesFrom(__DIR__ . '/../routes/import-export.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'omniporter');
        $this->bootModelDiscovery();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \OmniPorter\Import\Console\Commands\ScanImportableModelsCommand::class,
                \OmniPorter\Import\Console\Commands\ImportCommand::class,
                \OmniPorter\Import\Console\Commands\ImportBatchCleanupCommand::class,
                \OmniPorter\Export\Console\Commands\ExportCommand::class,
                \OmniPorter\Console\Commands\ClearCacheCommand::class,
                \OmniPorter\Console\Commands\ScaffoldCommand::class,
            ]);

        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Register publishable assets so the host app can vendor:publish them.
     */
    private function publishAssets(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // Config
        $this->publishes([
            __DIR__ . '/../config/omniporter.php' => config_path('omniporter.php'),
        ], 'omniporter-config');

        // Mail views
        $this->publishes([
            __DIR__ . '/../resources/views/emails' => resource_path('views/vendor/omniporter/emails'),
        ], 'omniporter-views');

        // Stubs
        $this->publishes([
            __DIR__ . '/../resources/stubs' => base_path('resources/stubs/vendor/omniporter'),
        ], 'omniporter-stubs');
    }

    /**
     * Scan the host application for Importable / Exportable models and register
     * them in the respective controller class-maps.
     *
     * Uses a bootstrap cache file in `bootstrap/cache/omniporter_models.php` to
     * avoid repeated filesystem scans in production.
     */
    private function bootModelDiscovery(): void
    {
        $cachePath = base_path('bootstrap/cache/omniporter_models.php');

        if (! $this->app->environment('local') && file_exists($cachePath)) {
            $map = require $cachePath;
            $this->hydrateClassMaps($map['imports'] ?? [], $map['exports'] ?? []);
            return;
        }

        [$importMap, $exportMap] = $this->scanModels();
        $this->hydrateClassMaps($importMap, $exportMap);
        $this->writeCache($cachePath, $importMap, $exportMap);
    }

    /**
     * Scan app_path for models that implement the HasImport / HasExport traits.
     *
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    private function scanModels(): array
    {
        $patterns = (array) config('omniporter.discovery.model_paths', []);
        $modelPaths = [];

        foreach ($patterns as $pattern) {
            $found = glob(base_path($pattern)) ?: [];
            $modelPaths = array_merge($modelPaths, $found);
        }

        $importMap  = [];
        $exportMap  = [];

        foreach ($modelPaths as $modelPath) {
            $class = $this->pathToClass($modelPath);

            if (! class_exists($class)) {
                continue;
            }

            $traits      = class_uses_recursive($class);
            $resourceKey = strtolower(class_basename($class));

            if (in_array(\OmniPorter\Traits\HasImport::class, $traits, true)) {
                $importMap[$resourceKey] = $class;
            }

            if (in_array(\OmniPorter\Traits\HasExport::class, $traits, true)) {
                $exportMap[$resourceKey] = $class;
            }
        }

        return [$importMap, $exportMap];
    }

    /**
     * Push discovered class maps into the controllers' static registries.
     *
     * @param  array<string,string>  $importMap
     * @param  array<string,string>  $exportMap
     */
    private function hydrateClassMaps(array $importMap, array $exportMap): void
    {
        foreach ($importMap as $resource => $class) {
            ImportController::addToClassMap($resource, $class);
        }

        foreach ($exportMap as $resource => $class) {
            ExportController::addToClassMap($resource, $class);
        }
    }

    /**
     * Persist the discovered class maps to a bootstrap cache file.
     *
     * @param  array<string,string>  $importMap
     * @param  array<string,string>  $exportMap
     */
    private function writeCache(string $cachePath, array $importMap, array $exportMap): void
    {
        $payload = var_export(['imports' => $importMap, 'exports' => $exportMap], true);
        file_put_contents($cachePath, "<?php return {$payload};");
    }

    /**
     * Convert an absolute file path inside app_path() to a PSR-4 class name.
     */
    private function pathToClass(string $filePath): string
    {
        // Normalize slashes in both base_path and filePath for Windows
        $basePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, base_path());
        $filePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);

        $relative = str_replace($basePath . DIRECTORY_SEPARATOR, '', $filePath);
        
        $class = str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);

        if (str_starts_with($class, 'app\\')) {
            $class = \Illuminate\Support\Str::replaceFirst('app\\', 'App\\', $class);
        }

        return $class;
    }
}
