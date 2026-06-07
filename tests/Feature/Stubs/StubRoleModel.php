<?php

namespace Tests\Feature\Stubs;

use Illuminate\Database\Eloquent\Model;
use OmniPorter\Contracts\Importable;

class StubRoleModel extends Model implements Importable
{
    protected $table = 'stub_roles';
    protected $fillable = ['name'];

    public static function getUniqueKeyForImportExport(): string
    {
        return 'name';
    }

    public static function getListOfRelationDetails(): array
    {
        return [];
    }

    public static function getImportValidators(): array
    {
        return [];
    }

    public static function getUniqueKeysForUpdate(): array|string
    {
        return 'name';
    }

    public function applyImportContext(array $context): void {}
    public function afterImportSave(array $context): void {}

    public function beforeImportValidation(array &$data): void
    {
        // ...
    }
}
