<?php

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Notification */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recipient' => $this->recipient,
            'channel' => $this->channel->value,
            'content' => $this->content,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'correlation_id' => $this->correlation_id,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'scheduled_at' => $this->scheduled_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
