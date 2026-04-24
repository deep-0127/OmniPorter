<?php

namespace App\Traits;

use App\Features\Import\Imports\GenericImport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

trait HasImport
{
    public static function import(string $filePath, bool $update, ?string $notifiableEmail = null, string $associationMethod = "sync",): void
    {
        $batchId = Str::uuid()->toString();

        Excel::queueImport(
            new GenericImport(new static, $update, $associationMethod, $notifiableEmail, $batchId, $filePath),
            $filePath
        );
    }

    /**
     * Allows the model to apply import-specific context to itself before saving.
     *
     * This method is useful for setting default values (like `finalized_by`, `organization_id`,
     * `source`, `created_by`, etc.) using data provided by the importer.
     *
     * ---
     * ### Usage:
     * This method is optional, but recommended for any model needing context-aware
     * import behavior. It should mutate the model instance directly.
     *---
     * Example in a model:
     * ```
     * public function applyImportContext(array $context): void
     * {
     *     $this->finalized_by = $context['user_id'] ?? null;
     *     $this->finalized_at = now();
     * }
     * ```
     * ---
     * @param array<string, mixed> $context An associative array of contextual import data.
     *                                      Example: ```['notifiable_email' => 'user@example.com', 'source' => 'excel', 'batch_id' => 'batch_1234']```
     * @return void
     */
    public function applyImportContext(array $context): void {}

    /**
     * Returns the field(s) that uniquely identify a record during import updates.
     *
     * ---
     * The importer uses this method to determine which column(s) to use when matching existing records in the database for **update operations**.
     *
     * ---
     * ### Example (Single Unique Key)
     * ```
     * public static function getUniqueKeysForUpdate(): array|string
     * {
     *     return 'work_email';
     * }
     * ```
     *
     * ---
     * ### Example (Multiple Unique Keys)
     * ```
     * public static function getUniqueKeysForUpdate(): array|string
     * {
     *     return ['user_id', 'month', 'year'];
     * }
     * ```
     *
     * ---
     * ### When to use:
     * - **Single key**: If the table uses a primary key (e.g., `id`) or unique key (e.g., `work_email`) for uniqueness.
     * - **Multiple keys**: If the table enforces a **composite unique constraint**
     *   (e.g., `user_id`, `month`, `year` in a payroll table).
     * ---
     * ### Related methods:
     * - `getUniqueKeyForImportExport()` – Defines uniqueness for import/export **relations**.
     * - `getUniqueKeysForUpdate()` – Defines uniqueness for **updates**.
     *
     * ---
     * @return array<string>|string
     *         - A string for single-column uniqueness (e.g., 'id').
     *         - An array for multi-column uniqueness (e.g., ['user_id', 'month', 'year']).
     */
    public static function getUniqueKeysForUpdate(): array|string {
        return self::getUniqueKeyForImportExport();
    }

    /**
     * Hook: Runs immediately after the model is saved during the import process.
     *
     * This method allows the model to trigger side effects—such as dispatching events,
     * sending notifications, or creating audit logs—once the record has been successfully
     * persisted to the database.
     *
     * ---
     * ### Usage:
     * This is a "no-op" (empty) method by default. Override it in your model class
     * to define custom logic that must occur strictly after the save operation.
     *
     * ---
     * ### Example:
     * ```
     * public function afterImportSave(array $context): void
     * {
     *      if ($this->wasRecentlyCreated) {
     *          // Send a welcome email using data from the import context
     *          Mail::to($this->email)->queue(new WelcomeEmail($context['notifiable_email']));
     *      }
     *
     *      Log::info("Imported record {$this->id} in batch {$context['batch_id']}");
     * }
     * ```
     * ---
     * @param array<string, mixed> $context An associative array of contextual import data.
     * Example: ```['notifiable_email' => 'user@example.com', 'source' => 'excel', 'batch_id' => '...']```
     * @return void
     */
     public function afterImportSave(array $context): void {}
}
