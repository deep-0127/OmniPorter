<?php

declare(strict_types=1);

namespace OmniPorter\Contracts;

/**
 * Contract that must be satisfied by any Eloquent model that participates
 * in OmniPorter's export pipeline.
 *
 * Usage:
 *   1. Add the `HasExport` trait to your model.
 *   2. Implement this interface and its three required methods.
 *   3. OmniPorter will auto-discover the model during boot.
 *
 * @example
 * class Employee extends Model implements Exportable
 * {
 *     use HasExport;
 *
 *     public static function getUniqueKeyForImportExport(): string
 *     {
 *         return 'employee_code';
 *     }
 *
 *     public static function getListOfRelationDetails(): array
 *     {
 *         return []; // or ['department' => 'name']
 *     }
 *
 *     public static function getColumnsToExport(): array
 *     {
 *         return ['employee_code', 'name', 'email', 'department'];
 *     }
 * }
 */
interface Exportable
{
    /**
     * The column name used as the unique identifier in export reports.
     */
    public static function getUniqueKeyForImportExport(): string;

    /**
     * Relation definitions that should be resolved when building export rows
     * (e.g. replace `department_id` with the department name).
     *
     * @return array<string, string>
     */
    public static function getListOfRelationDetails(): array;

    /**
     * The ordered list of column names (or computed labels) to include in the
     * exported spreadsheet.
     *
     * @return array<int, string>
     */
    public static function getColumnsToExport(): array;
}
