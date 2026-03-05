<?php

namespace App\Http\Controllers;

use App\Enums\Channel;
use App\Enums\Status;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Redis;

class MetricsController extends Controller
{
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
