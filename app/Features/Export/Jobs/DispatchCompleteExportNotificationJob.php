<?php

namespace App\Features\Export\Jobs;

use App\Features\Export\Mail\ExportCompleteMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class DispatchCompleteExportNotificationJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private string $email,
        private string $filePath
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Mail::to($this->email)->send(new ExportCompleteMail($this->filePath));
    }
}
