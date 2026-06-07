<?php

namespace Tests\Unit;

use OmniPorter\Import\Helpers\ImportAttributeCaster;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/**
 * Minimal stub model for casting tests — no DB needed.
 */
class StubModelForCasting extends Model
{
    protected $table = 'stubs';

    protected $casts = [
        'is_active'   => 'boolean',
        'age'         => 'integer',
        'salary'      => 'float',
        'tags'        => 'array',
        'joined_at'   => 'date',
        'updated_at'  => 'datetime',
        'name'        => 'string',
    ];
}

class ImportAttributeCasterTest extends TestCase
{
    private StubModelForCasting $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new StubModelForCasting();
    }

    // ── boolean ───────────────────────────────────────────────────────────────

    public function test_casts_true_string_to_boolean(): void
    {
        $result = ImportAttributeCaster::castAttribute($this->model, 'is_active', 'true');
        $this->assertTrue($result);
    }

    public function test_casts_false_string_to_boolean(): void
    {
        $result = ImportAttributeCaster::castAttribute($this->model, 'is_active', 'false');
        $this->assertFalse($result);
    }

    public function test_casts_1_to_boolean_true(): void
    {
        $result = ImportAttributeCaster::castAttribute($this->model, 'is_active', '1');
        $this->assertTrue($result);
    }

    // ── integer ───────────────────────────────────────────────────────────────

    public function test_casts_numeric_string_to_integer(): void
    {
        $result = ImportAttributeCaster::castAttribute($this->model, 'age', '25');
        $this->assertSame(25, $result);
    }

    public function test_returns_raw_value_when_not_valid_integer(): void
    {
        $result = ImportAttributeCaster::castAttribute($this->model, 'age', 'not_a_number');
        $this->assertSame('not_a_number', $result);
    }

    // ── float ─────────────────────────────────────────────────────────────────

    public function test_casts_string_to_float(): void
    {
        $result = ImportAttributeCaster::castAttribute($this->model, 'salary', '50000.50');
        $this->assertSame(50000.50, $result);
    }

    // ── string ────────────────────────────────────────────────────────────────

    public function test_casts_and_trims_string(): void
    {
        $result = ImportAttributeCaster::castAttribute($this->model, 'name', '  John  ');
        $this->assertSame('John', $result);
    }

    // ── date ─────────────────────────────────────────────────────────────────

    public function test_casts_date_string_to_y_m_d(): void
    {
        $result = ImportAttributeCaster::castAttribute($this->model, 'joined_at', '2024-01-15');
        $this->assertSame('2024-01-15', $result);
    }

    public function test_casts_excel_serial_date_to_y_m_d(): void
    {
        // Excel serial 45292 = 2024-01-15
        $result = ImportAttributeCaster::castAttribute($this->model, 'joined_at', 45292);
        $this->assertStringMatchesFormat('%d-%d-%d', $result);
    }

    // ── datetime ─────────────────────────────────────────────────────────────

    public function test_casts_datetime_string_to_y_m_d_h_i_s(): void
    {
        $result = ImportAttributeCaster::castAttribute($this->model, 'updated_at', '2024-01-15 10:30:00');
        $this->assertSame('2024-01-15 10:30:00', $result);
    }

    // ── no cast ───────────────────────────────────────────────────────────────

    public function test_returns_value_as_is_when_no_cast_defined(): void
    {
        $result = ImportAttributeCaster::castAttribute($this->model, 'undefined_field', 'raw_value');
        $this->assertSame('raw_value', $result);
    }
}
