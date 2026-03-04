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
            'notifications.*.priority' => ['sometimes', Rule::enum(Priority::class)],
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
                if (
                    isset($notification['channel'])
                    && $notification['channel'] === 'sms'
                    && isset($notification['content'])
                    && strlen($notification['content']) > 160
                ) {
                    $validator->errors()->add(
                        "notifications.{$index}.content",
                        'SMS content must not exceed 160 characters.'
                    );
                }
            }
        });
    }
}
