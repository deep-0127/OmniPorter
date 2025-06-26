<?php

namespace App\Concerns;

interface Importable
{
    public static function getUniqueKeyForImportExport(): string;

    public static function getListOfRelationDetails(): array;
}
