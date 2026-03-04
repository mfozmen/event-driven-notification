<?php

namespace App\Http\Requests;

use App\Enums\Channel;
use App\Enums\Priority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNotificationRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'recipient' => ['required', 'string'],
            'channel' => ['required', Rule::enum(Channel::class)],
            'content' => ['required', 'string'],
            'priority' => ['sometimes', Rule::enum(Priority::class)],
            'idempotency_key' => ['sometimes', 'string'],
        ];

        if ($this->input('channel') === 'sms') {
            $rules['content'][] = 'max:160';
        }

        return $rules;
    }
}
