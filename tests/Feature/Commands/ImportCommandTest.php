<?php

namespace Tests\Feature\Commands;

use Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mock Storage facade
        Storage::fake('local');

        $modelPath = base_path('app/Models/User.php');
        if (!File::exists(dirname($modelPath))) {
            File::makeDirectory(dirname($modelPath), 0755, true);
        }
        File::put($modelPath, '<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class User extends Model { use \OmniPorter\Traits\HasImport; protected $fillable = ["email", "name"]; }');

        // Add to class map
        \OmniPorter\Import\Http\Controllers\ImportController::addToClassMap('user', 'App\Models\User');
    }

    public function test_it_previews_data_before_import()
    {
        $filePath = base_path('tests/test_import.csv');
        File::put($filePath, "email,name\ntest@example.com,Test User");

        // Mock Excel
        Excel::shouldReceive('toArray')
            ->once()
            ->withAnyArgs()
            ->andReturn([
                [
                    ['email', 'name'],
                    ['test@example.com', 'Test User']
                ]
            ]);

        $this->artisan('omniporter:import', [
            'model' => 'user',
            'file' => $filePath,
            '--preview' => true,
        ])
             ->expectsOutputToContain('PRE-FLIGHT PREVIEW')
             ->expectsOutputToContain('Total Rows   : 1')
             ->expectsConfirmation('Do you want to proceed with the import?', 'no')
             ->expectsOutputToContain('Import cancelled by user.')
             ->assertExitCode(0);
             
        if (File::exists($filePath)) {
            File::delete($filePath);
        }
    }

    public function test_it_fails_if_model_is_not_importable()
    {
        $this->artisan('omniporter:import invalid_model path/to/file.csv')
             ->expectsOutput('Model [invalid_model] is not registered for import.')
             ->assertExitCode(1);
    }
}
