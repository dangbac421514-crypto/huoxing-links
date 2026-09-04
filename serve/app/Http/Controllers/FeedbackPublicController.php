<?php

namespace App\Http\Controllers;

use App\DTO\FeedbackSubmissionData;
use App\Http\Requests\StoreFeedbackTicketRequest;
use App\Services\FeedbackChannelGate;
use App\Services\FeedbackSubmissionService;
use App\Services\VisitorIdentityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FeedbackPublicController extends Controller
{
    public function __construct(
        private readonly FeedbackChannelGate $gate,
        private readonly VisitorIdentityService $visitors,
        private readonly FeedbackSubmissionService $submissions,
    ) {}

    public function show(Request $request, string $code): Response
    {
        $channel = $this->gate->forRequest(
            $code,
            $request,
            CarbonImmutable::now('Asia/Shanghai'),
        );
        $identity = $this->visitors->resolve($request);

        return response()
            ->view('feedback.show', ['channel' => $channel])
            ->withCookie($this->visitors->cookie($identity));
    }

    public function store(StoreFeedbackTicketRequest $request): Response
    {
        $identity = $this->visitors->resolve($request);
        $validated = $request->validated();
        $result = $this->submissions->submit(
            $request->channel(),
            new FeedbackSubmissionData(
                $validated['category'],
                $validated['content'],
                $validated['contact'] ?? null,
                $validated['idempotency_key'],
                $identity->hash,
                $request->attachmentFiles(),
            ),
        );

        $response = response()->json(
            ['public_no' => $result->ticket->public_no],
            $result->created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
        if ($identity->setCookie) {
            $response->withCookie($this->visitors->cookie($identity));
        }

        return $response;
    }
}
