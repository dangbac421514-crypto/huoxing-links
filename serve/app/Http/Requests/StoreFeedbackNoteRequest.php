<?php

namespace App\Http\Requests;

use App\Models\FeedbackTicket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFeedbackNoteRequest extends FormRequest
{
    public const NOTE_MIN_LENGTH = 1;

    public const NOTE_MAX_LENGTH = 1000;

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
            'note' => ['required', 'string', 'min:'.self::NOTE_MIN_LENGTH, 'max:'.self::NOTE_MAX_LENGTH],
        ];
    }
}
