<?php

namespace Tests\Feature\Stubs;

use OmniPorter\Contracts\Importable;
use OmniPorter\Traits\HasImport;
use OmniPorter\Traits\HasExport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A minimal model that implements both HasImport and HasExport for integration tests.
 * This mirrors the real-world Employee model from the HRMS backend.
 *
 * Validators are defined as inner classes at the bottom of this file to ensure
 * they are always loaded in the same compilation unit as the model itself,
 * avoiding any PSR-4 / class_exists() resolution issues during test bootstrap.
 */
class StubImportableModel extends Model implements Importable
{
    use HasImport, HasExport;

    protected $table = 'stub_importables';

    protected $fillable = [
        'name',
        'email',
        'department',
        'department_id',
        'status',
    ];

    protected $casts = [
        'name'       => 'string',
        'email'      => 'string',
        'department' => 'string',
        'status'     => 'string',
    ];

    // ── Importable interface ──────────────────────────────────────────────────

    public static function getUniqueKeyForImportExport(): string
    {
        return 'email';
    }

    public static function getListOfRelationDetails(): array
    {
        return [
            'roles' => [
                'type'  => 'belongsToMany',
                'model' => StubRoleModel::class,
                'method' => 'roles',
            ],
            'departmentRel' => [
                'type'  => 'belongsTo',
                'model' => StubDepartmentModel::class,
                'method' => 'departmentRel',
                'field' => 'department_id',
            ],
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(StubRoleModel::class, 'stub_importable_roles', 'stub_importable_id', 'stub_role_id');
    }

    public function departmentRel(): BelongsTo
    {
        return $this->belongsTo(StubDepartmentModel::class, 'department_id');
    }

    public static function getImportValidators(): array
    {
        return [
            'create' => StubCreateValidator::class,
            'update' => StubUpdateValidator::class,
        ];
    }

    public static function getUniqueKeysForUpdate(): array|string
    {
        return 'email';
    }

    // ── HasExport interface ───────────────────────────────────────────────────

    public static function getColumnsToExport(): array
    {
        return ['name', 'email', 'department', 'status'];
    }

    // ── Hook tracking (for test assertions) ───────────────────────────────────

    public bool $contextApplied = false;
    public bool $afterSaveCalled = false;
    public ?string $notifiableEmailFromContext = null;

    public function applyImportContext(array $context): void
    {
        $this->contextApplied = true;
        $this->notifiableEmailFromContext = $context['notifiable_email'] ?? null;
    }

    public function afterImportSave(array $context): void
    {
        $this->afterSaveCalled = true;
    }

    // ── HasExport static import helper ────────────────────────────────────────

    public static function export(
        array $exportableColumns,
        array $columns,
        array $filters,
        ?string $notifiableEmail,
        string $exportType = 'xlsx'
    ): void {
        $batchId = \Illuminate\Support\Str::uuid()->toString();
        \Maatwebsite\Excel\Facades\Excel::queueExport(
            new \OmniPorter\Export\Exports\GenericExport(
                new static(),
                $batchId,
                $exportableColumns,
                $columns,
                $filters,
            ),
            "exports/{$batchId}.{$exportType}",
        );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Validator stubs — defined in the same file to guarantee class_exists() works
// without relying on PSR-4 dev-autoload being fully resolved at test bootstrap.
//
// ImportDetailsCache checks: class_exists($validatorClassName)
// These classes satisfy that check by being present in the same compilation unit.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Validator for 'create' import mode.
 * Mirrors HRMS CreateEmployeeRequest: rules() signature for create context.
 */
class StubCreateValidator
{
    /**
     * @param bool $isUpdate — ignored for create, matches HasImport contract
     */
    public function rules(bool $isUpdate = false): array
    {
        return [
            'name'  => 'required|string|max:255',
            'email' => 'required|email',
        ];
    }
}

/**
 * Validator for 'update' import mode.
 * Mirrors HRMS UpdateEmployeeRequest: rules($id, $isUpdate) signature.
 */
class StubUpdateValidator
{
    /**
     * @param int|null $id       — the existing record ID (for unique rules)
     * @param bool     $isUpdate — always true in update mode
     */
    public function rules(?int $id = null, bool $isUpdate = true): array
    {
        return [
            'name'  => 'sometimes|string|max:255',
            'email' => 'required|email',
        ];
    }
}
