<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;
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

    public function test_git_index_has_no_legacy_sql_dumps_or_default_credentials(): void
    {
        $repository = dirname(base_path());
        $tracked = Process::fromShellCommandline('git -C '.escapeshellarg($repository).' ls-files -z')
            ->mustRun()
            ->getOutput();

        $this->assertStringNotContainsString("serve/database/base.sql\0", $tracked);
        $this->assertStringNotContainsString("serve/database/packages.sql\0", $tracked);

        $scanProcess = Process::fromShellCommandline(
            'git -C '.escapeshellarg($repository).' grep --cached -n -I -E '.escapeshellarg('admin123|\\$2y\\$12\\$DQNA/1BJcPdmpJ9ylifXHO9X.RDaovOj4SHL5M6S/vr9lvnFND5Gy').' -- deploy.sh serve/app serve/config serve/database/seeders admin/src admin/public jump mini_programs README.md',
        );
        $exitCode = $scanProcess->run();
        $this->assertSame(1, $exitCode, $scanProcess->getOutput());
    }
}
