<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FeedbackTicketResource;
use App\Models\FeedbackAttachment;
use App\Services\FeedbackAttachmentService;
use App\Support\FeedbackError;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class FeedbackAttachmentController extends Controller
{
    public function download(int $id): Response
    {
        $attachment = FeedbackAttachment::query()
            ->where('user_id', auth('api')->id())
            ->findOrFail($id);

        if ($attachment->disk !== FeedbackAttachmentService::DISK) {
            return $this->unavailable();
        }

        $disk = Storage::disk(FeedbackAttachmentService::DISK);
        if (! $disk->exists($attachment->path)) {
            return $this->unavailable();
        }

        $name = FeedbackTicketResource::safeOriginalName((string) $attachment->original_name);

        return $disk->download($attachment->path, $name, [
            'Content-Type' => $attachment->mime,
        ]);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'code' => FeedbackError::ATTACHMENT_UNAVAILABLE,
            'message' => '附件不可用',
        ], 404);
    }
}
