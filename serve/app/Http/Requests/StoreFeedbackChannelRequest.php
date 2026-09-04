<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeedbackChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'integer', Rule::exists('domains', 'id')->where('enable', true)],
            'name' => ['required', 'string', 'max:80'],
            'operator_name' => ['required', 'string', 'max:80'],
            'intro' => ['nullable', 'string', 'max:500'],
            'service_phone' => ['nullable', 'string', 'max:32'],
            'sla_text' => ['required', 'string', 'max:80'],
            'categories' => ['required', 'array', 'min:1', 'max:10'],
            'categories.*' => ['required', 'string', 'max:20', 'distinct'],
            'contact_required' => ['required', 'boolean'],
            'retention_days' => ['required', 'integer', 'min:30', 'max:365'],
            'webhook_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }
}
