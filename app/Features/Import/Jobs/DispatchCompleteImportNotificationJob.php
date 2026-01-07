<?php

namespace App\Features\Import\Jobs;

use App\Features\EmployeeManagement\Domain\Employee\Models\Employee;
use App\Features\Import\Mail\ImportCompleteMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class DispatchCompleteImportNotificationJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private int $employeeId,
        private string $filePath,
        private int $failedRows
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $employee = Employee::find($this->employeeId);
        if ($employee && $employee->work_email) {
            Mail::to($employee->work_email)->send(new ImportCompleteMail($this->filePath, $this->failedRows));
        }
    }
}
