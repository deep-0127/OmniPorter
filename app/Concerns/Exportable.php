<?php

namespace App\Concerns;

interface Exportable
{
    public static function getUniqueKeyForImportExport(): string;

    public static function getListOfRelationDetails(): array;

    public static function getColumnsToExport(): array;
}
