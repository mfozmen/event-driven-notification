<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Console\Command;

class ProcessScheduledNotificationsCommand extends Command
{
    protected $signature = 'notifications:process-scheduled';

    protected $description = 'Queue scheduled notifications whose time has arrived';

    public function handle(): int
    {
        $count = 0;

        Notification::where('status', Status::PENDING)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->chunkById(100, function ($notifications) use (&$count) {
                foreach ($notifications as $notification) {
                    $notification->update(['status' => Status::QUEUED]);
                    SendNotificationJob::dispatch($notification->id)
                        ->onQueue($notification->priority->value);
                    $count++;
                }
            });

        $this->info("Processed {$count} scheduled notifications.");

        return self::SUCCESS;
    }
}
