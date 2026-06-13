<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CreateNotificationJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $userId,
        public string $type,
        public string $message
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        \App\Models\UserNotification::create([
            'idUser' => $this->userId,
            'type' => $this->type,
            'read' => false,
            'message' => $this->message,
        ]);
    }
}
