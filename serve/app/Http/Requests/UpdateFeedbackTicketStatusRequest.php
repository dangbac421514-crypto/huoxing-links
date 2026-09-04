<?php

namespace App\Http\Requests;

use App\Enums\FeedbackTicketStatus;
use App\Models\FeedbackTicket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateFeedbackTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        FeedbackTicket::query()
            ->where('user_id', auth('api')->id())
            ->findOrFail($this->route('id'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', new Enum(FeedbackTicketStatus::class)],
        ];
    }
}
