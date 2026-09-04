<?php

namespace App\Http\Requests;

use App\Models\FeedbackChannel;
use App\Services\FeedbackChannelGate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
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

    protected function prepareForValidation(): void
    {
        $this->files->set('attachments', $this->attachmentFiles());
    }

    /**
     * @return list<UploadedFile>
     */
    public function attachmentFiles(): array
    {
        $files = $this->file('attachments', []);
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        $present = [];
        foreach (is_array($files) ? $files : [] as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $present[] = $file;
            }
        }

        return $present;
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
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => [
                'file',
                File::image(false)->types(['jpg', 'jpeg', 'png', 'webp'])->max('5mb')
                    ->dimensions(Rule::dimensions()->maxWidth(6000)->maxHeight(6000)),
            ],
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
