<?php

namespace App\Enums;

enum Status: string
{
    case PENDING = 'pending';
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case DELIVERED = 'delivered';
    case FAILED = 'failed';
    case RETRYING = 'retrying';
    case PERMANENTLY_FAILED = 'permanently_failed';
    case CANCELLED = 'cancelled';
}
