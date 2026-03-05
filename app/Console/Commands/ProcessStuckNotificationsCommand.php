<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Console\Command;

class ProcessStuckNotificationsCommand extends Command
{
    protected $signature = 'notifications:process-stuck';

    protected $description = 'Re-dispatch notifications stuck in retrying status';

    public function handle(): int
    {
        $count = 0;

        Notification::where('status', Status::RETRYING)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now())
            ->chunkById(100, function ($notifications) use (&$count) {
                foreach ($notifications as $notification) {
                    $notification->update(['status' => Status::QUEUED]);
                    SendNotificationJob::dispatch($notification->id)
                        ->onQueue($notification->priority->value);
                    $count++;
                }
            });

        $this->info("Processed {$count} stuck notifications.");

        return self::SUCCESS;
    }
}
