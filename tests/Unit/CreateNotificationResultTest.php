<?php

use App\DTOs\CreateNotificationResult;
use App\Models\Notification;

test('dto exposes notification and existed properties', function () {
    $notification = new Notification;

    $result = new CreateNotificationResult($notification, existed: false);

    expect($result->notification)->toBe($notification);
    expect($result->existed)->toBeFalse();
});

test('dto marks existed true for duplicate', function () {
    $notification = new Notification;

    $result = new CreateNotificationResult($notification, existed: true);

    expect($result->existed)->toBeTrue();
});
