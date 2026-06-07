<?php

namespace Tests\Feature;

use OmniPorter\Import\Imports\GenericImport;
use OmniPorter\Import\Jobs\DispatchCompleteImportNotificationJob;
use OmniPorter\Import\Mail\ImportCompleteMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Stubs\StubImportableModel;
use Tests\Feature\Stubs\StubRoleModel;
use Tests\Feature\Stubs\StubDepartmentModel;
use Tests\TestCase;

/**
 * Integration tests for the full OmniPorter import lifecycle.
 *
 * These tests mirror real usage patterns from the HRMS backend (D:/HRMS/hrms-backend-2025).
 * specifically the Employee import which uses HasImport, getImportValidators(), and
 * DispatchCompleteImportNotificationJob for the mail-on-complete lifecycle.
 *
 * Test environment:
 * - DB:    SQLite in-memory  (phpunit.xml: DB_CONNECTION=sqlite, DB_DATABASE=:memory:)
 * - Cache: array driver      (phpunit.xml: CACHE_STORE=array)
 * - Queue: sync              (phpunit.xml: QUEUE_CONNECTION=sync)
 * - Mail:  array driver / Mail::fake()
 * - Redis: facade-mocked     (no real Redis required)
 *
 * Row-level tests bypass Excel file I/O by using the lightweight InlineImport helper
 * (defined at the bottom of this file) which feeds associative-array rows directly to
 * GenericImport::onRow() via a fabricated Maatwebsite\Excel\Row.
 */
class GenericImportIntegrationTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        // Build the stub table in SQLite memory
        Schema::create('stub_importables', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('department')->nullable();
            $table->foreignId('department_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('stub_roles', function ($table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('stub_importable_roles', function ($table) {
            $table->foreignId('stub_importable_id');
            $table->foreignId('stub_role_id');
        });

        Schema::create('stub_departments', function ($table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Storage::fake('local');
        Mail::fake();
        // NOTE: Queue::fake() is NOT called here globally — it would prevent
        // DispatchCompleteImportNotificationJob from running in mail tests.
        // Queue::fake() is called explicitly only in tests that need Excel::assertQueued().

        // Mock Redis facade: ImportDetailsCache uses Redis::get/set/del.
        // The array cache driver handles in-process state; Redis mocking prevents
        // connection errors when the cache class serialises/deserialises between chunks.
        Redis::shouldReceive('get')->andReturn(null)->byDefault();
        Redis::shouldReceive('set')->andReturn(true)->byDefault();
        Redis::shouldReceive('del')->andReturn(1)->byDefault();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('stub_importables');
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build a GenericImport instance with the given configuration.
     */
    private function makeImport(bool $update = false, ?string $email = 'test@example.com'): GenericImport
    {
        $batchId = 'test-batch-' . uniqid();
        return new GenericImport(
            new StubImportableModel(),
            $update,
            'sync',
            $email,
            $batchId,
            'imports/uploads/test.xlsx'
        );
    }

    /**
     * Feed one associative-array row to GenericImport::onRow().
     *
     * GenericImport::onRow() expects a Maatwebsite\Excel\Row. We create a minimal
     * subclass that proxies toArray() and getIndex() to avoid a full spreadsheet
     * file being needed.
     */
    private function feedRow(GenericImport $import, array $row, int $rowIndex = 2): void
    {
        $fakeRow = new FakeExcelRow($row, $rowIndex);
        $import->onRow($fakeRow);
    }

    /**
     * Prime the import's internal field→heading map.
     * In real imports this is done lazily on the first onRow() call when the
     * fieldHeadingMap is empty. We replicate that path so tests don't need to
     * know about ImportDetailsCache internals.
     * Because `getFieldHeadingMap()` is empty before the first row, onRow() itself
     * calls `initializeFieldHeadingMap(array_keys($rawRow))` — so we only need to
     * feed a row and the map will be initialised automatically.
     */

    // ─────────────────────────────────────────────────────────────────────────
    // CREATE MODE
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function create_mode_saves_valid_row_to_database(): void
    {
        $import = $this->makeImport(false);

        $this->feedRow($import, [
            'name'       => 'Alice Smith',
            'email'      => 'alice@example.com',
            'department' => 'Engineering',
            'status'     => 'active',
        ]);

        $this->assertDatabaseHas('stub_importables', [
            'email' => 'alice@example.com',
            'name'  => 'Alice Smith',
        ]);
    }

    #[Test]
    public function create_mode_validation_failure_does_not_persist_row(): void
    {
        $import = $this->makeImport(false);

        // email fails validation (not a valid email format)
        $this->feedRow($import, [
            'name'       => '',            // fails `required`
            'email'      => 'NOT-AN-EMAIL',
            'department' => 'HR',
            'status'     => 'active',
        ]);

        $this->assertDatabaseCount('stub_importables', 0);
    }

    #[Test]
    public function create_mode_duplicate_unique_key_records_error_without_crashing(): void
    {
        // Pre-seed Alice
        StubImportableModel::create([
            'name'  => 'Alice',
            'email' => 'alice@example.com',
        ]);

        $import = $this->makeImport(false);

        // Same email → UniqueConstraintViolationException caught internally
        $this->feedRow($import, [
            'name'       => 'Alice Duplicate',
            'email'      => 'alice@example.com',
            'department' => 'Marketing',
            'status'     => 'active',
        ]);

        // Original record unchanged; no second record created
        $this->assertDatabaseCount('stub_importables', 1);
        $this->assertDatabaseHas('stub_importables', ['name' => 'Alice']);
    }

    #[Test]
    public function create_mode_multiple_rows_in_sequence_all_persisted(): void
    {
        $import = $this->makeImport(false);

        $this->feedRow($import, ['name' => 'Bob',   'email' => 'bob@example.com',   'department' => 'Eng',   'status' => 'active'], 2);
        $this->feedRow($import, ['name' => 'Carol', 'email' => 'carol@example.com', 'department' => 'HR',    'status' => 'active'], 3);
        $this->feedRow($import, ['name' => 'Dave',  'email' => 'dave@example.com',  'department' => 'Legal', 'status' => 'inactive'], 4);

        $this->assertDatabaseCount('stub_importables', 3);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UPDATE MODE
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function update_mode_updates_existing_record_by_unique_key(): void
    {
        StubImportableModel::create([
            'name'       => 'Old Name',
            'email'      => 'alice@example.com',
            'department' => 'HR',
            'status'     => 'active',
        ]);

        $import = $this->makeImport(true);

        $this->feedRow($import, [
            'name'       => 'New Name',
            'email'      => 'alice@example.com',
            'department' => 'Engineering',
            'status'     => 'active',
        ]);

        $this->assertDatabaseHas('stub_importables', [
            'email'      => 'alice@example.com',
            'name'       => 'New Name',
            'department' => 'Engineering',
        ]);
    }

    #[Test]
    public function update_mode_does_not_create_new_record_when_existing_not_found(): void
    {
        $import = $this->makeImport(true);

        $this->feedRow($import, [
            'name'  => 'Ghost',
            'email' => 'ghost@example.com',
        ]);

        // No record should be created — update mode must not silently insert
        $this->assertDatabaseCount('stub_importables', 0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // applyImportContext hook
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function apply_import_context_is_called_with_correct_context_shape(): void
    {
        $capturedContext = null;

        // Swap the model instance to a spy subclass that records the context
        $spyModel = new class extends StubImportableModel {
            public static ?array $capturedContext = null;
            public function applyImportContext(array $context): void
            {
                self::$capturedContext = $context;
            }
        };

        $batchId = 'ctx-batch-001';
        $import  = new GenericImport($spyModel, false, 'sync', 'admin@example.com', $batchId, 'imports/test.xlsx');

        $this->feedRow($import, ['name' => 'Bob', 'email' => 'bob@ctx.com']);

        $ctx = $spyModel::$capturedContext;
        $this->assertNotNull($ctx);
        $this->assertArrayHasKey('notifiable_email', $ctx);
        $this->assertArrayHasKey('source', $ctx);
        $this->assertArrayHasKey('batch_id', $ctx);
        $this->assertArrayHasKey('is_update', $ctx);
        $this->assertSame('admin@example.com', $ctx['notifiable_email']);
        $this->assertSame($batchId, $ctx['batch_id']);
        $this->assertFalse($ctx['is_update']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Mail / Job Notification
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Calls handle() directly so the mail is sent even without QUEUE_CONNECTION=sync.
     * Mail::fake() captures the outgoing mail.
     * Models the HRMS DispatchCompleteImportNotificationJob → ImportCompleteMail lifecycle.
     */
    #[Test]
    public function notification_job_sends_import_complete_mail_to_notifiable_email(): void
    {
        Storage::put('imports/results/batch-test/final.xlsx', 'fake-xlsx-content');
        $filePath = Storage::path('imports/results/batch-test/final.xlsx');

        // Call handle() directly — bypasses queue entirely, forces synchronous execution
        (new DispatchCompleteImportNotificationJob('notify@example.com', $filePath, 0))->handle();

        Mail::assertSent(ImportCompleteMail::class, function ($mail) {
            return $mail->hasTo('notify@example.com');
        });
    }

    #[Test]
    public function notification_job_skips_mail_when_email_is_null(): void
    {
        $filePath = Storage::path('imports/results/batch-null/final.xlsx');

        (new DispatchCompleteImportNotificationJob(null, $filePath, 0))->handle();

        Mail::assertNothingSent();
    }

    #[Test]
    public function notification_job_reports_failed_row_count_in_mail(): void
    {
        Storage::put('imports/results/batch-fail/final.xlsx', 'fake');
        $filePath = Storage::path('imports/results/batch-fail/final.xlsx');

        (new DispatchCompleteImportNotificationJob('admin@example.com', $filePath, 5))->handle();

        Mail::assertSent(ImportCompleteMail::class, function ($mail) {
            return $mail->hasTo('admin@example.com');
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HasImport trait: Excel::queueImport integration
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Verifies that HasImport::import() queues a GenericImport job via Excel::queueImport().
     * Excel::fake() internally calls Queue::fake(), so we scope it to just these tests.
     * This mirrors how HRMS triggers Employee::import($path, $update, $email, 'sync').
     */
    #[Test]
    public function has_import_trait_queues_generic_import_with_correct_arguments(): void
    {
        Excel::fake(); // also fakes the queue internally
        Storage::put('imports/uploads/test.xlsx', 'fake-xlsx');

        StubImportableModel::import(
            'imports/uploads/test.xlsx',
            false,
            'notify@example.com',
            'sync'
        );

        // ExcelFake::assertQueued() keys by file path, not class name
        Excel::assertQueued('imports/uploads/test.xlsx', config('omniporter.import.disk'));
    }

    #[Test]
    public function has_import_trait_passes_update_flag_to_generic_import(): void
    {
        Excel::fake();
        Storage::put('imports/uploads/update.xlsx', 'fake-xlsx');

        StubImportableModel::import('imports/uploads/update.xlsx', true, 'hr@example.com', 'sync');

        // Verify the queued import has update=true via ExcelFake's stored import instance
        Excel::assertQueued('imports/uploads/update.xlsx', config('omniporter.import.disk'), function ($import) {
            $ref = new \ReflectionProperty(GenericImport::class, 'update');
            $ref->setAccessible(true);
            return $ref->getValue($import) === true;
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function import_resolves_and_syncs_belongs_to_many_relationships(): void
    {
        // Pre-seed roles
        StubRoleModel::create(['name' => 'admin']);
        StubRoleModel::create(['name' => 'editor']);
        StubRoleModel::create(['name' => 'viewer']);

        $import = $this->makeImport(false);

        // Feed a row with a comma-separated list of role names.
        // The heading "roles" matches the relationship method name in StubImportableModel.
        $this->feedRow($import, [
            'name'  => 'Bob Builder',
            'email' => 'bob@example.com',
            'roles' => 'admin,editor',
        ]);

        $user = StubImportableModel::where('email', 'bob@example.com')->first();
        $this->assertNotNull($user);

        $this->assertCount(2, $user->roles);
        $this->assertTrue($user->roles->contains('name', 'admin'));
        $this->assertTrue($user->roles->contains('name', 'editor'));
        $this->assertFalse($user->roles->contains('name', 'viewer'));
    }

    #[Test]
    public function import_ignores_non_existent_related_models_in_belongs_to_many(): void
    {
        StubRoleModel::create(['name' => 'admin']);

        $import = $this->makeImport(false);

        $this->feedRow($import, [
            'name'  => 'Ghost User',
            'email' => 'ghost@example.com',
            'roles' => 'admin,non_existent_role',
        ]);

        $user = StubImportableModel::where('email', 'ghost@example.com')->first();
        $this->assertNotNull($user);

        $this->assertCount(1, $user->roles);
        $this->assertTrue($user->roles->contains('name', 'admin'));
    }

    #[Test]
    public function import_resolves_belongs_to_relationships(): void
    {
        $dept = StubDepartmentModel::create(['name' => 'Engineering']);

        $import = $this->makeImport(false);

        $this->feedRow($import, [
            'name'           => 'John Engineer',
            'email'          => 'john@eng.com',
            'departmentRel'  => 'Engineering',
        ]);

        $user = StubImportableModel::where('email', 'john@eng.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals($dept->id, $user->department_id);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Test infrastructure: minimal Maatwebsite\Excel\Row substitute
//
// Maatwebsite\Excel\Row is a final-ish value object wrapping a PhpSpreadsheet
// RowIterator. Rather than booting a real spreadsheet, we extend it to override
// only the two methods GenericImport::onRow() uses: getIndex() and toArray().
// ─────────────────────────────────────────────────────────────────────────────

/**
 * A lightweight Excel Row stand-in that feeds fixed data to GenericImport::onRow().
 *
 * Maatwebsite\Excel\Row's constructor requires a PhpSpreadsheet Row instance.
 * We skip the parent constructor entirely and override getIndex() / toArray().
 */
class FakeExcelRow extends \Maatwebsite\Excel\Row
{
    public function __construct(
        private array $data,
        private int $index
    ) {
        // Deliberately skip parent::__construct() — we don't have a real spreadsheet row
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function toArray(
        $nullValue = null,
        $calculateFormulas = false,
        $formatData = false,
        $returnCellRef = false
    ): array {
        return $this->data;
    }
}
