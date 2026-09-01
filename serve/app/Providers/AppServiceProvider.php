<?php

namespace App\Providers;

use App\Contracts\EmailGateway;
use App\Contracts\ReferralCodeGenerator;
use App\Contracts\SmsGateway;
use App\Services\AliyunSmsGateway;
use App\Services\LaravelMailGateway;
use App\Services\RandomReferralCodeGenerator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ReferralCodeGenerator::class, RandomReferralCodeGenerator::class);
        $this->app->bind(SmsGateway::class, AliyunSmsGateway::class);
        $this->app->bind(EmailGateway::class, LaravelMailGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
