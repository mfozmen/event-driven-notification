<?php

namespace App\Http\Requests;

use App\Enums\Channel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNotificationTemplateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', Rule::unique('notification_templates', 'name')->ignore($this->route('template'))],
            'channel' => ['required', Rule::enum(Channel::class)],
            'body_template' => ['required', 'string'],
            'variables' => ['sometimes', 'array'],
            'variables.*' => ['string'],
        ];
    }
}
