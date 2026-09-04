<?php

namespace App\Providers;

use App\Services\FeedbackAbuseKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    private const FEEDBACK_SUBMIT_LIMITER = 'feedback-submit';

    private const FEEDBACK_VISITOR_BURST_MAX = 3;

    private const FEEDBACK_VISITOR_BURST_MINUTES = 10;

    private const FEEDBACK_VISITOR_DAILY_MAX = 10;

    private const FEEDBACK_CHANNEL_PER_MINUTE = 60;

    private const FEEDBACK_RATE_LIMITED_MESSAGE = '请求过于频繁，请稍后再试。';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for(self::FEEDBACK_SUBMIT_LIMITER, function (Request $request) {
            $visitorKey = app(FeedbackAbuseKey::class)->forRequest($request);
            $channelKey = 'feedback-channel:'.(string) $request->route('code');
            $tooMany = static function (Request $request, array $headers) {
                return response()->json([
                    'message' => self::FEEDBACK_RATE_LIMITED_MESSAGE,
                ], Response::HTTP_TOO_MANY_REQUESTS, $headers);
            };

            return [
                Limit::perMinutes(self::FEEDBACK_VISITOR_BURST_MINUTES, self::FEEDBACK_VISITOR_BURST_MAX)
                    ->by($visitorKey.':burst')
                    ->response($tooMany),
                Limit::perDay(self::FEEDBACK_VISITOR_DAILY_MAX)
                    ->by($visitorKey.':day')
                    ->response($tooMany),
                Limit::perMinute(self::FEEDBACK_CHANNEL_PER_MINUTE)
                    ->by($channelKey)
                    ->response($tooMany),
            ];
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
