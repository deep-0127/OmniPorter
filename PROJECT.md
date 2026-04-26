# OmniPorter Project Guide

This document contains essential commands and developer notes for working with the OmniPorter framework.

## 🚀 Essential Commands

### 1. Configuration & Setup
Publish the configuration file to customize disks, cache stores, and more.
```bash
php artisan vendor:publish --tag="omniporter-config"
```

### 2. Scaffolding
Quickly generate the necessary structure for a new resource (not fully implemented in v1, but available for structure generation).
```bash
php artisan omniporter:scaffold {ResourceName}
```

### 3. Maintenance
Clear the OmniPorter job state cache if jobs are stuck or metadata is corrupted.
```bash
php artisan omniporter:clear-cache
```

### 4. Background Processing
OmniPorter relies heavily on queues. Ensure your queue worker is running in development:
```bash
php artisan queue:work
```

## 🛠️ Developer Checklist

- [ ] **Model Contracts**: Ensure your model implements `Importable` or `Exportable`.
- [ ] **Traits**: Add `use HasImport;` and `use HasExport;` to your model.
- [ ] **Registration**: Check that your model is discovered by the `ImportExportServiceProvider`.
- [ ] **Disk Configuration**: Verify that `OMNIPORTER_IMPORT_DISK` and `OMNIPORTER_EXPORT_DISK` are set in your `.env`.
- [ ] **Queue Connection**: Ensure `QUEUE_CONNECTION` is not set to `sync` in production (use `redis` or `database`).

## 🧪 Testing

Run the test suite to ensure everything is working correctly:
```bash
./vendor/bin/phpunit
```
