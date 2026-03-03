<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'batch_id',
        'idempotency_key',
        'correlation_id',
        'recipient',
        'channel',
        'content',
        'priority',
        'status',
        'attempts',
        'max_attempts',
        'next_retry_at',
        'last_attempted_at',
        'delivered_at',
        'failed_at',
        'scheduled_at',
        'error_message',
    ];

    protected $casts = [
        'channel' => Channel::class,
        'priority' => Priority::class,
        'status' => Status::class,
        'next_retry_at' => 'datetime',
        'last_attempted_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'scheduled_at' => 'datetime',
    ];
}
