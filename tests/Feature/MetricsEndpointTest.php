<?php

use App\Enums\Status;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    Redis::shouldReceive('llen')->with('queues:high')->andReturn(5)->byDefault();
    Redis::shouldReceive('llen')->with('queues:normal')->andReturn(10)->byDefault();
    Redis::shouldReceive('llen')->with('queues:low')->andReturn(2)->byDefault();

    Redis::shouldReceive('get')->with('metrics:deliveries:success:sms')->andReturn(100)->byDefault();
    Redis::shouldReceive('get')->with('metrics:deliveries:success:email')->andReturn(50)->byDefault();
    Redis::shouldReceive('get')->with('metrics:deliveries:success:push')->andReturn(25)->byDefault();
    Redis::shouldReceive('get')->with('metrics:deliveries:failure:sms')->andReturn(5)->byDefault();
    Redis::shouldReceive('get')->with('metrics:deliveries:failure:email')->andReturn(3)->byDefault();
    Redis::shouldReceive('get')->with('metrics:deliveries:failure:push')->andReturn(1)->byDefault();

    Redis::shouldReceive('lrange')->with('metrics:latency:sms', 0, -1)->andReturn([120.5, 80.3, 100.0])->byDefault();
    Redis::shouldReceive('lrange')->with('metrics:latency:email', 0, -1)->andReturn([200.0, 150.0])->byDefault();
    Redis::shouldReceive('lrange')->with('metrics:latency:push', 0, -1)->andReturn([])->byDefault();
});

test('metrics returns queue depths per priority', function () {
    $response = $this->getJson('/api/metrics');

    $response->assertStatus(200)
        ->assertJsonPath('queue_depths.high', 5)
        ->assertJsonPath('queue_depths.normal', 10)
        ->assertJsonPath('queue_depths.low', 2);
});

test('metrics returns success and failure counts per channel', function () {
    $response = $this->getJson('/api/metrics');

    $response->assertStatus(200)
        ->assertJsonPath('deliveries.sms.success', 100)
        ->assertJsonPath('deliveries.sms.failure', 5)
        ->assertJsonPath('deliveries.email.success', 50)
        ->assertJsonPath('deliveries.email.failure', 3)
        ->assertJsonPath('deliveries.push.success', 25)
        ->assertJsonPath('deliveries.push.failure', 1);
});

test('metrics returns average delivery latency per channel', function () {
    $response = $this->getJson('/api/metrics');

    $response->assertStatus(200);

    $data = $response->json();

    expect((float) $data['latency']['sms']['avg_ms'])->toBe(100.27);
    expect((float) $data['latency']['email']['avg_ms'])->toBe(175.0);
    expect($data['latency']['push']['avg_ms'])->toBe(0);
});

test('metrics returns total notifications by status from database', function () {
    Notification::factory()->count(3)->create(['status' => Status::DELIVERED]);
    Notification::factory()->count(2)->create(['status' => Status::QUEUED]);
    Notification::factory()->create(['status' => Status::PERMANENTLY_FAILED]);

    $response = $this->getJson('/api/metrics');

    $response->assertStatus(200)
        ->assertJsonPath('totals.delivered', 3)
        ->assertJsonPath('totals.queued', 2)
        ->assertJsonPath('totals.permanently_failed', 1);
});

test('metrics response has correct structure', function () {
    $response = $this->getJson('/api/metrics');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'queue_depths' => ['high', 'normal', 'low'],
            'deliveries' => [
                'sms' => ['success', 'failure'],
                'email' => ['success', 'failure'],
                'push' => ['success', 'failure'],
            ],
            'latency' => [
                'sms' => ['avg_ms'],
                'email' => ['avg_ms'],
                'push' => ['avg_ms'],
            ],
            'totals',
            'timestamp',
        ]);
});
