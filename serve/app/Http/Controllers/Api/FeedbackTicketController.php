<?php

namespace App\Http\Controllers\Api;

use App\Enums\FeedbackTicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFeedbackNoteRequest;
use App\Http\Requests\UpdateFeedbackTicketStatusRequest;
use App\Http\Resources\FeedbackTicketListResource;
use App\Http\Resources\FeedbackTicketResource;
use App\Models\FeedbackTicket;
use App\Services\FeedbackTicketService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ugly\Base\Traits\ApiResource;

class FeedbackTicketController extends Controller
{
    use ApiResource;

    public function __construct(
        private readonly FeedbackTicketService $tickets,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->ownedQuery()
            ->with(['channel', 'deliveries'])
            ->orderByDesc('id');
        $this->applyFilters($query, $request);

        return $this->paginate(
            $query,
            fn (FeedbackTicket $ticket): array => (new FeedbackTicketListResource($ticket))->resolve(),
        );
    }

    public function show(int $id): JsonResponse
    {
        $ticket = $this->ownedQuery()->findOrFail($id);

        return $this->success($this->toDetailArray($ticket));
    }

    public function setStatus(UpdateFeedbackTicketStatusRequest $request, int $id): JsonResponse
    {
        $ticket = $this->ownedQuery()->findOrFail($id);
        $to = FeedbackTicketStatus::from($request->validated('status'));
        $ticket = $this->tickets->transition($ticket, $to, (int) auth('api')->id());

        return $this->success($this->toDetailArray($ticket));
    }

    public function addNote(StoreFeedbackNoteRequest $request, int $id): JsonResponse
    {
        $ticket = $this->ownedQuery()->findOrFail($id);
        $ticket = $this->tickets->addNote($ticket, $request->validated('note'), (int) auth('api')->id());

        return $this->success($this->toDetailArray($ticket));
    }

    private function ownedQuery(): Builder
    {
        return FeedbackTicket::query()->where('user_id', auth('api')->id());
    }

    /** @return array<string, mixed> */
    private function toDetailArray(FeedbackTicket $ticket): array
    {
        $ticket->load([
            'channel',
            'attachments',
            'events' => static fn (Relation $relation) => $relation->orderBy('id'),
            'deliveries',
        ]);

        return (new FeedbackTicketResource($ticket))->resolve();
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('channel_id')) {
            $query->where('feedback_channel_id', $request->integer('channel_id'));
        } elseif ($request->filled('feedback_channel_id')) {
            $query->where('feedback_channel_id', $request->integer('feedback_channel_id'));
        }
        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }
        if (! $request->filled('date')) {
            return;
        }

        $date = $request->string('date')->toString();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return;
        }

        $timezone = (string) config('app.timezone');
        $start = CarbonImmutable::createFromFormat('Y-m-d', $date, $timezone);
        if (! $start instanceof CarbonImmutable) {
            return;
        }
        $query->whereBetween('submitted_at', [$start->startOfDay(), $start->endOfDay()]);
    }
}
