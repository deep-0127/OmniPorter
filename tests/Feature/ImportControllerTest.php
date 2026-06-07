<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use OmniPorter\Import\Http\Controllers\ImportController;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HTTP-layer tests for the ImportController.
 *
 * These tests fake all I/O (Storage, Excel, Queue) and do NOT touch the
 * database, so RefreshDatabase is intentionally omitted. Mixing RefreshDatabase
 * with GenericImportIntegrationTest (which also uses RefreshDatabase and manually
 * creates tables) causes a "There is already an active transaction" PDOException
 * on SQLite :memory: when both suites run in the same process.
 */
class ImportControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Register a fake resource so tests can target it
        ImportController::addToClassMap('stub', \Tests\Feature\Stubs\StubImportableModel::class);
        ImportController::addToClassMap('stubs', \Tests\Feature\Stubs\StubImportableModel::class);

        Storage::fake('local');
        Excel::fake();
        Queue::fake();

        // ImportDetailsCache uses Redis::get/set — mock to avoid needing the Redis extension
        Redis::shouldReceive('get')->andReturn(null)->byDefault();
        Redis::shouldReceive('set')->andReturn(true)->byDefault();
        Redis::shouldReceive('del')->andReturn(1)->byDefault();

        // Manually create schema for stubs since we are not using RefreshDatabase
        Schema::create('stub_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('stub_importable_stub_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stub_importable_id');
            $table->foreignId('stub_role_id');
            $table->timestamps();
        });

        Schema::create('stub_departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('stub_roles');
        Schema::dropIfExists('stub_importable_stub_role');
        Schema::dropIfExists('stub_departments');

        parent::tearDown();
    }

    // ── invalid mode ──────────────────────────────────────────────────────────

    #[Test]
    public function invalid_mode_returns_422(): void
    {
        $file = UploadedFile::fake()->create(
            'data.xlsx',
            10,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        // the 'mode' parameter is restricted by regex 'create|update'
        // so 'upsert' will cause a 404 because the route doesn't match
        $response = $this->postJson('/imports/stub/upsert', ['file' => $file]);

        $response->assertStatus(404);
    }

    // ── unknown resource ──────────────────────────────────────────────────────

    #[Test]
    public function unknown_resource_returns_404(): void
    {
        $file = UploadedFile::fake()->create(
            'data.xlsx',
            10,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        $response = $this->postJson('/imports/unknown_resource/create', ['file' => $file]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    // ── missing file returns 422 ──────────────────────────────────────────────

    #[Test]
    public function missing_file_returns_422(): void
    {
        $response = $this->postJson('/imports/stub/create', []);

        $response->assertStatus(422);
    }

    // ── valid create import ───────────────────────────────────────────────────

    #[Test]
    public function valid_create_import_returns_200_and_queues(): void
    {
        $file = UploadedFile::fake()->create(
            'data.xlsx',
            10,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        $response = $this->postJson('/imports/stubs/create', ['file' => $file]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        // The controller stores the file as imports/uploads/{random}.xlsx.
        // ExcelFake::assertQueued() keys by file path (not class name).
        // Use matchByRegex() since the exact stored filename is random.
        Excel::matchByRegex();
        Excel::assertQueued('~imports/uploads/~', config('omniporter.import.disk'));
        Excel::doNotMatchByRegex(); // restore for subsequent assertions
    }

    // ── valid update import ───────────────────────────────────────────────────

    #[Test]
    public function valid_update_import_returns_200(): void
    {
        $file = UploadedFile::fake()->create(
            'data.xlsx',
            10,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        $response = $this->postJson('/imports/stubs/update', ['file' => $file]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }
}
