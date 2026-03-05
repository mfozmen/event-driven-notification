<?php

use App\Logging\JsonLogFormatter;
use Monolog\Level;
use Monolog\LogRecord;

test('formatter produces valid JSON with required fields', function () {
    $formatter = new JsonLogFormatter;

    $record = new LogRecord(
        datetime: new \DateTimeImmutable,
        channel: 'app',
        level: Level::Info,
        message: 'Test message',
        context: [],
    );

    $output = $formatter->format($record);
    $decoded = json_decode($output, true);

    expect($decoded)->toBeArray();
    expect($decoded)->toHaveKeys(['timestamp', 'level', 'message']);
    expect($decoded['level'])->toBe('INFO');
    expect($decoded['message'])->toBe('Test message');
});

test('formatter includes correlation_id when present in context', function () {
    $formatter = new JsonLogFormatter;

    $record = new LogRecord(
        datetime: new \DateTimeImmutable,
        channel: 'app',
        level: Level::Info,
        message: 'Notification processed',
        context: [
            'correlation_id' => 'abc-123',
            'notification_id' => 'notif-456',
            'channel' => 'sms',
        ],
    );

    $output = $formatter->format($record);
    $decoded = json_decode($output, true);

    expect($decoded['correlation_id'])->toBe('abc-123');
    expect($decoded['notification_id'])->toBe('notif-456');
    expect($decoded['channel'])->toBe('sms');
});

test('formatter omits context fields when not present', function () {
    $formatter = new JsonLogFormatter;

    $record = new LogRecord(
        datetime: new \DateTimeImmutable,
        channel: 'app',
        level: Level::Warning,
        message: 'Simple warning',
        context: [],
    );

    $output = $formatter->format($record);
    $decoded = json_decode($output, true);

    expect($decoded)->not->toHaveKey('correlation_id');
    expect($decoded)->not->toHaveKey('notification_id');
    expect($decoded)->not->toHaveKey('channel');
});
