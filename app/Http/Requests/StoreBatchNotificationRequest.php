<?php

namespace App\Http\Requests;

use App\Enums\Channel;
use App\Enums\Priority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBatchNotificationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'notifications' => ['required', 'array', 'min:1', 'max:1000'],
            'notifications.*.recipient' => ['required', 'string'],
            'notifications.*.channel' => ['required', Rule::enum(Channel::class)],
            'notifications.*.content' => ['required', 'string'],
            'notifications.*.priority' => ['sometimes', 'nullable', Rule::enum(Priority::class)],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $notifications = $this->input('notifications', []);

            if (! is_array($notifications)) {
                return;
            }

            foreach ($notifications as $index => $notification) {
                if (! isset($notification['channel']) || ! isset($notification['content'])) {
                    continue;
                }

                $channel = $notification['channel'];
                $contentLength = strlen($notification['content']);

                $maxLength = match ($channel) {
                    'sms' => 160,
                    'email' => 10000,
                    'push' => 500,
                    default => null,
                };

                if ($maxLength !== null && $contentLength > $maxLength) {
                    $channelName = strtoupper($channel);
                    $validator->errors()->add(
                        "notifications.{$index}.content",
                        "{$channelName} content must not exceed {$maxLength} characters."
                    );
                }
            }
        });
    }
}
