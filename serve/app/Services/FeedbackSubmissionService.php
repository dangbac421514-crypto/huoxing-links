<?php

namespace App\Services;

use App\DTO\FeedbackSubmissionData;
use App\DTO\FeedbackSubmissionResult;
use App\Enums\FeedbackTicketStatus;
use App\Models\FeedbackChannel;
use App\Models\FeedbackEvent;
use App\Models\FeedbackTicket;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class FeedbackSubmissionService
{
    private const PUBLIC_NO_LENGTH = 16;

    private const MAX_PUBLIC_NO_ATTEMPTS = 8;

    private const SUBMITTED_EVENT = 'submitted';

    private const IDEMPOTENCY_INDEX = 'feedback_tickets_feedback_channel_id_idempotency_key_unique';

    private const PUBLIC_NO_INDEX = 'feedback_tickets_public_no_unique';

    public function __construct(private readonly FeedbackAttachmentService $attachments) {}

    public function submit(FeedbackChannel $channel, FeedbackSubmissionData $data): FeedbackSubmissionResult
    {
        $existing = $this->findByIdempotency($channel, $data->idempotencyKey);
        if ($existing instanceof FeedbackTicket) {
            return new FeedbackSubmissionResult($existing, false);
        }

        $tracked = [];
        try {
            return DB::transaction(function () use ($channel, $data, &$tracked): FeedbackSubmissionResult {
                $ticket = $this->createTicket($channel, $data);
                $this->writeSubmittedEvent($ticket);
                $stored = $this->attachments->storeForTicket($ticket, $this->attachmentFiles($data));
                $tracked = $stored->pluck('path')->all();

                return new FeedbackSubmissionResult($ticket, true);
            });
        } catch (QueryException $exception) {
            $this->attachments->deleteStored($tracked);
            if (! $this->isDuplicateKey($exception, self::IDEMPOTENCY_INDEX)) {
                throw $exception;
            }

            $existing = $this->findByIdempotency($channel, $data->idempotencyKey);
            if (! $existing instanceof FeedbackTicket) {
                throw $exception;
            }

            return new FeedbackSubmissionResult($existing, false);
        } catch (Throwable $exception) {
            $this->attachments->deleteStored($tracked);
            throw $exception;
        }
    }

    /**
     * @return list<UploadedFile>
     */
    private function attachmentFiles(FeedbackSubmissionData $data): array
    {
        $files = array_values(array_filter(
            $data->attachments,
            static fn (mixed $file): bool => $file instanceof UploadedFile && $file->isValid(),
        ));
        if ($files !== []) {
            return $files;
        }

        $fromRequest = request()->file('attachments', []);
        if ($fromRequest instanceof UploadedFile) {
            $fromRequest = [$fromRequest];
        }

        return array_values(array_filter(
            is_array($fromRequest) ? $fromRequest : [],
            static fn (mixed $file): bool => $file instanceof UploadedFile && $file->isValid(),
        ));
    }

    private function createTicket(FeedbackChannel $channel, FeedbackSubmissionData $data): FeedbackTicket
    {
        $submittedAt = CarbonImmutable::now('Asia/Shanghai');
        $attributes = [
            'user_id' => $channel->user_id,
            'feedback_channel_id' => $channel->id,
            'category' => $data->category,
            'status' => FeedbackTicketStatus::PENDING,
            'contact' => $data->contact,
            'content' => $data->content,
            'visitor_hash' => $data->visitorHash,
            'idempotency_key' => $data->idempotencyKey,
            'submitted_at' => $submittedAt,
        ];

        for ($attempt = 0; $attempt < self::MAX_PUBLIC_NO_ATTEMPTS; $attempt++) {
            $attributes['public_no'] = 'FB-'.Str::upper(Str::random(self::PUBLIC_NO_LENGTH));
            try {
                return FeedbackTicket::query()->create($attributes);
            } catch (QueryException $exception) {
                if ($this->isDuplicateKey($exception, self::IDEMPOTENCY_INDEX)
                    || ! $this->isDuplicateKey($exception, self::PUBLIC_NO_INDEX)
                    || $attempt === self::MAX_PUBLIC_NO_ATTEMPTS - 1) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('客诉工单创建失败');
    }

    private function writeSubmittedEvent(FeedbackTicket $ticket): void
    {
        FeedbackEvent::query()->create([
            'feedback_ticket_id' => $ticket->id,
            'user_id' => $ticket->user_id,
            'actor_user_id' => null,
            'event' => self::SUBMITTED_EVENT,
            'from_status' => null,
            'to_status' => FeedbackTicketStatus::PENDING->value,
            'note' => null,
            'created_at' => CarbonImmutable::now('Asia/Shanghai'),
        ]);
    }

    private function findByIdempotency(FeedbackChannel $channel, string $idempotencyKey): ?FeedbackTicket
    {
        return FeedbackTicket::query()
            ->where('feedback_channel_id', $channel->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function isDuplicateKey(QueryException $exception, string $index): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062
            && str_contains((string) ($exception->errorInfo[2] ?? $exception->getMessage()), $index);
    }
}
