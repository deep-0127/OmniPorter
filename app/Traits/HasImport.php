<?php

namespace App\Traits;

use App\Features\Import\Imports\GenericImport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

trait HasImport
{
    public static function import(string $filePath, bool $update, $employee, string $associationMethod = "sync",): void
    {
        $batchId = Str::uuid()->toString();

        Excel::queueImport(
            new GenericImport(new static, $update, $associationMethod, $employee, $batchId, $filePath),
            $filePath
        );
    }
}
