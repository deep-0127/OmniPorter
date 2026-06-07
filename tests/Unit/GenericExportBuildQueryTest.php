<?php

namespace Tests\Unit;

use OmniPorter\Export\Exports\GenericExport;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A minimal stub model for building export queries.
 * Uses an in-memory SQLite table created in setUp().
 */
class StubExportModel extends Model
{
    protected $table = 'stub_exports';
    protected $fillable = ['name', 'status', 'salary', 'department'];

    public static function getColumnsToExport(): array
    {
        return ['name', 'status', 'salary', 'department'];
    }

    public static function getListOfRelationDetails(): array
    {
        return [];
    }
}

class GenericExportBuildQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create the stub table in SQLite memory DB
        \Illuminate\Support\Facades\Schema::create('stub_exports', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->decimal('salary', 10, 2)->nullable();
            $table->string('department')->nullable();
            $table->timestamps();
        });
    }

    private function makeExport(array $filters): GenericExport
    {
        $columns = ['name', 'status', 'salary', 'department'];
        return new GenericExport(
            new StubExportModel(),
            'test-batch-' . uniqid(),   // unique per call → prevents static singleton reuse
            $columns,
            $columns,
            $filters
        );
    }

    // ── equality operator ─────────────────────────────────────────────────────

    public function test_eq_filter_produces_where_clause(): void
    {
        StubExportModel::create(['name' => 'Alice', 'status' => 'active']);
        StubExportModel::create(['name' => 'Bob',   'status' => 'inactive']);

        $export = $this->makeExport(['status_eq' => 'active']);
        $results = $export->buildQuery()->get();

        $this->assertCount(1, $results);
        $this->assertSame('Alice', $results->first()->name);
    }

    // ── not-equal operator ────────────────────────────────────────────────────

    public function test_ne_filter_excludes_matching_rows(): void
    {
        StubExportModel::create(['name' => 'Alice', 'status' => 'active']);
        StubExportModel::create(['name' => 'Bob',   'status' => 'inactive']);

        $export = $this->makeExport(['status_ne' => 'active']);
        $results = $export->buildQuery()->get();

        $this->assertCount(1, $results);
        $this->assertSame('Bob', $results->first()->name);
    }

    // ── like operator ─────────────────────────────────────────────────────────

    public function test_like_filter_uses_wildcard_search(): void
    {
        StubExportModel::create(['name' => 'Alice Smith']);
        StubExportModel::create(['name' => 'Bob Jones']);

        $export = $this->makeExport(['name_like' => 'Alice']);
        $results = $export->buildQuery()->get();

        $this->assertCount(1, $results);
        $this->assertSame('Alice Smith', $results->first()->name);
    }

    // ── IS NULL operator ──────────────────────────────────────────────────────

    public function test_null_filter_returns_rows_with_null_column(): void
    {
        StubExportModel::create(['name' => 'Alice', 'department' => null]);
        StubExportModel::create(['name' => 'Bob',   'department' => 'Engineering']);

        $export = $this->makeExport(['department_null' => '1']);
        $results = $export->buildQuery()->get();

        $this->assertCount(1, $results);
        $this->assertSame('Alice', $results->first()->name);
    }

    // ── IS NOT NULL operator ──────────────────────────────────────────────────

    public function test_nnull_filter_returns_rows_with_non_null_column(): void
    {
        StubExportModel::create(['name' => 'Alice', 'department' => null]);
        StubExportModel::create(['name' => 'Bob',   'department' => 'Engineering']);

        $export = $this->makeExport(['department_nnull' => '1']);
        $results = $export->buildQuery()->get();

        $this->assertCount(1, $results);
        $this->assertSame('Bob', $results->first()->name);
    }

    // ── IN operator ───────────────────────────────────────────────────────────

    public function test_in_filter_returns_matching_rows(): void
    {
        StubExportModel::create(['name' => 'Alice', 'status' => 'active']);
        StubExportModel::create(['name' => 'Bob',   'status' => 'pending']);
        StubExportModel::create(['name' => 'Carol', 'status' => 'inactive']);

        $export = $this->makeExport(['status_in' => 'active,pending']);
        $results = $export->buildQuery()->get();

        $this->assertCount(2, $results);
    }

    // ── unregistered column blocked ───────────────────────────────────────────

    public function test_unregistered_column_is_ignored_in_filter(): void
    {
        StubExportModel::create(['name' => 'Alice', 'status' => 'active']);
        StubExportModel::create(['name' => 'Bob',   'status' => 'inactive']);

        // 'secret_column' is not in exportableColumns — should be silently ignored
        $export = $this->makeExport(['secret_column_eq' => 'value']);
        $results = $export->buildQuery()->get();

        // All rows returned since the filter was ignored
        $this->assertCount(2, $results);
    }

    // ── empty filter skipped ──────────────────────────────────────────────────

    public function test_empty_filter_value_is_skipped(): void
    {
        StubExportModel::create(['name' => 'Alice']);
        StubExportModel::create(['name' => 'Bob']);

        $export = $this->makeExport(['name_eq' => '']);
        $results = $export->buildQuery()->get();

        $this->assertCount(2, $results);
    }

    // ── zero value filter ─────────────────────────────────────────────────────

    public function test_zero_filter_value_is_not_skipped(): void
    {
        StubExportModel::create(['name' => 'Alice', 'salary' => 0]);
        StubExportModel::create(['name' => 'Bob',   'salary' => 1000]);

        $export = $this->makeExport(['salary_eq' => '0']);
        $results = $export->buildQuery()->get();

        $this->assertCount(1, $results, 'Zero filter value should not be skipped');
        $this->assertSame('Alice', $results->first()->name);
    }

    // ── range operators ───────────────────────────────────────────────────────

    public function test_range_operators_work(): void
    {
        StubExportModel::create(['name' => 'Low',  'salary' => 10]);
        StubExportModel::create(['name' => 'Mid',  'salary' => 50]);
        StubExportModel::create(['name' => 'High', 'salary' => 100]);

        // GT
        $export = $this->makeExport(['salary_gt' => '50']);
        $this->assertCount(1, $export->buildQuery()->get());

        // GTE
        $export = $this->makeExport(['salary_gte' => '50']);
        $this->assertCount(2, $export->buildQuery()->get());

        // LT
        $export = $this->makeExport(['salary_lt' => '50']);
        $this->assertCount(1, $export->buildQuery()->get());

        // LTE
        $export = $this->makeExport(['salary_lte' => '50']);
        $this->assertCount(2, $export->buildQuery()->get());
    }
}
