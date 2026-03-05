<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Console\Command;

class ProcessStuckNotificationsCommand extends Command
{
    protected $signature = 'notifications:process-stuck';

    protected $description = 'Re-dispatch notifications stuck in retrying, processing, or pending status';

    public function handle(): int
    {
        $count = 0;

        $this->processStuckRetrying($count);
        $this->processStuckProcessing($count);
        $this->processStuckPending($count);

        $this->info("Processed {$count} stuck notifications.");

        return self::SUCCESS;
    }

    private function processStuckRetrying(int &$count): void
    {
        Notification::where('status', Status::RETRYING)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->chunkById(100, function ($notifications) use (&$count) {
                $this->requeue($notifications, $count);
            });
    }

    private function processStuckProcessing(int &$count): void
    {
        Notification::where('status', Status::PROCESSING)
            ->where('last_attempted_at', '<=', now()->subMinutes(5))
            ->chunkById(100, function ($notifications) use (&$count) {
                $this->requeue($notifications, $count);
            });
    }

    private function processStuckPending(int &$count): void
    {
        Notification::where('status', Status::PENDING)
            ->whereNull('scheduled_at')
            ->where('created_at', '<=', now()->subMinutes(2))
            ->chunkById(100, function ($notifications) use (&$count) {
                $this->requeue($notifications, $count);
            });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Notification>  $notifications
     */
    private function requeue(\Illuminate\Support\Collection $notifications, int &$count): void
    {
        foreach ($notifications as $notification) {
            $notification->update(['status' => Status::QUEUED]);
            SendNotificationJob::dispatch($notification->id)
                ->onQueue($notification->priority->value);
            $count++;
        }
    }
}
