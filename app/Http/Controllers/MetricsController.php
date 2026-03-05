<?php

namespace App\Http\Controllers;

use App\Enums\Channel;
use App\Enums\Status;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Redis;
use OpenApi\Attributes as OA;

class MetricsController extends Controller
{
    #[OA\Get(
        path: '/api/metrics',
        summary: 'System metrics',
        tags: ['Observability'],
        description: 'Returns queue depths, delivery counts, latency, and notification totals.',
        responses: [
            new OA\Response(
                response: 200,
                description: 'System metrics',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'queue_depths',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'high', type: 'integer', example: 0),
                                new OA\Property(property: 'normal', type: 'integer', example: 5),
                                new OA\Property(property: 'low', type: 'integer', example: 2),
                            ]
                        ),
                        new OA\Property(
                            property: 'deliveries',
                            type: 'object',
                            properties: [
                                new OA\Property(
                                    property: 'sms',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'success', type: 'integer', example: 100),
                                        new OA\Property(property: 'failure', type: 'integer', example: 3),
                                    ]
                                ),
                                new OA\Property(
                                    property: 'email',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'success', type: 'integer', example: 50),
                                        new OA\Property(property: 'failure', type: 'integer', example: 1),
                                    ]
                                ),
                                new OA\Property(
                                    property: 'push',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'success', type: 'integer', example: 75),
                                        new OA\Property(property: 'failure', type: 'integer', example: 2),
                                    ]
                                ),
                            ]
                        ),
                        new OA\Property(
                            property: 'latency',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'sms', type: 'object', properties: [new OA\Property(property: 'avg_ms', type: 'number', format: 'float', example: 120.5)]),
                                new OA\Property(property: 'email', type: 'object', properties: [new OA\Property(property: 'avg_ms', type: 'number', format: 'float', example: 95.3)]),
                                new OA\Property(property: 'push', type: 'object', properties: [new OA\Property(property: 'avg_ms', type: 'number', format: 'float', example: 80.1)]),
                            ]
                        ),
                        new OA\Property(property: 'totals', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
                        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                    ]
                )
            ),
        ]
    )]
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'queue_depths' => $this->getQueueDepths(),
            'deliveries' => $this->getDeliveryCounts(),
            'latency' => $this->getLatency(),
            'totals' => $this->getTotals(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array{high: int, normal: int, low: int}
     */
    private function getQueueDepths(): array
    {
        return [
            'high' => (int) Redis::llen('queues:high'),
            'normal' => (int) Redis::llen('queues:normal'),
            'low' => (int) Redis::llen('queues:low'),
        ];
    }

    /**
     * @return array<string, array{success: int, failure: int}>
     */
    private function getDeliveryCounts(): array
    {
        $counts = [];

        foreach (Channel::cases() as $channel) {
            $name = $channel->value;
            $counts[$name] = [
                'success' => (int) Redis::get("metrics:deliveries:success:{$name}"),
                'failure' => (int) Redis::get("metrics:deliveries:failure:{$name}"),
            ];
        }

        return $counts;
    }

    /**
     * @return array<string, array{avg_ms: float}>
     */
    private function getLatency(): array
    {
        $latency = [];

        foreach (Channel::cases() as $channel) {
            $name = $channel->value;
            /** @var array<int, string> $values */
            $values = Redis::lrange("metrics:latency:{$name}", 0, -1);

            if (count($values) === 0) {
                $latency[$name] = ['avg_ms' => 0];
            } else {
                $floats = array_map('floatval', $values);
                $latency[$name] = ['avg_ms' => round(array_sum($floats) / count($floats), 2)];
            }
        }

        return $latency;
    }

    /**
     * @return array<string, int>
     */
    private function getTotals(): array
    {
        $totals = [];

        foreach (Status::cases() as $status) {
            $count = Notification::where('status', $status)->count();
            if ($count > 0) {
                $totals[$status->value] = $count;
            }
        }

        return $totals;
    }
}
