<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Domain;
use App\Models\FeedbackChannel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class FeedbackChannelGate
{
    public function __construct(
        private readonly ShareOriginPolicy $origins,
        private readonly EntitlementService $entitlements,
    ) {}

    public function forRequest(string $code, Request $request, CarbonImmutable $at): FeedbackChannel
    {
        $channel = FeedbackChannel::query()
            ->with(['domain', 'user.vipPackage'])
            ->where('code', $code)
            ->first();
        if (! $channel instanceof FeedbackChannel) {
            abort(404);
        }

        $domain = $channel->domain;
        if (! $domain instanceof Domain) {
            abort(404);
        }

        $originHost = $this->origins->hostFor($domain);
        $expectedHost = $originHost ?? $this->hostFromUrl((string) $domain->url);
        if ($expectedHost === '' || $this->requestHost($request) !== $expectedHost) {
            abort(404);
        }
        if ($originHost === null) {
            $this->unavailable();
        }
        if (! (bool) $channel->status) {
            $this->unavailable();
        }

        $owner = $channel->user;
        if (! $owner instanceof User || ! (bool) $owner->getAttribute('status')) {
            $this->unavailable();
        }

        try {
            $this->entitlements->assertActive($owner, $at);
        } catch (BusinessRuleException) {
            $this->unavailable();
        }

        return $channel;
    }

    private function unavailable(): never
    {
        abort(response()->view('feedback.unavailable'));
    }

    private function requestHost(Request $request): string
    {
        return strtolower($request->getHost());
    }

    private function hostFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }
}
