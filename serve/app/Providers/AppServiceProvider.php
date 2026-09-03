<?php

namespace App\Providers;

use App\Contracts\DnsResolver;
use App\Contracts\EmailGateway;
use App\Contracts\MiniProgramSchemeClient;
use App\Contracts\MiniProgramSchemeGenerator;
use App\Contracts\ReferralCodeGenerator;
use App\Contracts\SmsGateway;
use App\Services\AliyunSmsGateway;
use App\Services\EasyWechatMiniProgramSchemeClient;
use App\Services\EasyWechatMiniProgramSchemeGenerator;
use App\Services\Http\NativeDnsResolver;
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
        $this->app->singleton(DnsResolver::class, NativeDnsResolver::class);
        $this->app->bind(MiniProgramSchemeClient::class, EasyWechatMiniProgramSchemeClient::class);
        $this->app->bind(MiniProgramSchemeGenerator::class, EasyWechatMiniProgramSchemeGenerator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
