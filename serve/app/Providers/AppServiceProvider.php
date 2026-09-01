<?php

namespace App\Providers;

use App\Contracts\ReferralCodeGenerator;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
