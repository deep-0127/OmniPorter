# Changelog

All notable changes to OmniPorter will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Added
- hasOne relation support for import and export
- Progress tracking via `ProgressUpdated` event with real-time status updates
- CLI commands: `omniporter:import`, `omniporter:export`, `omniporter:clear-cache`, `omniporter:scaffold`
- Progress API endpoint (`GET /api/v1/progress/{batchId}`) for real-time import status queries
- Storage driver decoupling — supports S3, local, and any Laravel-compatible storage driver
- Exception consolidation to `OmniPorter\Exceptions` namespace
- Email templates for import_complete and export_complete notifications
- `ProgressController` for progress tracking API

### Changed
- Improved normalization to preserve digits in field names (fixes collision between "Address 1" and "Address 2")
- Prioritized belongsToMany mapping with 2-pass strategy (exact match first, then fuzzy)
- Updated cache to use configurable storage driver via `config('omniporter.cache.store')`
- Refactored ImportDetailsCache for better state management across chunks
- Export notifications now use storage-aware attachments for cloud storage compatibility

### Fixed
- Data loss during updates by reordering onRow() logic
- Cache resumption errors with proper key handling
- Enum support for attribute casting
- Memory leaks during large batch imports by resetting results array after each chunk
- Null-safety on relation method key access in GenericExport

### OSS package restructure: `src/` directory with `OmniPorter\` namespace
- `OmniPorterServiceProvider` with auto-discovery, config publishing, and route loading
- Public contracts: `OmniPorter\Contracts\Importable` and `OmniPorter\Contracts\Exportable`
- Exception hierarchy: `OmniPorterException`, `ImportException`, `ExportException`
- `config/omniporter.php` — fully documented configuration file
- Full integration test suite: `GenericImportIntegrationTest` covering create, update, validation, duplicate handling, context hooks, and mail notifications
- `FakeExcelRow` test helper to bypass PhpSpreadsheet for fast row-level testing
- Unit tests: `ApiResponseTest`, `ImportAttributeCasterTest`, `ImportDetailsCacheTest`, `ExportDetailsCacheTest`, `GenericExportBuildQueryTest`
- Feature tests: `ImportControllerTest`, `ExportControllerTest`
- `docs/gemini.md` — comprehensive architecture and developer reference

### Fixed
- `ExportController`: removed PHPUnit `isEmpty` import; replaced with `!empty()`
- `ExportController` / `ImportController`: null-safe `auth()->user()?->email`
- `ImportDetailsCache`: replaced `Model::all()` with lazy query builder to prevent full-table loads
- `ImportDetailsCache`: typed `OmniPorterImportException` instead of generic `Exception`
- `ImportDetailsCache`: guarded against non-string headings in `mapBelongsToManyHeadings()` to prevent `TypeError`
- `GenericImport`: `intdiv()` for correct integer chunk-index arithmetic
- `GenericImport`: reset `$this->results` in `afterChunk()` to prevent memory leaks during large batch imports
- `GenericExport`: null-safety on relation method key access
- `ScanImportableModelsCommand`: used `class_uses_recursive()` to improve trait discovery

---

## [0.1.0] — 2024-01-01

### Added
- Initial extraction from HRMS backend (`D:/HRMS/hrms-backend-2025`)
- `GenericImport` — chunk-based, queued Excel import with row-level validation
- `GenericExport` — streamed Excel export with relation resolution
- `HasImport` / `HasExport` traits for Eloquent model integration
- `ImportDetailsCache` — Redis-backed batch state management
- `ExportDetailsCache` — Redis-backed export context tracking
- `DispatchCompleteImportNotificationJob` — mail-on-complete lifecycle
- `ImportController` / `ExportController` — REST endpoints
- Auto-discovery via `ImportExportServiceProvider`
- `Importable` / `Exportable` interfaces
- `ImportAttributeCaster` — Eloquent cast for import metadata
- `ApiResponse` — consistent JSON envelope helpers

[Unreleased]: https://github.com/deep-shah/omniporter/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/deep-shah/omniporter/releases/tag/v0.1.0
