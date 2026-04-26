<?php

namespace OmniPorter\Export\Jobs;

use OmniPorter\Export\Mail\ExportCompleteMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class DispatchCompleteExportNotificationJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private ?string $email,
        private string $filePath,
        private ?string $disk = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (empty($this->email)) {
            return;
        }

        $url = URL::temporarySignedRoute(
            'omniporter.export.download',
            now()->addDays(1),
            ['path' => $this->filePath]
        );

        Mail::to($this->email)->send(new ExportCompleteMail($url));
    }
}
