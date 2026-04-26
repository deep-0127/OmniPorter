<?php

declare(strict_types=1);

namespace OmniPorter\Contracts;

/**
 * Contract that must be satisfied by any Eloquent model that participates
 * in OmniPorter's import pipeline.
 *
 * Usage:
 *   1. Add the `HasImport` trait to your model.
 *   2. Implement this interface and its three required methods.
 *   3. OmniPorter will auto-discover the model during boot.
 *
 * @example
 * class Employee extends Model implements Importable
 * {
 *     use HasImport;
 *
 *     public static function getUniqueKeyForImportExport(): string
 *     {
 *         return 'employee_code';
 *     }
 *
 *     public static function getListOfRelationDetails(): array
 *     {
 *         return []; // or ['department_id' => DepartmentRelation::class]
 *     }
 *
 *     public static function getImportValidators(): array
 *     {
 *         return [
 *             'create' => CreateEmployeeRequest::class,
 *             'update' => UpdateEmployeeRequest::class,
 *         ];
 *     }
 * }
 */
interface Importable
{
    /**
     * The column name used to detect duplicate rows and drive update logic.
     *
     * For example: `'email'`, `'employee_code'`, `'sku'`
     */
    public static function getUniqueKeyForImportExport(): string;

    /**
     * Definitions for foreign-key relations that OmniPorter should resolve
     * during import (e.g. convert a department *name* to a `department_id`).
     *
     * Each entry maps an Excel heading to a RelationResolver class. Return an
     * empty array if the model has no relations to resolve.
     *
     * @return array<string, class-string>
     */
    public static function getListOfRelationDetails(): array;

    /**
     * Map operation names to FormRequest / Validator classes used for
     * row-level validation before persistence.
     *
     * Supported keys: `'create'`, `'update'`
     *
     * @return array<string, class-string>
     *
     * @example
     * return [
     *     'create' => CreateEmployeeRequest::class,
     *     'update' => UpdateEmployeeRequest::class,
     * ];
     */
    public static function getImportValidators(): array;

    /**
     * Hook called before row-level validation.
     * Allows modifying the raw mapped data before it is passed to the validator.
     * 
     * @param array $data The row data to be validated (passed by reference)
     */
    public function beforeImportValidation(array &$data): void;
}
