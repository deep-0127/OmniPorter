<?php

namespace App\Traits;

use App\Features\Export\Exports\GenericExport;
use App\Features\Export\Jobs\DispatchCompleteExportNotificationJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

trait HasExport
{
    public static function export(array $exportableColumns, array $columns, array $filters, $employee, string $exportType = 'xlsx'): void
    {;
        $batchId = Str::uuid()->toString();
        $filePath = "exports/$batchId.$exportType";

        Excel::queue(
            new GenericExport(new static, $batchId, $exportableColumns, $columns, $filters),
            $filePath
        )->chain([
            new DispatchCompleteExportNotificationJob($employee->work_email, Storage::path($filePath))
        ]);
    }
}
