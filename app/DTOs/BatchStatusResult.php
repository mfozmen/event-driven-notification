<?php

namespace App\DTOs;

readonly class BatchStatusResult
{
    /**
     * @param  array<string, int>  $statusCounts
     */
    public function __construct(
        public string $batchId,
        public int $total,
        public array $statusCounts,
    ) {}
}
