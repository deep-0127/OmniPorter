<?php

namespace OmniPorter\Shared\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Broadcasting\Channel;

class ProgressUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function broadcastOn(): array
    {
        return [
            new Channel('omniporter-progress.' . $this->batchId),
        ];
    }

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $batchId,
        public string $type, // 'import' or 'export'
        public int $progress, // percentage 0-100
        public int $totalRows,
        public int $processedRows,
    ) {
    }
}
