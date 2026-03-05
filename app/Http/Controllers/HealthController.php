<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use OpenApi\Attributes as OA;

class HealthController extends Controller
{
    #[OA\Get(
        path: '/api/health',
        summary: 'Health check',
        tags: ['Observability'],
        description: 'Returns service health status for database, Redis, and Horizon.',
        responses: [
            new OA\Response(
                response: 200,
                description: 'All services healthy',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'healthy'),
                        new OA\Property(
                            property: 'services',
                            type: 'object',
                            properties: [
                                new OA\Property(
                                    property: 'database',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'status', type: 'string', example: 'up'),
                                        new OA\Property(property: 'latency_ms', type: 'number', format: 'float', example: 1.23),
                                    ]
                                ),
                                new OA\Property(
                                    property: 'redis',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'status', type: 'string', example: 'up'),
                                        new OA\Property(property: 'latency_ms', type: 'number', format: 'float', example: 0.45),
                                    ]
                                ),
                                new OA\Property(
                                    property: 'horizon',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'status', type: 'string', example: 'running'),
                                    ]
                                ),
                            ]
                        ),
                        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                    ]
                )
            ),
            new OA\Response(response: 503, description: 'One or more services degraded'),
        ]
    )]
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
