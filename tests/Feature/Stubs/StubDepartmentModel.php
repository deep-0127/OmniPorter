<?php

namespace Tests\Feature\Stubs;

use Illuminate\Database\Eloquent\Model;
use OmniPorter\Contracts\Importable;

class StubDepartmentModel extends Model implements Importable
{
    protected $table = 'stub_departments';
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

    public function beforeImportValidation(array &$data): void
    {
        // ...
    }
}
