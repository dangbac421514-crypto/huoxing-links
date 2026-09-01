<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class FoundationAcceptanceTest extends TestCase
{
    public function test_runtime_initialization_does_not_read_sql_dumps_or_expose_default_credentials(): void
    {
        $source = File::get(base_path('app/Console/Commands/SystemInit.php'));

        $this->assertStringNotContainsString("file_get_contents(database_path('packages.sql'))", $source);
        $this->assertStringNotContainsString("bcrypt('admin123')", $source);
        $this->assertStringContainsString('app:admin-provision', $source);
    }

    public function test_legacy_web_installer_is_removed_from_runtime(): void
    {
        $this->assertFileDoesNotExist(base_path('app/Http/Controllers/Install/IndexController.php'));
        $this->assertFalse(collect(Route::getRoutes())->contains(
            static fn ($route): bool => $route->uri() === 'install',
        ));
    }
}
