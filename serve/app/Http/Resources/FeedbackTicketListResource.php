<?php

namespace App\Http\Resources;

use App\Enums\FeedbackDeliveryKind;
use App\Enums\FeedbackTicketStatus;
use App\Models\FeedbackDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeedbackTicketListResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_no' => $this->public_no,
            'category' => $this->category,
            'status' => $this->status instanceof FeedbackTicketStatus ? $this->status->value : $this->status,
            'channel_id' => $this->feedback_channel_id,
            'channel_name' => $this->whenLoaded('channel', fn () => $this->channel?->name),
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'notification_status' => $this->notificationStatus(),
        ];
    }

    protected function notificationStatus(): ?string
    {
        if (! $this->relationLoaded('deliveries')) {
            return null;
        }

        $delivery = $this->deliveries->first(
            static fn (FeedbackDelivery $delivery): bool => $delivery->kind === FeedbackDeliveryKind::TICKET,
        );

        return $delivery?->status?->value;
    }
}
