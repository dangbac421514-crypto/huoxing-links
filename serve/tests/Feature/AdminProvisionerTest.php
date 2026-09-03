<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use App\Services\AdminProvisioner;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

final class AdminProvisionerTest extends TestCase
{
    public function test_provisioner_creates_an_admin_without_a_default_password(): void
    {
        $admin = app(AdminProvisioner::class)->provision(' owner ', 'correct-horse-battery-staple');

        $this->assertSame('owner', $admin->username);
        $this->assertSame(UserType::Admin, $admin->type);
        $this->assertTrue(Hash::check('correct-horse-battery-staple', $admin->password));
        $this->assertTrue($admin->must_change_password);
        $this->assertDatabaseMissing('users', ['username' => 'admin']);
    }

    public function test_provisioner_rejects_short_passwords(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AdminProvisioner::class)->provision('owner', 'too-short');
    }

    public function test_provisioner_rejects_empty_and_overlong_usernames(): void
    {
        $provisioner = app(AdminProvisioner::class);

        $this->expectException(InvalidArgumentException::class);
        $provisioner->provision('   ', 'correct-horse-battery-staple');
    }

    public function test_provisioner_rejects_overlong_usernames(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AdminProvisioner::class)->provision(str_repeat('a', 256), 'correct-horse-battery-staple');
    }

    public function test_provisioner_rejects_collision_with_non_admin_user(): void
    {
        User::factory()->create(['username' => 'member']);

        $this->expectException(LogicException::class);

        app(AdminProvisioner::class)->provision('member', 'correct-horse-battery-staple');
    }

    public function test_provisioner_can_repeatably_update_an_existing_admin(): void
    {
        $provisioner = app(AdminProvisioner::class);
        $first = $provisioner->provision('owner', 'first-correct-password');
        $second = $provisioner->provision(' owner ', 'second-correct-password');

        $this->assertSame($first->id, $second->id);
        $this->assertTrue(Hash::check('second-correct-password', $second->password));
        $this->assertFalse(Hash::check('first-correct-password', $second->password));
        $this->assertTrue($second->must_change_password);
        $this->assertSame(1, User::query()->where('username', 'owner')->count());
    }

    public function test_command_reads_password_interactively_and_never_outputs_it(): void
    {
        $password = 'command-correct-password';

        $this->artisan('app:admin-provision', ['username' => 'cli-owner'])
            ->expectsQuestion('管理员密码（至少 12 位）', $password)
            ->expectsOutputToContain('cli-owner')
            ->assertExitCode(0)
            ->doesntExpectOutput($password);
    }

    public function test_command_rejects_short_password_without_echoing_it(): void
    {
        $password = 'too-short';

        $this->artisan('app:admin-provision', ['username' => 'cli-owner'])
            ->expectsQuestion('管理员密码（至少 12 位）', $password)
            ->assertExitCode(1)
            ->doesntExpectOutput($password);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_system_init_only_prints_the_new_initialization_commands(): void
    {
        $this->artisan('app:system-init')
            ->expectsOutputToContain('php artisan migrate --seed')
            ->expectsOutputToContain('php artisan app:admin-provision')
            ->assertExitCode(0);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('sys_configs', 0);
    }
}
