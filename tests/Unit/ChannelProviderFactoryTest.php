<?php

use App\Channels\ChannelProviderFactory;
use App\Channels\EmailProvider;
use App\Channels\PushProvider;
use App\Channels\SmsProvider;
use App\Enums\Channel;

beforeEach(function () {
    $this->factory = app(ChannelProviderFactory::class);
});

test('resolve returns SmsProvider for SMS channel', function () {
    $provider = $this->factory->resolve(Channel::SMS);

    expect($provider)->toBeInstanceOf(SmsProvider::class);
});

test('resolve returns EmailProvider for EMAIL channel', function () {
    $provider = $this->factory->resolve(Channel::EMAIL);

    expect($provider)->toBeInstanceOf(EmailProvider::class);
});

test('resolve returns PushProvider for PUSH channel', function () {
    $provider = $this->factory->resolve(Channel::PUSH);

    expect($provider)->toBeInstanceOf(PushProvider::class);
});
