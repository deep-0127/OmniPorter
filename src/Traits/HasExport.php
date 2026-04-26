<?php

namespace OmniPorter\Traits;

use OmniPorter\Export\Exports\GenericExport;
use OmniPorter\Export\Jobs\DispatchCompleteExportNotificationJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

trait HasExport
{
    public static function export(array $exportableColumns, array $columns, array $filters, ?string $notifiableEmail = null, string $exportType = 'xlsx'): void
    {
        $batchId = Str::uuid()->toString();
        $filePath = "exports/$batchId.$exportType";
        $disk = config('omniporter.export.disk');

        Excel::queue(
            new GenericExport(new static, $batchId, $exportableColumns, $columns, $filters),
            $filePath,
            $disk
        )->chain([
            new DispatchCompleteExportNotificationJob($notifiableEmail, $filePath, $disk)
        ]);
    }

    public static function getUniqueKeyForImportExport(): string
    {
        $instance = new static;
        if (property_exists($instance, 'omni_porter_config') && isset($instance->omni_porter_config['unique_key'])) {
            return (string) $instance->omni_porter_config['unique_key'];
        }

        return 'id';
    }

    public static function getListOfRelationDetails(): array
    {
        $instance = new static;
        if (property_exists($instance, 'omni_porter_config') && isset($instance->omni_porter_config['relations'])) {
            return (array) $instance->omni_porter_config['relations'];
        }

        return [];
    }

    public static function getColumnsToExport(): array
    {
        $instance = new static;
        if (property_exists($instance, 'omni_porter_config') && isset($instance->omni_porter_config['columns'])) {
            return (array) $instance->omni_porter_config['columns'];
        }

        $fillable = $instance->getFillable();
        if (!empty($fillable)) {
            return $fillable;
        }

        return \Illuminate\Support\Facades\Schema::getColumnListing($instance->getTable());
    }
}
