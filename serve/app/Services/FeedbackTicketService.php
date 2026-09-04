<?php

namespace App\Services;

use App\Enums\FeedbackTicketStatus;
use App\Models\FeedbackEvent;
use App\Models\FeedbackTicket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FeedbackTicketService
{
    public const EVENT_STATUS_CHANGED = 'status_changed';

    public const EVENT_NOTE_ADDED = 'note_added';

    public function transition(FeedbackTicket $ticket, FeedbackTicketStatus $to, int $actorId): FeedbackTicket
    {
        $from = $ticket->status instanceof FeedbackTicketStatus
            ? $ticket->status
            : FeedbackTicketStatus::from((string) $ticket->status);
        FeedbackTicketStateMachine::assertTransition($from, $to);

        return DB::transaction(function () use ($ticket, $from, $to, $actorId): FeedbackTicket {
            $ticket->status = $to;
            if ($to === FeedbackTicketStatus::RESOLVED) {
                $ticket->resolved_at = CarbonImmutable::now();
            } elseif ($from === FeedbackTicketStatus::RESOLVED && $to === FeedbackTicketStatus::PROCESSING) {
                $ticket->resolved_at = null;
            }
            $ticket->save();

            $this->writeEvent(
                $ticket,
                $actorId,
                self::EVENT_STATUS_CHANGED,
                $from->value,
                $to->value,
                null,
            );

            return $ticket->fresh() ?? $ticket;
        });
    }

    public function addNote(FeedbackTicket $ticket, string $note, int $actorId): FeedbackTicket
    {
        return DB::transaction(function () use ($ticket, $note, $actorId): FeedbackTicket {
            $this->writeEvent(
                $ticket,
                $actorId,
                self::EVENT_NOTE_ADDED,
                null,
                null,
                $note,
            );

            return $ticket->fresh() ?? $ticket;
        });
    }

    private function writeEvent(
        FeedbackTicket $ticket,
        int $actorId,
        string $event,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $note,
    ): void {
        FeedbackEvent::query()->create([
            'feedback_ticket_id' => $ticket->id,
            'user_id' => $ticket->user_id,
            'actor_user_id' => $actorId,
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'created_at' => CarbonImmutable::now(),
        ]);
    }
}
