<?php

namespace OmniPorter\Import\Jobs;

use OmniPorter\Import\Mail\ImportCompleteMail;
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
        private ?string $email,
        private string $filePath,
        private int $failedRows,
        private ?string $disk = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->email) {
            Mail::to($this->email)->send(new ImportCompleteMail($this->filePath, $this->failedRows, $this->disk));
        }
    }
}
