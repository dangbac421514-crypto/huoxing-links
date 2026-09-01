<?php

namespace Tests\Feature;

use Illuminate\Foundation\Application;
use Tests\TestCase;

final class Laravel12CompatibilityTest extends TestCase
{
    public function test_laravel_twelve_or_newer_boots_with_routes_and_config_cache(): void
    {
        $this->assertGreaterThanOrEqual(12, (int) explode('.', Application::VERSION)[0]);
        $this->artisan('route:list')->assertExitCode(0);
        $this->artisan('config:cache')->assertExitCode(0);
        $this->artisan('config:clear')->assertExitCode(0);
    }
}
