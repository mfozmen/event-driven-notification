<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->horizonRepo = Mockery::mock(MasterSupervisorRepository::class);
    $this->horizonRepo->shouldReceive('all')->andReturn(['supervisor-1'])->byDefault();
    $this->app->instance(MasterSupervisorRepository::class, $this->horizonRepo);
});

function mockRedisUp(): void
{
    Redis::shouldReceive('ping')->andReturn(true);
}

test('health returns 200 with all services up', function () {
    mockRedisUp();

    $response = $this->getJson('/api/health');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'services' => [
                'database' => ['status', 'latency_ms'],
                'redis' => ['status', 'latency_ms'],
                'horizon' => ['status'],
            ],
            'timestamp',
        ])
        ->assertJsonPath('status', 'healthy')
        ->assertJsonPath('services.database.status', 'up')
        ->assertJsonPath('services.redis.status', 'up')
        ->assertJsonPath('services.horizon.status', 'running');
});

test('health returns 503 when database is down', function () {
    mockRedisUp();
    DB::shouldReceive('connection->getPdo')->andThrow(new \Exception('Connection refused'));

    $response = $this->getJson('/api/health');

    $response->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('services.database.status', 'down');
});

test('health returns 503 when redis is down', function () {
    Redis::shouldReceive('ping')->andThrow(new \Exception('Connection refused'));

    $response = $this->getJson('/api/health');

    $response->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('services.redis.status', 'down');
});

test('health response includes latency_ms for database and redis', function () {
    mockRedisUp();

    $response = $this->getJson('/api/health');

    $response->assertStatus(200);

    $data = $response->json();

    expect($data['services']['database']['latency_ms'])->toBeNumeric()->toBeGreaterThanOrEqual(0);
    expect($data['services']['redis']['latency_ms'])->toBeNumeric()->toBeGreaterThanOrEqual(0);
});

test('health response includes correct timestamp', function () {
    mockRedisUp();

    $response = $this->getJson('/api/health');

    $response->assertStatus(200);

    $data = $response->json();

    expect($data['timestamp'])->toBeString();

    $parsed = \Carbon\Carbon::parse($data['timestamp']);
    expect($parsed->diffInSeconds(now()))->toBeLessThan(5);
});

test('health endpoint does not include correlation id header', function () {
    mockRedisUp();

    $response = $this->getJson('/api/health');

    $response->assertHeaderMissing('X-Correlation-ID');
});
