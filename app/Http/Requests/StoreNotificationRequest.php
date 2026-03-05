<?php

namespace App\Http\Requests;

use App\Enums\Channel;
use App\Enums\Priority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNotificationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'recipient' => ['required', 'string'],
            'channel' => ['required', Rule::enum(Channel::class)],
            'content' => ['required_without:template_id', 'nullable', 'string'],
            'priority' => ['sometimes', 'nullable', Rule::enum(Priority::class)],
            'idempotency_key' => ['sometimes', 'nullable', 'string'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'template_id' => ['sometimes', 'nullable', 'string', 'exists:notification_templates,id'],
            'template_variables' => ['sometimes', 'nullable', 'array'],
        ];

        match ($this->input('channel')) {
            'sms' => $rules['content'][] = 'max:160',
            'email' => $rules['content'][] = 'max:10000',
            'push' => $rules['content'][] = 'max:500',
            default => null,
        };

        return $rules;
    }
}
