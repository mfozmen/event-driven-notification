<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

class HealthController extends Controller
{
    public function __invoke(MasterSupervisorRepository $masterSupervisorRepository): JsonResponse
    {
        $services = [];
        $allHealthy = true;

        $services['database'] = $this->checkDatabase();
        $services['redis'] = $this->checkRedis();
        $services['horizon'] = $this->checkHorizon($masterSupervisorRepository);

        foreach ($services as $service) {
            if (in_array($service['status'], ['down', 'stopped'])) {
                $allHealthy = false;

                break;
            }
        }

        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'degraded',
            'services' => $services,
            'timestamp' => now()->toIso8601String(),
        ], $allHealthy ? 200 : 503);
    }

    /**
     * @return array{status: string, latency_ms: float}
     */
    private function checkDatabase(): array
    {
        $start = microtime(true);

        try {
            DB::connection()->getPdo();
            $latency = round((microtime(true) - $start) * 1000, 2);

            return ['status' => 'up', 'latency_ms' => $latency];
        } catch (\Throwable) {
            return ['status' => 'down', 'latency_ms' => 0];
        }
    }

    /**
     * @return array{status: string, latency_ms: float}
     */
    private function checkRedis(): array
    {
        $start = microtime(true);

        try {
            Redis::ping();
            $latency = round((microtime(true) - $start) * 1000, 2);

            return ['status' => 'up', 'latency_ms' => $latency];
        } catch (\Throwable) {
            return ['status' => 'down', 'latency_ms' => 0];
        }
    }

    /**
     * @return array{status: string}
     */
    private function checkHorizon(MasterSupervisorRepository $repository): array
    {
        try {
            $masters = $repository->all();

            return ['status' => count($masters) > 0 ? 'running' : 'stopped'];
        } catch (\Throwable) {
            return ['status' => 'stopped'];
        }
    }
}
