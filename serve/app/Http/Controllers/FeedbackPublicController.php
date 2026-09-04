<?php

namespace App\Http\Controllers;

use App\Services\FeedbackChannelGate;
use App\Services\VisitorIdentityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FeedbackPublicController extends Controller
{
    public function __construct(
        private readonly FeedbackChannelGate $gate,
        private readonly VisitorIdentityService $visitors,
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
}
