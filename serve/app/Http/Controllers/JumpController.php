<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\View\View;

class JumpController extends Controller
{
    public function douyinReview(): Response
    {
        return response()
            ->view('jump/douyin-review')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header(
                'Content-Security-Policy',
                "default-src 'self'; img-src 'self'; style-src 'unsafe-inline'; script-src 'none'; connect-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
            );
    }

    public function show(string $code): View
    {
        return view('jump/wechat');
    }

    public function qr(string $code): Response
    {
        return response()
            ->view('jump/qr', ['code' => $code])
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header(
                'Content-Security-Policy',
                "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            );
    }
}
