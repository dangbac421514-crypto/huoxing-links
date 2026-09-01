<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use Ugly\Base\Traits\ApiResource;

final class FrameworkCompatibilityTest extends TestCase
{
    public function test_framework_and_app_boot_contracts_are_supported(): void
    {
        $this->assertSame(13, (int) explode('.', Application::VERSION)[0]);
        $this->artisan('route:list')->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('personal_access_tokens'));
        $user = User::query()->create([
            'username' => 'compatibility-'.Str::random(16),
            'password' => 'password',
            'status' => true,
            'type' => 1,
        ]);
        $plain = $user->createToken('compatibility')->plainTextToken;
        $this->assertNotSame('', $plain);
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sync', config('queue.default'));
        $this->assertContains(ApiResource::class, class_uses_recursive(AuthController::class));
        $this->artisan('schedule:list')->assertExitCode(0);
        $this->artisan('config:cache')->assertExitCode(0);
        $this->assertNotNull(config('app.key'));
        $this->artisan('config:clear')->assertExitCode(0);
    }
}
