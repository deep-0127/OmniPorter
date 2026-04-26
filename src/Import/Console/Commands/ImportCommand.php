<?php

namespace OmniPorter\Import\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use OmniPorter\Traits\HasImport;
use OmniPorter\Import\Http\Controllers\ImportController;

class ImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'omniporter:import {model} {file} {--mode=create} {--email=} {--preview} {--chunk=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import data into a model from an Excel/CSV file';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $modelInput = $this->argument('model');
        $filePath = $this->argument('file');
        $mode = $this->option('mode');
        $email = $this->option('email');
        $chunk = $this->option('chunk');
        $resource = Str::singular(strtolower($modelInput));

        $classMap = ImportController::getClassMap();

        if (!isset($classMap[$resource])) {
            $this->error("Model [{$modelInput}] is not registered for import.");
            return 1;
        }

        if (!File::exists($filePath)) {
            $this->error("File [{$filePath}] does not exist.");
            return 1;
        }

        $modelClass = $classMap[$resource];

        // 3. Preview Logic
        $userConfirmed = true;
        if ($this->option('preview')) {
            try {
                $data = Excel::toArray(new \stdClass(), $filePath);
                $rows = $data[0] ?? [];

                if (empty($rows)) {
                    $this->warn("  The file appears to be empty.");
                    return 0;
                }

                $header = array_shift($rows);
                $rowCount = count($rows);
                $sample = array_slice($rows, 0, 5);

                $this->newLine();
                $this->line("  <bg=cyan;fg=black> PRE-FLIGHT PREVIEW </>");
                $this->line("  <fg=gray>Target Model :</> <fg=white;options=bold>{$modelClass}</>");
                $this->line("  <fg=gray>Total Rows   :</> <fg=white;options=bold>{$rowCount}</>");
                $this->newLine();

                $this->table(
                    array_map(fn($h) => "<fg=cyan>{$h}</>", $header),
                    $sample
                );

                $this->newLine();
                if (!$this->confirm('Do you want to proceed with the import?')) {
                    $this->info("Import cancelled by user.");
                    return 0;
                }
            } catch (\Exception $e) {
                $this->error("  Failed to read file: " . $e->getMessage());
                return 1;
            }
        }

        // 4. File Storage
        $disk = config('omniporter.import.disk', 'local');
        $fileName = 'imports/' . Str::random(40) . '.' . File::extension($filePath);
        
        if (!Storage::disk($disk)->put($fileName, File::get($filePath))) {
            $this->error("  Failed to store file on disk [{$disk}].");
            return 1;
        }

        $storedPath = Storage::disk($disk)->path($fileName);
        $update = ($mode === 'update');

        // 5. Execution
        try {
            $modelClass::import($fileName, $update, $email, 'sync', (int) $chunk ?: null);
            $this->info("  <fg=green;options=bold>SUCCESS</> Import batch dispatched successfully for [{$modelClass}].");
            return 0;
        } catch (\Exception $e) {
            $this->error("  Failed to dispatch import: " . $e->getMessage());
            return 1;
        }
    }
}
