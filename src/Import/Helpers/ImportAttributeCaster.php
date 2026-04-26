<?php

namespace OmniPorter\Import\Helpers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ImportAttributeCaster
{
    public static function castAttribute(Model $model, string $field, mixed $value): mixed
    {
        $castDefinition = $model->getCasts()[$field] ?? null;

        if (! $castDefinition) {
            return $value;
        }

        return match (true) {
            in_array($castDefinition, ['bool', 'boolean'])
            => filter_var($value, FILTER_VALIDATE_BOOLEAN),

            in_array($castDefinition, ['int', 'integer'])
            => filter_var($value, FILTER_VALIDATE_INT) !== false ? (int)$value : $value,

            in_array($castDefinition, ['float', 'double', 'real', 'decimal'])
            => filter_var($value, FILTER_VALIDATE_FLOAT) !== false ? (float)$value : $value,
            str_starts_with($castDefinition, 'date') || str_starts_with($castDefinition, 'datetime') || str_starts_with($castDefinition, 'immutable_date')
            => self::resolveExcelDate($castDefinition, $value),

            $castDefinition === 'string'
            => trim((string)$value),

            enum_exists($castDefinition) && method_exists($castDefinition, 'tryFrom')
            => self::resolveEnum($castDefinition, $value),

            default => $value,
        };
    }

    private static function resolveEnum(string $enumClass, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        // 1. Try direct value match (e.g., 'pending' -> Status::PENDING)
        $enumValue = $enumClass::tryFrom($value);
        if ($enumValue) {
            return $enumValue->value;
        }

        // 2. Try case name match (e.g., 'PENDING' -> Status::PENDING)
        foreach ($enumClass::cases() as $case) {
            if (Str::lower($case->name) === Str::lower((string)$value)) {
                return $case->value;
            }
        }

        return $value;
    }

    private static function resolveExcelDate(string $castDefinition, mixed $value): ?string
    {
        try {
            $isDateTime = str_contains($castDefinition, 'datetime');
            $format = $isDateTime ? 'Y-m-d H:i:s' : 'Y-m-d';

            if (is_numeric($value)) {
                return ExcelDate::excelToDateTimeObject($value)->format($format);
            }

            return Carbon::parse($value)->format($format);
        } catch (\Exception $e) {
            return $value;
        }
    }
}
