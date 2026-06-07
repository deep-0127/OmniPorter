<?php

namespace Tests\Feature\Commands;

use Tests\TestCase;
use Illuminate\Support\Facades\File;

class UserStub extends \Illuminate\Database\Eloquent\Model {}

class ScaffoldCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure App\Models\User exists for the command to pass its resolve check
        if (!class_exists('App\Models\User')) {
            eval('namespace App\Models { class User extends \Illuminate\Database\Eloquent\Model {} }');
        }
    }

    public function test_it_scaffolds_files_for_a_model()
    {
        $path = 'app/OmniPorterTest';
        $fullPath = base_path($path);

        if (File::exists($fullPath)) {
            File::deleteDirectory($fullPath);
        }

        $this->artisan('omniporter:scaffold User --path=' . $path)
             ->assertExitCode(0)
             ->expectsOutputToContain('Scaffolding completed');

        $this->assertTrue(File::exists($fullPath . '/UserValidation.php'));
        $this->assertTrue(File::exists($fullPath . '/UserExportable.php'));

        File::deleteDirectory($fullPath);
    }

    public function test_it_does_not_overwrite_without_force()
    {
        $path = 'app/OmniPorterTest';
        $fullPath = base_path($path);
        
        if (!File::exists($fullPath)) {
            File::makeDirectory($fullPath, 0755, true);
        }
        
        File::put($fullPath . '/UserValidation.php', 'original content');

        $this->artisan('omniporter:scaffold User --path=' . $path)
             ->expectsOutputToContain('UserValidation already exists! Use --force to overwrite.');

        $this->assertEquals('original content', File::get($fullPath . '/UserValidation.php'));

        File::deleteDirectory($fullPath);
    }

    public function test_it_overwrites_with_force()
    {
        $path = 'app/OmniPorterTest';
        $fullPath = base_path($path);
        
        if (!File::exists($fullPath)) {
            File::makeDirectory($fullPath, 0755, true);
        }
        
        File::put($fullPath . '/UserValidation.php', 'original content');

        $this->artisan('omniporter:scaffold User --path=' . $path . ' --force')
             ->assertExitCode(0);

        $this->assertNotEquals('original content', File::get($fullPath . '/UserValidation.php'));

        File::deleteDirectory($fullPath);
    }

    public function test_it_fails_for_non_existent_model()
    {
        $this->artisan('omniporter:scaffold NonExistentModel')
             ->assertExitCode(1)
             ->expectsOutputToContain('Model class [NonExistentModel] not found');
    }
}
