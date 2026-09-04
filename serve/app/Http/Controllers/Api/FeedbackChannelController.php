<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFeedbackChannelRequest;
use App\Http\Requests\UpdateFeedbackChannelRequest;
use App\Http\Resources\FeedbackChannelResource;
use App\Models\FeedbackChannel;
use App\Services\FeedbackChannelService;
use App\Services\FeedbackDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Ugly\Base\Traits\ApiResource;

class FeedbackChannelController extends Controller
{
    use ApiResource;

    public function __construct(
        private readonly FeedbackChannelService $channels,
        private readonly FeedbackDeliveryService $deliveries,
    ) {}

    public function index(): JsonResponse
    {
        $query = $this->ownedQuery()->with('domain')->orderByDesc('id');

        return $this->paginate(
            $query,
            fn (FeedbackChannel $channel): array => $this->toPublicArray($channel),
        );
    }

    public function store(StoreFeedbackChannelRequest $request): JsonResponse
    {
        $channel = $this->channels->create(auth('api')->user(), $request->validated());

        return $this->success($this->toPublicArray($channel), Response::HTTP_CREATED);
    }

    public function show(int $id): JsonResponse
    {
        $channel = $this->ownedQuery()->with('domain')->findOrFail($id);

        return $this->success($this->toPublicArray($channel));
    }

    public function update(UpdateFeedbackChannelRequest $request, int $id): JsonResponse
    {
        $channel = $this->ownedQuery()->findOrFail($id);
        $channel = $this->channels->update($channel, $request->validated());

        return $this->success($this->toPublicArray($channel));
    }

    public function setStatus(Request $request, int $id): JsonResponse
    {
        $channel = $this->ownedQuery()->findOrFail($id);
        $payload = $request->json()->all();
        if (! is_array($payload) || count($payload) !== 1 || ! array_key_exists('status', $payload) || ! is_bool($payload['status'])) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'status 必须是布尔值且请求只能包含该字段',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $channel = $this->channels->setStatus($channel, $payload['status']);

        return $this->success($this->toPublicArray($channel));
    }

    public function testNotification(int $id): JsonResponse
    {
        $channel = $this->ownedQuery()->findOrFail($id);
        $delivery = $this->deliveries->queueTest($channel);

        return $this->success([
            'kind' => $delivery->kind->value,
            'status' => $delivery->status->value,
        ]);
    }

    private function ownedQuery()
    {
        return FeedbackChannel::query()->where('user_id', auth('api')->id());
    }

    /** @return array<string, mixed> */
    private function toPublicArray(FeedbackChannel $channel): array
    {
        $channel->loadMissing('domain');

        return (new FeedbackChannelResource($channel))->resolve();
    }
}
