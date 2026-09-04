<?php

namespace App\Http\Resources;

use App\Models\FeedbackAttachment;
use App\Models\FeedbackEvent;
use Illuminate\Http\Request;

class FeedbackTicketResource extends FeedbackTicketListResource
{
    public const FALLBACK_ATTACHMENT_NAME = 'attachment';

    public static function safeOriginalName(?string $name): string
    {
        $safe = str_replace(["\r", "\n", '/', '\\', "\0"], '', (string) $name);
        $safe = trim($safe);

        return $safe === '' ? self::FALLBACK_ATTACHMENT_NAME : $safe;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'contact' => $this->contact,
            'content' => $this->content,
            'resolved_at' => $this->resolved_at?->format('Y-m-d H:i:s'),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(
                fn (FeedbackAttachment $attachment): array => $this->attachmentPayload($attachment),
            )->values()->all()),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(
                fn (FeedbackEvent $event): array => [
                    'id' => $event->id,
                    'event' => $event->event,
                    'from_status' => $event->from_status,
                    'to_status' => $event->to_status,
                    'note' => $event->note,
                    'actor_user_id' => $event->actor_user_id,
                    'created_at' => $event->created_at?->format('Y-m-d H:i:s'),
                ],
            )->values()->all()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attachmentPayload(FeedbackAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'original_name' => self::safeOriginalName($attachment->original_name),
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'download_url' => route('feedback-attachments.download', ['id' => $attachment->id]),
        ];
    }
}
