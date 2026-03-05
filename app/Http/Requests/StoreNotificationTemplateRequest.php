<?php

namespace App\Http\Requests;

use App\Enums\Channel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNotificationTemplateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'unique:notification_templates,name'],
            'channel' => ['required', Rule::enum(Channel::class)],
            'body_template' => ['required', 'string'],
            'variables' => ['sometimes', 'array'],
            'variables.*' => ['string'],
        ];
    }
}
