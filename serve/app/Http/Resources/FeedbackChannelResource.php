<?php

namespace App\Http\Resources;

use App\Exceptions\BusinessRuleException;
use App\Services\FeedbackShareUrl;
use App\Support\FeedbackError;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeedbackChannelResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $shareUrl = $this->shareUrlOrNull();

        return [
            'id' => $this->id,
            'domain_id' => $this->domain_id,
            'code' => $this->code,
            'name' => $this->name,
            'operator_name' => $this->operator_name,
            'intro' => $this->intro,
            'service_phone' => $this->service_phone,
            'sla_text' => $this->sla_text,
            'categories' => $this->categories,
            'contact_required' => (bool) $this->contact_required,
            'retention_days' => (int) $this->retention_days,
            'status' => (bool) $this->status,
            'share_url' => $shareUrl,
            'domain_available' => $shareUrl !== null,
            'webhook_configured' => filled($this->resource->webhook_url),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function shareUrlOrNull(): ?string
    {
        try {
            return app(FeedbackShareUrl::class)->for($this->resource);
        } catch (BusinessRuleException $exception) {
            if ($exception->errorCode !== FeedbackError::DOMAIN_UNAVAILABLE) {
                throw $exception;
            }

            return null;
        }
    }
}
