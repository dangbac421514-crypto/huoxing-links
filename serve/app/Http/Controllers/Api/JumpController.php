<?php

namespace App\Http\Controllers\Api;

use App\DTO\CoordinatorResponse;
use App\Http\Controllers\Controller;
use App\Services\LinkResolutionCoordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public HTTP adapter. Link policy, accounting, resolution and sanitization
 * all live in LinkResolutionCoordinator so both endpoints share one path.
 */
final class JumpController extends Controller
{
    public function __construct(private readonly LinkResolutionCoordinator $coordinator) {}

    public function target(Request $request, string $code): JsonResponse
    {
        return $this->respond($this->coordinator->target($request, $code));
    }

    public function getShowQr(Request $request, string $code): JsonResponse
    {
        return $this->respond($this->coordinator->showQr($request, $code));
    }

    private function respond(CoordinatorResponse $result): JsonResponse
    {
        $response = response()->json($result->payload, $result->status);
        if ($result->cookie !== null) {
            $response->withCookie($result->cookie);
        }

        return $response;
    }
}
