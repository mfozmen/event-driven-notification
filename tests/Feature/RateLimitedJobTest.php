<?php

use App\Channels\ChannelProviderFactory;
use App\Contracts\NotificationChannelInterface;
use App\DTOs\DeliveryResult;
use App\Enums\Channel;
use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Services\ChannelRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $provider = Mockery::mock(NotificationChannelInterface::class);
    $provider->shouldReceive('send')->andReturn(DeliveryResult::successful('mock-msg-id'));

    $this->factory = Mockery::mock(ChannelProviderFactory::class);
    $this->factory->shouldReceive('resolve')->andReturn($provider);
});

test('job is released back to queue when rate limited', function () {
    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'channel' => Channel::SMS,
    ]);

    $limiter = $this->mock(ChannelRateLimiter::class);
    $limiter->shouldReceive('attempt')
        ->with(Channel::SMS)
        ->andReturn(false);

    $job = Mockery::mock(SendNotificationJob::class, [$notification->id])
        ->makePartial();
    $job->shouldReceive('release')->with(1)->once();

    $job->handle($limiter, $this->factory);

    $notification->refresh();

    expect($notification->status)->toBe(Status::QUEUED);
});

test('job processes notification when rate limit allows', function () {
    $notification = Notification::factory()->create([
        'status' => Status::QUEUED,
        'channel' => Channel::SMS,
    ]);

    $limiter = $this->mock(ChannelRateLimiter::class);
    $limiter->shouldReceive('attempt')
        ->with(Channel::SMS)
        ->andReturn(true);

    $job = new SendNotificationJob($notification->id);
    $job->handle($limiter, $this->factory);

    $notification->refresh();

    expect($notification->status)->toBe(Status::DELIVERED);
});
