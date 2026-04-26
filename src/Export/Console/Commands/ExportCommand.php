<?php

namespace OmniPorter\Export\Console\Commands;

use Illuminate\Console\Command;
use OmniPorter\Traits\HasExport;

class ExportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'omniporter:export {model} {--columns=} {--filters=} {--type=xlsx} {--email=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export data from a model to Excel/CSV';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $modelInput = $this->argument('model');
        $columns = $this->option('columns') ? explode(',', $this->option('columns')) : null;
        $filters = $this->option('filters') ? explode(',', $this->option('filters')) : [];
        $type = $this->option('type');
        $email = $this->option('email');

        // 1. Look up model in classMap (singularize the resource name)
        $resource = \Illuminate\Support\Str::singular($modelInput);
        $classMap = \OmniPorter\Export\Http\Controllers\ExportController::getClassMap();

        if (!isset($classMap[$resource])) {
            $this->error("Model [{$modelInput}] is not registered for export.");
            return 1;
        }

        $modelClass = $classMap[$resource];

        // 2. Validate model uses HasExport trait
        $traits = class_uses_recursive($modelClass);
        if (!in_array(HasExport::class, $traits)) {
            $this->error("Model class [{$modelClass}] does not use the OmniPorter\\Traits\\HasExport trait.");
            return 1;
        }

        // Trigger export
        try {
            $exportableColumns = $modelClass::getColumnsToExport();
            $actualColumns = $columns ?? $exportableColumns;

            $modelClass::export($exportableColumns, $actualColumns, $filters, $email, $type);
            $this->info("Export batch dispatched successfully for [{$modelClass}].");
            $this->line("Type: {$type}");
            if ($email) {
                $this->line("Notification will be sent to: {$email}");
            }
        } catch (\Exception $e) {
            $this->error("Failed to dispatch export: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
