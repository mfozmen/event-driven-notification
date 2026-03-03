<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use Illuminate\Database\Eloquent\Factories\Factory;

class NotificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'correlation_id' => $this->faker->uuid(),
            'recipient' => $this->faker->phoneNumber(),
            'channel' => $this->faker->randomElement(Channel::cases()),
            'content' => $this->faker->sentence(),
            'priority' => Priority::NORMAL,
            'status' => Status::PENDING,
        ];
    }
}
