<?php

namespace App\Http\Requests;

use App\Models\FeedbackChannel;
use App\Services\FeedbackChannelGate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class StoreFeedbackTicketRequest extends FormRequest
{
    private ?FeedbackChannel $resolvedChannel = null;

    public function authorize(): bool
    {
        return true;
    }

    public function channel(): FeedbackChannel
    {
        if (! $this->resolvedChannel instanceof FeedbackChannel) {
            $this->resolvedChannel = app(FeedbackChannelGate::class)->forRequest(
                (string) $this->route('code'),
                $this,
                CarbonImmutable::now('Asia/Shanghai'),
            );
        }

        return $this->resolvedChannel;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $channel = $this->channel();
        $contact = $channel->contact_required
            ? ['required', 'string', 'max:80']
            : ['nullable', 'string', 'max:80'];

        return [
            'category' => ['required', 'string', Rule::in((array) $channel->categories)],
            'content' => ['required', 'string', 'min:10', 'max:2000'],
            'contact' => $contact,
            'idempotency_key' => ['required', 'uuid'],
            'privacy_accepted' => ['required', 'accepted'],
            'user_id' => ['prohibited'],
            'status' => ['prohibited'],
            'public_no' => ['prohibited'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
