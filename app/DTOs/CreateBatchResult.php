<?php

namespace App\DTOs;

readonly class CreateBatchResult
{
    public function __construct(
        public string $batchId,
        public int $count,
    ) {}
}
