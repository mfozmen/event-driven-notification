<?php

use App\Http\Middleware\CorrelationIdMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

test('middleware generates correlation id when not provided', function () {
    $middleware = new CorrelationIdMiddleware;
    $request = Request::create('/test');

    $response = $middleware->handle($request, fn () => new Response);

    $correlationId = $request->attributes->get('correlation_id');

    expect($correlationId)->not->toBeNull();
    expect($correlationId)->toBeString();
    expect($response->headers->get('X-Correlation-ID'))->toBe($correlationId);
});

test('middleware uses correlation id from request header', function () {
    $middleware = new CorrelationIdMiddleware;
    $request = Request::create('/test');
    $request->headers->set('X-Correlation-ID', 'my-custom-id');

    $response = $middleware->handle($request, fn () => new Response);

    expect($request->attributes->get('correlation_id'))->toBe('my-custom-id');
    expect($response->headers->get('X-Correlation-ID'))->toBe('my-custom-id');
});

test('middleware sets correlation id on request attributes', function () {
    $middleware = new CorrelationIdMiddleware;
    $request = Request::create('/test');

    $middleware->handle($request, fn () => new Response);

    expect($request->attributes->has('correlation_id'))->toBeTrue();
});

test('middleware adds correlation id to response header', function () {
    $middleware = new CorrelationIdMiddleware;
    $request = Request::create('/test');

    $response = $middleware->handle($request, fn () => new Response);

    expect($response->headers->has('X-Correlation-ID'))->toBeTrue();
});
