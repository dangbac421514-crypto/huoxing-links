<?php

declare(strict_types=1);

use App\Enums\UserType;
use App\Models\Domain;
use App\Models\FeedbackChannel;
use App\Models\User;
use App\Models\VipPackage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\TestEnvironmentGuard;

require __DIR__.'/../../vendor/autoload.php';

TestEnvironmentGuard::assertSafe();

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

TestEnvironmentGuard::assertSafe();

$migrated = Artisan::call('migrate:fresh', [
    '--seed' => true,
    '--force' => true,
]);
if ($migrated !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(1);
}

$package = VipPackage::query()->where('level', '>=', 1)->orderBy('id')->first();
if (! $package instanceof VipPackage) {
    fwrite(STDERR, "E2E seed requires an active membership package\n");
    exit(1);
}

$username = '13800138000';
$password = bin2hex(random_bytes(16));
$start = CarbonImmutable::now('Asia/Shanghai')->subMinute();

$user = User::query()->create([
    'username' => $username,
    'password' => Hash::make($password),
    'status' => true,
    'type' => UserType::MEMBER,
    'must_change_password' => false,
    'vip_id' => $package->id,
    'start_at' => $start,
    'end_at' => $start->addMonth(),
]);

$domain = Domain::query()->create([
    'url' => 'https://127.0.0.1',
    'title' => '127.0.0.1',
    'enable' => true,
]);

$channel = FeedbackChannel::query()->create([
    'user_id' => $user->id,
    'domain_id' => $domain->id,
    'code' => Str::lower(Str::random(24)),
    'status' => true,
    'name' => 'E2E客诉渠道',
    'operator_name' => 'E2E运营主体',
    'intro' => '浏览器端客诉受理验收',
    'sla_text' => '2小时内响应',
    'categories' => ['售前承诺', '订单履约', '退款售后', '服务态度', '产品问题', '其他'],
    'contact_required' => false,
    'retention_days' => 180,
]);

echo json_encode([
    'username' => $username,
    'password' => $password,
    'channel_code' => $channel->code,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
