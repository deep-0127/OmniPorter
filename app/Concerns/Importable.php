<?php

namespace App\Concerns;

interface Importable
{
    public static function getUniqueKeyForImportExport(): string;

    public static function getListOfRelationDetails(): array;

    /**
     * Return an array mapping operations to Validator classes.
     * Example:
     * return [
     * 'create' => CreateEmployeeRequest::class,
     * 'update' => UpdateEmployeeRequest::class,
     * ];
     */
    public static function getImportValidators(): array;
}
