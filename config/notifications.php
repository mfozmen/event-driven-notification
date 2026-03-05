<?php

return [
    'webhook' => [
        'url' => env('WEBHOOK_SITE_URL', 'https://webhook.site'),
        'uuid' => env('WEBHOOK_SITE_UUID', ''),
    ],

    'rate_limit' => [
        'per_second' => (int) env('NOTIFICATION_RATE_LIMIT', 100),
    ],

    'retry' => [
        'max_attempts' => (int) env('NOTIFICATION_MAX_ATTEMPTS', 3),
        'base_delay_seconds' => (int) env('NOTIFICATION_RETRY_BASE_DELAY', 30),
    ],

    'circuit_breaker' => [
        'failure_threshold' => (int) env('CIRCUIT_BREAKER_FAILURE_THRESHOLD', 5),
        'window_seconds' => (int) env('CIRCUIT_BREAKER_WINDOW_SECONDS', 60),
        'cooldown_seconds' => (int) env('CIRCUIT_BREAKER_COOLDOWN_SECONDS', 30),
    ],

    'channels' => [
        'sms' => [
            'max_content_length' => 160,
        ],
        'email' => [
            'max_content_length' => 10000,
            'subject_required' => true,
        ],
        'push' => [
            'max_title_length' => 100,
            'max_content_length' => 500,
            'title_required' => true,
        ],
    ],
];
