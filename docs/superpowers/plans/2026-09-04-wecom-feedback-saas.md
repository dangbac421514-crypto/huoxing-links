# 企业微信客诉受理 SaaS Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在现有 Laravel 13 链接平台中交付多租户商家客诉渠道、公开反馈表单、私有附件、工单后台和企业微信群机器人通知。

**Architecture:** 客诉渠道是独立业务聚合，不新增 `LinkType`，也不进入 `LinkResolutionCoordinator`。它复用现有用户、会员、域名、访客 Cookie、认证、队列和分享域名校验；公开页以固定 `/f/{code}` 路由接收工单，管理端继续使用现有 Vue 3 + Element Plus。

**Tech Stack:** PHP 8.3、Laravel 13.29、MySQL 8、Redis、Laravel Filesystem/HTTP Client/Queue/RateLimiter、Vue 3、TypeScript、Element Plus、Playwright 1.62.1。

**Spec:** `docs/superpowers/specs/2026-09-04-wecom-feedback-saas-design.md`

## Global Constraints

- 基线固定为 `codex/full-function-implementation@c8c064926af7d5d32c958306ca3f2be68a4021c1`，任务分支为 `codex/wecom-feedback-saas`。
- 不新增 `LinkType`，不让客诉提交消耗普通链接 UV，不改 `LinkResolutionCoordinator` 的目标解析职责。
- 分享地址固定为 `https://{selected-host}/f/{code}`；渠道必须绑定一个现有启用域名，域名失效时不自动轮换。
- 所有普通 API（管理员也一样）只读取当前登录账号自己的 `user_id` 数据；首版不存在跨租户接口。
- 页面文案固定表达“商家售后反馈/客诉受理”，禁止仿冒企业微信官方投诉、变体字举报或“防投诉/降低封号率”承诺。
- 联系方式、正文、内部备注、Webhook 和附件原名使用应用层加密；Webhook 永不回显或写日志。
- 原始 IP/User-Agent 不持久化；限流键使用 `APP_FEEDBACK_HASH_KEY` 做 HMAC。
- 附件最多 3 张、单张 5 MiB，只接收 JPEG/PNG/WebP，存入私有磁盘且只能通过租户授权下载。
- 通知仅含运营主体、工单号、分类、提交时间和后台链接，不含联系方式、正文或附件。
- 不增加完整工单系统生产依赖；只增加 Playwright 开发依赖。
- 生产发布和真实企业微信配置必须在本地/测试验收通过后再次取得用户确认。
- 每个任务只暂存列出的文件，先通过该任务测试与 `git diff --check` 再提交。

## Execution preflight

Run:

```bash
git status -sb
git rev-parse --verify HEAD
git rev-parse --verify origin/codex/wecom-feedback-saas
git diff --exit-code origin/codex/wecom-feedback-saas...HEAD
docker compose -f compose.test.yaml up -d --wait
composer install --working-dir=serve --no-interaction
npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm --dir admin install --frozen-lockfile
```

Expected: worktree clean；本地与远端任务分支指向同一提交；MySQL/Redis healthy；依赖安装只生成被忽略的 `vendor/node_modules`，不改锁文件。

---

### Task 1: 抽出共享域名源策略

**Files:**
- Create: `serve/app/Services/ShareOriginPolicy.php`
- Modify: `serve/app/Services/LinkShareUrl.php`
- Create: `serve/tests/Unit/Services/ShareOriginPolicyTest.php`
- Modify: `serve/tests/Unit/Services/LinkShareUrlTest.php`

**Interfaces:**
- Consumes: `Domain`, `config('app.public_origin')`, `config('app.allowed_share_hosts')`。
- Produces: `ShareOriginPolicy::forDomain(Domain $domain): ?string`、`ShareOriginPolicy::publicOrigin(): ?string`、`ShareOriginPolicy::hostFor(Domain $domain): ?string`；`LinkShareUrl` 保持 `for(Link $link): string` 不变。

- [ ] **Step 1: Write the failing policy tests**

```php
public function test_domain_origin_requires_enabled_exact_allowlisted_https_origin(): void
{
    config(['app.allowed_share_hosts' => ['share.example']]);
    $valid = Domain::query()->create(['title' => 'share', 'url' => 'https://SHARE.example/', 'enable' => true]);
    $this->assertSame('https://share.example', app(ShareOriginPolicy::class)->forDomain($valid));
    foreach (['http://share.example', 'https://user@share.example', 'https://share.example/path', 'https://share.example:8443'] as $url) {
        $valid->forceFill(['url' => $url])->save();
        $this->assertNull(app(ShareOriginPolicy::class)->forDomain($valid->fresh()));
    }
}

public function test_disabled_or_unlisted_domain_has_no_origin(): void
{
    config(['app.allowed_share_hosts' => ['other.example']]);
    $domain = Domain::query()->create(['title' => 'share', 'url' => 'https://share.example', 'enable' => false]);
    $this->assertNull(app(ShareOriginPolicy::class)->forDomain($domain));
}
```

- [ ] **Step 2: Run tests to verify red**

Run: `serve/bin/test-env php artisan test tests/Unit/Services/ShareOriginPolicyTest.php tests/Unit/Services/LinkShareUrlTest.php`

Expected: FAIL because `ShareOriginPolicy` does not exist.

- [ ] **Step 3: Implement the shared parser and refactor LinkShareUrl**

```php
final class ShareOriginPolicy
{
    public function forDomain(Domain $domain): ?string
    {
        return (bool) $domain->enable ? $this->normalize((string) $domain->url) : null;
    }

    public function publicOrigin(): ?string
    {
        return $this->normalize((string) config('app.public_origin', ''));
    }

    public function hostFor(Domain $domain): ?string
    {
        $origin = $this->forDomain($domain);
        return $origin === null ? null : parse_url($origin, PHP_URL_HOST);
    }
}
```

`normalize()` 搬运现有 `LinkShareUrl::validatedOrigin()` 的全部校验：HTTPS、无 userinfo/query/fragment、端口只允许缺省或 443、path 只能空或 `/`、host 必须精确位于 `ALLOWED_SHARE_HOSTS`。`LinkShareUrl` 注入该策略，先选启用域名，失败再回退 `publicOrigin()`；原有错误码和分享地址测试必须保持不变。

- [ ] **Step 4: Run focused and regression tests**

Run: `serve/bin/test-env php artisan test tests/Unit/Services/ShareOriginPolicyTest.php tests/Unit/Services/LinkShareUrlTest.php tests/Feature/LinkCrudTest.php`

Expected: PASS；六类普通链接分享地址行为无变化。

- [ ] **Step 5: Commit**

```bash
git add serve/app/Services/ShareOriginPolicy.php serve/app/Services/LinkShareUrl.php serve/tests/Unit/Services/ShareOriginPolicyTest.php serve/tests/Unit/Services/LinkShareUrlTest.php
git diff --cached --check
git commit -m "refactor: share validated origin policy"
```

### Task 2: 建立客诉数据模型、密钥和私有磁盘

**Files:**
- Create: `serve/database/migrations/2026_09_04_000201_create_feedback_tables.php`
- Create: `serve/app/Enums/FeedbackTicketStatus.php`
- Create: `serve/app/Enums/FeedbackDeliveryStatus.php`
- Create: `serve/app/Enums/FeedbackDeliveryKind.php`
- Create: `serve/app/Support/FeedbackError.php`
- Create: `serve/app/Models/FeedbackChannel.php`
- Create: `serve/app/Models/FeedbackTicket.php`
- Create: `serve/app/Models/FeedbackAttachment.php`
- Create: `serve/app/Models/FeedbackEvent.php`
- Create: `serve/app/Models/FeedbackDelivery.php`
- Modify: `serve/config/app.php`
- Modify: `serve/config/filesystems.php`
- Modify: `serve/.env.example`
- Modify: `serve/phpunit.xml`
- Modify: `serve/bin/test-env`
- Modify: `serve/tests/Support/TestEnvironmentGuard.php`
- Create: `serve/tests/Feature/FeedbackSchemaTest.php`

**Interfaces:**
- Produces: five Eloquent models; string-backed status/kind enums; `feedback_private` disk; `config('app.feedback_hash_key')`。

- [ ] **Step 1: Write failing schema and encryption tests**

```php
public function test_feedback_schema_has_tenant_keys_and_idempotency_constraints(): void
{
    $this->assertTrue(Schema::hasColumns('feedback_channels', ['user_id', 'domain_id', 'code', 'webhook_url']));
    $this->assertTrue(Schema::hasColumns('feedback_tickets', ['user_id', 'feedback_channel_id', 'public_no', 'idempotency_key', 'anonymized_at']));
    $this->assertTrue(Schema::hasColumns('feedback_attachments', ['user_id', 'feedback_ticket_id', 'original_name', 'sha256']));
    $this->assertTrue(Schema::hasColumns('feedback_events', ['feedback_ticket_id', 'actor_user_id', 'note']));
    $this->assertTrue(Schema::hasColumns('feedback_deliveries', ['feedback_channel_id', 'feedback_ticket_id', 'kind', 'idempotency_key']));
}

public function test_feedback_personal_fields_are_encrypted_and_hidden(): void
{
    $ticket = $this->feedbackTicketWithSecrets('contact-secret', 'content-secret');
    $raw = DB::table('feedback_tickets')->where('id', $ticket->id)->first();
    $this->assertNotSame('contact-secret', $raw->contact);
    $this->assertNotSame('content-secret', $raw->content);
    $this->assertStringNotContainsString('contact-secret', $ticket->toJson());
}
```

`FeedbackSchemaTest::feedbackTicketWithSecrets(string $contact, string $content): FeedbackTicket` 是测试类私有 helper：依次创建启用的 `User`、`Domain`、`FeedbackChannel`，再以传入明文创建 `FeedbackTicket` 并返回 fresh model；不向生产模型添加测试方法。

- [ ] **Step 2: Run tests to verify red**

Run: `serve/bin/test-env php artisan test tests/Feature/FeedbackSchemaTest.php tests/Feature/TestingIsolationTest.php`

Expected: FAIL because tables, models and `APP_FEEDBACK_HASH_KEY` do not exist.

- [ ] **Step 3: Create exact schema and model casts**

Migration contract:

```php
Schema::create('feedback_channels', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('user_id')->constrained()->restrictOnDelete();
    $table->foreignId('domain_id')->constrained()->restrictOnDelete();
    $table->char('code', 24)->unique();
    $table->boolean('status')->default(true);
    $table->string('name', 80);
    $table->string('operator_name', 80);
    $table->string('intro', 500)->default('');
    $table->string('service_phone', 32)->nullable();
    $table->string('sla_text', 80);
    $table->json('categories');
    $table->boolean('contact_required')->default(false);
    $table->unsignedSmallInteger('retention_days')->default(180);
    $table->text('webhook_url')->nullable();
    $table->timestamp('webhook_configured_at')->nullable();
    $table->timestamps();
    $table->index(['user_id', 'status']);
});

Schema::create('feedback_tickets', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('user_id')->constrained()->restrictOnDelete();
    $table->foreignId('feedback_channel_id')->constrained()->restrictOnDelete();
    $table->string('public_no', 19)->unique();
    $table->string('category', 40);
    $table->string('status', 20)->default('pending');
    $table->text('contact')->nullable();
    $table->text('content')->nullable();
    $table->char('visitor_hash', 64)->nullable();
    $table->char('idempotency_key', 36);
    $table->timestamp('submitted_at');
    $table->timestamp('resolved_at')->nullable();
    $table->timestamp('anonymized_at')->nullable();
    $table->timestamps();
    $table->unique(['feedback_channel_id', 'idempotency_key']);
    $table->index(['user_id', 'status', 'submitted_at']);
});
```

The same migration creates:

- `feedback_attachments`: tenant/ticket foreign keys, `disk(40)`, `path(255)`, encrypted `original_name TEXT`, `mime(80)`, unsigned `size`, `sha256 CHAR(64)`, timestamps and `(feedback_ticket_id, sha256)` index.
- `feedback_events`: ticket/user foreign keys, nullable actor with `nullOnDelete()`, `event(40)`, nullable `from_status/to_status(20)`, encrypted nullable `note TEXT`, `created_at`, `(feedback_ticket_id, created_at)` index.
- `feedback_deliveries`: channel/user foreign keys, nullable ticket with `nullOnDelete()`, `kind(20)`, `channel(20)` default `wecom`, `status(20)`, attempts, nullable retry/error/sent timestamps, unique `idempotency_key(80)`, timestamps.

Model casts are exact: channel `status/contact_required` boolean, `categories` array, `retention_days` integer, `webhook_url` encrypted and hidden; ticket `status` enum, `contact/content` encrypted and hidden, timestamps datetime; attachment `original_name` encrypted and hidden; event `note` encrypted and hidden; delivery `kind/status` enums and timestamps datetime.

Relationship names are fixed: `FeedbackChannel::user/domain/tickets/deliveries`；`FeedbackTicket::user/channel/attachments/events/deliveries`；`FeedbackAttachment::user/ticket`；`FeedbackEvent::user/actor/ticket`；`FeedbackDelivery::user/channel/ticket`。`FeedbackError` defines `CHANNEL_NOT_FOUND`、`CHANNEL_DISABLED`、`DOMAIN_UNAVAILABLE`、`INVALID_STATE`、`WEBHOOK_INVALID`、`NOTIFICATION_UNCONFIGURED`、`NOTIFICATION_FAILED`、`ATTACHMENT_UNAVAILABLE`。

Add `feedback_private` disk rooted at `env('FEEDBACK_PRIVATE_ROOT') ?: storage_path('app/private/feedback')` with `throw => true`, so a blank example value cannot resolve to the project directory. Add empty `APP_FEEDBACK_HASH_KEY=` and `FEEDBACK_PRIVATE_ROOT=` to `.env.example`; add a 32+ character test key to phpunit/test wrapper and require it in `TestEnvironmentGuard`. `FeedbackSchemaTest` asserts the resolved default root is exactly `storage_path('app/private/feedback')` when the environment value is blank.

- [ ] **Step 4: Run schema, rollback and isolation tests**

Run: `serve/bin/test-env php artisan migrate:fresh --seed && serve/bin/test-env php artisan test tests/Feature/FeedbackSchemaTest.php tests/Feature/TestingIsolationTest.php tests/Feature/DatabaseSchemaTest.php`

Expected: PASS；`down()` drops child tables before parents and never narrows encrypted columns.

- [ ] **Step 5: Commit**

```bash
git add serve/database/migrations/2026_09_04_000201_create_feedback_tables.php serve/app/Enums/FeedbackTicketStatus.php serve/app/Enums/FeedbackDeliveryStatus.php serve/app/Enums/FeedbackDeliveryKind.php serve/app/Support/FeedbackError.php serve/app/Models/FeedbackChannel.php serve/app/Models/FeedbackTicket.php serve/app/Models/FeedbackAttachment.php serve/app/Models/FeedbackEvent.php serve/app/Models/FeedbackDelivery.php serve/config/app.php serve/config/filesystems.php serve/.env.example serve/phpunit.xml serve/bin/test-env serve/tests/Support/TestEnvironmentGuard.php serve/tests/Feature/FeedbackSchemaTest.php
git diff --cached --check
git commit -m "feat: add encrypted feedback data model"
```

### Task 3: 实现客诉渠道和固定分享地址 API

**Files:**
- Create: `serve/app/Services/FeedbackShareUrl.php`
- Create: `serve/app/Services/FeedbackChannelService.php`
- Create: `serve/app/Http/Requests/StoreFeedbackChannelRequest.php`
- Create: `serve/app/Http/Requests/UpdateFeedbackChannelRequest.php`
- Create: `serve/app/Http/Resources/FeedbackChannelResource.php`
- Create: `serve/app/Http/Controllers/Api/FeedbackChannelController.php`
- Modify: `serve/routes/api.php`
- Create: `serve/tests/Concerns/CreatesFeedbackFixtures.php`
- Create: `serve/tests/Feature/FeedbackChannelApiTest.php`

**Interfaces:**
- Produces: `FeedbackShareUrl::for(FeedbackChannel $channel): string`; channel list/detail/create/update/status endpoints（无物理删除）；fixture helpers `activeFeedbackUser(): User`、`enabledShareDomain(string $host = 'feedback.example'): Domain`、`feedbackChannelFor(User $user, array $overrides = []): FeedbackChannel`、`feedbackTicketFor(User $user, array $overrides = []): FeedbackTicket`、`validChannelPayload(int $domainId): array`、`validSubmission(array $overrides = []): array`、`postToChannelHost(FeedbackChannel $channel, array $payload): TestResponse`、`actingAsFeedbackOwner(FeedbackTicket $ticket): void`、`expiredFeedbackTicket(int $retentionDays): FeedbackTicket`、`attachPrivateFixture(FeedbackTicket $ticket): FeedbackAttachment`。

- [ ] **Step 1: Write failing channel API tests**

```php
public function test_member_creates_channel_with_server_owned_code_and_share_url(): void
{
    $user = $this->activeFeedbackUser();
    Sanctum::actingAs($user, ['*'], 'api');
    $domain = $this->enabledShareDomain('feedback.example');
    $response = $this->postJson('/api/feedback-channels', $this->validChannelPayload($domain->id));
    $response->assertCreated()
        ->assertJsonPath('domain_id', $domain->id)
        ->assertJsonPath('share_url', fn ($value) => preg_match('#^https://feedback\.example/f/[A-Za-z0-9]{24}$#', $value) === 1)
        ->assertJsonMissingPath('webhook_url');
}

public function test_normal_endpoint_never_reads_another_users_channel(): void
{
    $owner = $this->activeFeedbackUser();
    $other = $this->activeFeedbackUser();
    $channel = $this->feedbackChannelFor($owner);
    Sanctum::actingAs($other, ['*'], 'api');
    $this->getJson('/api/feedback-channels/'.$channel->id)->assertNotFound();
}
```

- [ ] **Step 2: Run tests to verify red**

Run: `serve/bin/test-env php artisan test tests/Feature/FeedbackChannelApiTest.php`

Expected: FAIL with 404 because routes/controllers do not exist.

- [ ] **Step 3: Implement channel contracts**

Validation contract:

```php
return [
    'domain_id' => ['required', 'integer', Rule::exists('domains', 'id')->where('enable', true)],
    'name' => ['required', 'string', 'max:80'],
    'operator_name' => ['required', 'string', 'max:80'],
    'intro' => ['nullable', 'string', 'max:500'],
    'service_phone' => ['nullable', 'string', 'max:32'],
    'sla_text' => ['required', 'string', 'max:80'],
    'categories' => ['required', 'array', 'min:1', 'max:10'],
    'categories.*' => ['required', 'string', 'max:20', 'distinct'],
    'contact_required' => ['required', 'boolean'],
    'retention_days' => ['required', 'integer', 'min:30', 'max:365'],
];
```

`FeedbackChannelService::create()` calls `EntitlementService::assertActive()`, generates exactly 24 alphanumeric characters with collision retry, ignores client `code/user_id/status/webhook_url`, and writes actor `user_id`. `update()` allows only validated public configuration; `setStatus()` accepts exactly one JSON boolean field `status`.

`FeedbackShareUrl::for()` requires a loaded/enabled channel domain and `ShareOriginPolicy::forDomain()` result, otherwise throws `BusinessRuleException(FeedbackError::DOMAIN_UNAVAILABLE, '所选域名暂不可用', 503)`. Resource returns `share_url` or `null`, `domain_available`, and `webhook_configured`; it never serializes encrypted fields. Controller queries always include `where('user_id', auth('api')->id())`, including administrator accounts.

- [ ] **Step 4: Run API and secret regressions**

Run: `serve/bin/test-env php artisan test tests/Feature/FeedbackChannelApiTest.php tests/Feature/SecretAtRestTest.php tests/Feature/ProtectedSecretAuditTest.php`

Expected: PASS；foreign resources remain non-enumerating 404; client-owned code/user/status fields are ignored or rejected.

- [ ] **Step 5: Commit**

```bash
git add serve/app/Services/FeedbackShareUrl.php serve/app/Services/FeedbackChannelService.php serve/app/Http/Requests/StoreFeedbackChannelRequest.php serve/app/Http/Requests/UpdateFeedbackChannelRequest.php serve/app/Http/Resources/FeedbackChannelResource.php serve/app/Http/Controllers/Api/FeedbackChannelController.php serve/routes/api.php serve/tests/Concerns/CreatesFeedbackFixtures.php serve/tests/Feature/FeedbackChannelApiTest.php
git diff --cached --check
git commit -m "feat: manage tenant feedback channels"
```

### Task 4: 建立公开渠道门禁和移动反馈页

**Files:**
- Create: `serve/app/Services/FeedbackChannelGate.php`
- Create: `serve/app/Http/Controllers/FeedbackPublicController.php`
- Create: `serve/resources/views/feedback/show.blade.php`
- Create: `serve/resources/views/feedback/unavailable.blade.php`
- Modify: `serve/routes/web.php`
- Create: `serve/tests/Feature/FeedbackPublicPageTest.php`

**Interfaces:**
- Produces: `FeedbackChannelGate::forRequest(string $code, Request $request, CarbonImmutable $at): FeedbackChannel`; `GET /f/{code}` HTML boundary。

- [ ] **Step 1: Write failing host and copy tests**

```php
public function test_public_page_requires_selected_host_and_safe_merchant_copy(): void
{
    $channel = $this->feedbackChannelFor($this->activeFeedbackUser());
    $this->withServerVariables(['HTTP_HOST' => parse_url($channel->domain->url, PHP_URL_HOST)])
        ->get('/f/'.$channel->code)
        ->assertOk()
        ->assertSee($channel->operator_name)
        ->assertSee('商家售后反馈')
        ->assertDontSee('企业微信官方投诉')
        ->assertCookie('visitor_id');
    $this->withServerVariables(['HTTP_HOST' => 'other.example'])->get('/f/'.$channel->code)->assertNotFound();
}
```

- [ ] **Step 2: Run tests to verify red**

Run: `serve/bin/test-env php artisan test tests/Feature/FeedbackPublicPageTest.php`

Expected: FAIL because `/f/{code}` is missing.

- [ ] **Step 3: Implement gate and fixed Blade templates**

`FeedbackChannelGate` loads channel + domain + owner by code, returns 404 for missing/wrong host, and returns a generic unavailable response for channel disabled, domain disabled/unallowlisted, owner disabled, or `EntitlementService::assertActive()` failure. Host comparison uses `ShareOriginPolicy::hostFor()` and the normalized request host; it never accepts another domain-pool host for the same code.

`show.blade.php` contains only server-escaped values and fixed controls: category select, 10–2000 character textarea, optional contact, three-image picker, consent checkbox, submit button, operator/SLA/phone and a visible “本页面由上述商家运营，并非企业微信官方投诉入口” notice. It must not use `{!! !!}` or remote scripts. The GET response sets the existing `VisitorIdentityService` secure visitor cookie.

- [ ] **Step 4: Run page and XSS tests**

Run: `serve/bin/test-env php artisan test tests/Feature/FeedbackPublicPageTest.php tests/Feature/Security/VisitorIdentityTest.php`

Expected: PASS；script-like configured text is escaped; wrong host and foreign domain both return 404.

- [ ] **Step 5: Commit**

```bash
git add serve/app/Services/FeedbackChannelGate.php serve/app/Http/Controllers/FeedbackPublicController.php serve/resources/views/feedback/show.blade.php serve/resources/views/feedback/unavailable.blade.php serve/routes/web.php serve/tests/Feature/FeedbackPublicPageTest.php
git diff --cached --check
git commit -m "feat: render branded public feedback page"
```

### Task 5: 实现加密、幂等和限流的工单提交

**Files:**
- Create: `serve/app/DTO/FeedbackSubmissionData.php`
- Create: `serve/app/DTO/FeedbackSubmissionResult.php`
- Create: `serve/app/Services/FeedbackAbuseKey.php`
- Create: `serve/app/Services/FeedbackSubmissionService.php`
- Create: `serve/app/Http/Requests/StoreFeedbackTicketRequest.php`
- Modify: `serve/app/Http/Controllers/FeedbackPublicController.php`
- Modify: `serve/app/Providers/RouteServiceProvider.php`
- Modify: `serve/routes/web.php`
- Modify: `serve/resources/views/feedback/show.blade.php`
- Create: `serve/tests/Feature/FeedbackSubmissionTest.php`

**Interfaces:**
- Produces: `FeedbackSubmissionService::submit(FeedbackChannel $channel, FeedbackSubmissionData $data): FeedbackSubmissionResult`; `POST /f/{code}/tickets`。

- [ ] **Step 1: Write failing submission tests**

```php
public function test_submission_encrypts_personal_data_and_is_idempotent(): void
{
    $channel = $this->feedbackChannelFor($this->activeFeedbackUser());
    $payload = ['category' => $channel->categories[0], 'content' => 'content-secret-123', 'contact' => 'contact-secret', 'idempotency_key' => (string) Str::uuid(), 'privacy_accepted' => true];
    $first = $this->postToChannelHost($channel, $payload)->assertCreated();
    $second = $this->postToChannelHost($channel, $payload)->assertOk();
    $this->assertSame($first->json('public_no'), $second->json('public_no'));
    $this->assertDatabaseCount('feedback_tickets', 1);
    $raw = DB::table('feedback_tickets')->first();
    $this->assertStringNotContainsString('content-secret-123', $raw->content);
    $this->assertStringNotContainsString('contact-secret', $raw->contact);
}
```

Also test category membership, contact-required behavior, 9/2001 character rejection, missing privacy consent, client `user_id/status/public_no` rejection and fourth request rate-limited with 429.

- [ ] **Step 2: Run tests to verify red**

Run: `serve/bin/test-env php artisan test tests/Feature/FeedbackSubmissionTest.php`

Expected: FAIL because POST route and submission service are missing.

- [ ] **Step 3: Implement exact DTO, transaction and limits**

```php
final readonly class FeedbackSubmissionData
{
    /** @param list<UploadedFile> $attachments */
    public function __construct(public string $category, public string $content, public ?string $contact, public string $idempotencyKey, public string $visitorHash, public array $attachments = []) {}
}

final readonly class FeedbackSubmissionResult
{
    public function __construct(public FeedbackTicket $ticket, public bool $created) {}
}
```

`StoreFeedbackTicketRequest` uses strict category-in-channel validation, content `10..2000`, contact required only when configured, UUID idempotency key, `accepted` privacy flag and prohibits server-owned fields. Controller resolves `VisitorIdentityService`, passes its hash to `FeedbackSubmissionData`, and attaches a fresh secure visitor cookie to POST responses when needed. `FeedbackSubmissionService` first queries `(channel_id,idempotency_key)`; on duplicate it returns the existing ticket. New tickets use `FB-` + 16 uppercase alphanumeric characters with collision retry, `status=pending`, owner/channel IDs, encrypted contact/content, DTO visitor hash and `submitted_at=Asia/Shanghai now` inside a transaction；同一事务写一条不含正文的 `submitted` 事件。并发撞上唯一约束时只捕获该约束对应的 `QueryException`，重新读取同一渠道/幂等键的工单并返回 `created=false`，其他数据库异常继续抛出。

`FeedbackAbuseKey::forRequest()` HMACs `feedback:{code}:{ip}` with a 32+ character `APP_FEEDBACK_HASH_KEY`. Register three limits: visitor 3 per 10 minutes, visitor 10 per day, channel 60 per minute. The POST route uses `throttle:feedback-submit`; the exception response is stable JSON 429 without echoing IP.

Blade JavaScript uses `crypto.randomUUID()` once per logical submission, sends same-origin `FormData` with CSRF token, disables the button while pending, preserves the key on retry, and replaces it only after a successful response.

- [ ] **Step 4: Run submission and isolation tests**

Run: `serve/bin/test-env php artisan test tests/Feature/FeedbackSubmissionTest.php tests/Feature/FeedbackPublicPageTest.php tests/Feature/TestingIsolationTest.php`

Expected: PASS；raw database and response bodies contain neither contact nor content plaintext outside the authorized resource path.

- [ ] **Step 5: Commit**

```bash
git add serve/app/DTO/FeedbackSubmissionData.php serve/app/DTO/FeedbackSubmissionResult.php serve/app/Services/FeedbackAbuseKey.php serve/app/Services/FeedbackSubmissionService.php serve/app/Http/Requests/StoreFeedbackTicketRequest.php serve/app/Http/Controllers/FeedbackPublicController.php serve/app/Providers/RouteServiceProvider.php serve/routes/web.php serve/resources/views/feedback/show.blade.php serve/tests/Feature/FeedbackSubmissionTest.php
git diff --cached --check
git commit -m "feat: accept idempotent feedback tickets"
```

### Task 6: 增加原子私有附件处理

**Files:**
- Create: `serve/app/Services/FeedbackAttachmentService.php`
- Modify: `serve/app/Http/Requests/StoreFeedbackTicketRequest.php`
- Modify: `serve/app/Services/FeedbackSubmissionService.php`
- Modify: `serve/resources/views/feedback/show.blade.php`
- Create: `serve/tests/Feature/FeedbackAttachmentTest.php`

**Interfaces:**
- Produces: `FeedbackAttachmentService::storeForTicket(FeedbackTicket $ticket, array $files): Collection`; attachment metadata with private random paths。

- [ ] **Step 1: Write failing attachment tests**

```php
public function test_valid_image_is_private_and_failed_batch_leaves_no_ticket_or_file(): void
{
    Storage::fake('feedback_private');
    $channel = $this->feedbackChannelFor($this->activeFeedbackUser());
    $valid = UploadedFile::fake()->image('evidence.png', 800, 600)->size(100);
    $this->postToChannelHost($channel, $this->validSubmission(['attachments' => [$valid]]))->assertCreated();
    $attachment = FeedbackAttachment::query()->firstOrFail();
    Storage::disk('feedback_private')->assertExists($attachment->path);
    $this->assertStringNotContainsString('evidence.png', $attachment->getRawOriginal('original_name'));
}
```

Also test four files, >5 MiB, SVG renamed `.png`, HTML, 6001px dimension and a simulated second-file storage exception that rolls back ticket rows and deletes the first stored file.

- [ ] **Step 2: Run tests to verify red**

Run: `serve/bin/test-env php artisan test tests/Feature/FeedbackAttachmentTest.php`

Expected: FAIL because attachment rules/service do not exist.

- [ ] **Step 3: Implement Laravel 13 image validation and cleanup**

```php
'attachments' => ['nullable', 'array', 'max:3'],
'attachments.*' => [
    'file',
    File::image(false)->types(['jpg', 'jpeg', 'png', 'webp'])->max('5mb')
        ->dimensions(Rule::dimensions()->maxWidth(6000)->maxHeight(6000)),
],
```

The service derives MIME from server inspection, hashes the temporary file with SHA-256, stores under `{user_id}/{ticket_id}/{uuid}.{server-extension}` on `feedback_private`, and creates encrypted original-name metadata. `FeedbackSubmissionService` tracks every stored path; storage or database failure rolls back the transaction and deletes all tracked paths before rethrowing. Notification dispatch remains after commit and never runs for a failed attachment batch.

- [ ] **Step 4: Run attachment and submission tests**

Run: `serve/bin/test-env php artisan test tests/Feature/FeedbackAttachmentTest.php tests/Feature/FeedbackSubmissionTest.php`

Expected: PASS；no public storage URL is emitted and invalid files create zero rows/files.

- [ ] **Step 5: Commit**

```bash
git add serve/app/Services/FeedbackAttachmentService.php serve/app/Http/Requests/StoreFeedbackTicketRequest.php serve/app/Services/FeedbackSubmissionService.php serve/resources/views/feedback/show.blade.php serve/tests/Feature/FeedbackAttachmentTest.php
git diff --cached --check
git commit -m "feat: store feedback evidence privately"
```

### Task 7: 接入加密企业微信群机器人通知

**Files:**
- Create: `serve/app/Services/WeComWebhookPolicy.php`
- Create: `serve/app/Services/WeComWebhookClient.php`
- Create: `serve/app/Services/FeedbackNotificationPayload.php`
- Create: `serve/app/Services/FeedbackDeliveryService.php`
- Create: `serve/app/Jobs/SendFeedbackNotification.php`
- Modify: `serve/app/Services/FeedbackChannelService.php`
- Modify: `serve/app/Services/FeedbackSubmissionService.php`
- Modify: `serve/app/Http/Requests/StoreFeedbackChannelRequest.php`
- Modify: `serve/app/Http/Requests/UpdateFeedbackChannelRequest.php`
- Modify: `serve/app/Http/Resources/FeedbackChannelResource.php`
- Modify: `serve/app/Http/Controllers/Api/FeedbackChannelController.php`
- Modify: `serve/app/Models/FeedbackChannel.php`
- Create: `serve/tests/Unit/Services/WeComWebhookPolicyTest.php`
- Create: `serve/tests/Feature/FeedbackNotificationTest.php`

**Interfaces:**
- Produces: `WeComWebhookPolicy::assertValid(string $url): string`; `WeComWebhookClient::send(string $url, array $payload): void`; `FeedbackDeliveryService::queueTicket(FeedbackTicket $ticket): ?FeedbackDelivery`; `queueTest(FeedbackChannel $channel): FeedbackDelivery`。

- [ ] **Step 1: Write failing URL, secrecy and payload tests**

```php
public function test_webhook_policy_accepts_only_exact_wecom_endpoint(): void
{
    $valid = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=12345678-abcd-1234-abcd-123456789012';
    $this->assertSame($valid, app(WeComWebhookPolicy::class)->assertValid($valid));
    foreach (['http://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=x', 'https://evil.example/cgi-bin/webhook/send?key=x', 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=x&next=y'] as $bad) {
        try {
            app(WeComWebhookPolicy::class)->assertValid($bad);
            $this->fail('unsafe webhook accepted');
        } catch (BusinessRuleException $exception) {
            $this->assertSame(FeedbackError::WEBHOOK_INVALID, $exception->errorCode);
        }
    }
}

public function test_notification_excludes_personal_data_and_secret(): void
{
    Http::preventStrayRequests();
    Http::fake(['https://qyapi.weixin.qq.com/*' => Http::response(['errcode' => 0, 'errmsg' => 'ok'])]);
    $ticket = $this->feedbackTicketFor($this->activeFeedbackUser(), ['contact' => 'contact-secret', 'content' => 'content-secret']);
    $ticket->channel->forceFill(['webhook_url' => 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=12345678-abcd-1234-abcd-123456789012'])->save();
    app(FeedbackDeliveryService::class)->queueTicket($ticket);
    Http::assertSent(fn (Request $request) => ! str_contains($request->body(), 'contact-secret') && ! str_contains($request->body(), 'content-secret'));
}
```

- [ ] **Step 2: Run tests to verify red**

Run: `serve/bin/test-env php artisan test tests/Unit/Services/WeComWebhookPolicyTest.php tests/Feature/FeedbackNotificationTest.php`

Expected: FAIL because policy/client/delivery job do not exist.

- [ ] **Step 3: Implement write-only configuration and outbox dispatch**

Webhook policy requires HTTPS, exact host `qyapi.weixin.qq.com`, default/443 port, exact path `/cgi-bin/webhook/send`, no fragment/userinfo, and exactly one `key` query value matching `[A-Za-z0-9_-]{16,128}`. Store/update requests accept optional `webhook_url`; missing preserves existing, explicit `null` clears it, non-null passes the policy. The encrypted model field is hidden; resource returns only `webhook_configured` and timestamp. Set writes `webhook_configured_at=now()`；clear sets it to `null`。两者写 `ProtectedSecretAuditService` events `protected_secret.set/invalidated` with key identifier `feedback_channel:{id}:webhook_url` and no value.

`FeedbackDeliveryService` creates unique `ticket:{ticket-id}:wecom` delivery inside the submission transaction. `dispatchSafely(FeedbackDelivery $delivery): void` registers an `afterCommit` callback, dispatches `SendFeedbackNotification($deliveryId)`, and catches only dispatch/同步队列 exceptions so a notification outage cannot change the already committed HTTP result；caught errors leave the delivery retryable and never expose the Webhook. Test notifications use `test:{uuid}`, `kind=test`, nullable ticket ID and explicit text marker “测试通知”.

`WeComWebhookClient` calls `Http::connectTimeout(3)->timeout(5)->withoutRedirecting()->post()`, accepts only 2xx JSON with `errcode === 0`, and throws `BusinessRuleException(FeedbackError::NOTIFICATION_FAILED, '企业微信通知发送失败', 502)` otherwise. Job has `$tries=5`, `backoff(): array { return [60, 300, 1800, 7200]; }`; each attempt increments `attempts` and sets `next_attempt_at` from the matching backoff, success sets `sent/sent_at` and clears retry time, exhausted failure sets `failed` and only stores `WECOM_REQUEST_FAILED` or `WECOM_RESPONSE_REJECTED`.

- [ ] **Step 4: Run notification, audit and no-stray-request tests**

Run: `serve/bin/test-env php artisan test tests/Unit/Services/WeComWebhookPolicyTest.php tests/Feature/FeedbackNotificationTest.php tests/Feature/ProtectedSecretAuditTest.php`

Expected: PASS；ticket submission remains 201 when fake Webhook fails; delivery is retryable; no secret appears in JSON, logs or model serialization.

- [ ] **Step 5: Commit**

```bash
git add serve/app/Services/WeComWebhookPolicy.php serve/app/Services/WeComWebhookClient.php serve/app/Services/FeedbackNotificationPayload.php serve/app/Services/FeedbackDeliveryService.php serve/app/Jobs/SendFeedbackNotification.php serve/app/Services/FeedbackChannelService.php serve/app/Services/FeedbackSubmissionService.php serve/app/Http/Requests/StoreFeedbackChannelRequest.php serve/app/Http/Requests/UpdateFeedbackChannelRequest.php serve/app/Http/Resources/FeedbackChannelResource.php serve/app/Http/Controllers/Api/FeedbackChannelController.php serve/app/Models/FeedbackChannel.php serve/tests/Unit/Services/WeComWebhookPolicyTest.php serve/tests/Feature/FeedbackNotificationTest.php
git diff --cached --check
git commit -m "feat: notify WeCom about feedback tickets"
```

### Task 8: 实现租户工单后台 API、状态机和授权下载

**Files:**
- Create: `serve/app/Services/FeedbackTicketStateMachine.php`
- Create: `serve/app/Services/FeedbackTicketService.php`
- Create: `serve/app/Http/Requests/UpdateFeedbackTicketStatusRequest.php`
- Create: `serve/app/Http/Requests/StoreFeedbackNoteRequest.php`
- Create: `serve/app/Http/Resources/FeedbackTicketListResource.php`
- Create: `serve/app/Http/Resources/FeedbackTicketResource.php`
- Create: `serve/app/Http/Controllers/Api/FeedbackTicketController.php`
- Create: `serve/app/Http/Controllers/Api/FeedbackAttachmentController.php`
- Modify: `serve/routes/api.php`
- Create: `serve/tests/Feature/FeedbackTicketApiTest.php`

**Interfaces:**
- Produces: ticket list/detail/status/note/download endpoints; `FeedbackTicketStateMachine::assertTransition(FeedbackTicketStatus $from, FeedbackTicketStatus $to): void`。

- [ ] **Step 1: Write failing tenant and transition tests**

```php
public function test_owner_can_process_ticket_but_foreign_user_gets_404(): void
{
    $owner = $this->activeFeedbackUser();
    $ticket = $this->feedbackTicketFor($owner);
    Sanctum::actingAs($owner, ['*'], 'api');
    $this->patchJson('/api/feedback-tickets/'.$ticket->id.'/status', ['status' => 'processing'])
        ->assertOk()->assertJsonPath('status', 'processing');
    Sanctum::actingAs($this->activeFeedbackUser(), ['*'], 'api');
    $this->getJson('/api/feedback-tickets/'.$ticket->id)->assertNotFound();
}

public function test_invalid_transition_is_rejected_without_event(): void
{
    $ticket = $this->feedbackTicketFor($this->activeFeedbackUser(), ['status' => 'pending']);
    $this->actingAsFeedbackOwner($ticket);
    $this->patchJson('/api/feedback-tickets/'.$ticket->id.'/status', ['status' => 'closed'])->assertStatus(422);
    $this->assertDatabaseCount('feedback_events', 0);
}
```

- [ ] **Step 2: Run tests to verify red**

Run: `serve/bin/test-env php artisan test tests/Feature/FeedbackTicketApiTest.php`

Expected: FAIL because management routes do not exist.

- [ ] **Step 3: Implement resources, state machine and download boundary**

Allowed transitions are exact: pending→processing, processing→resolved, resolved→closed, resolved→processing. `FeedbackTicketService` writes ticket update and event in one transaction; resolving sets `resolved_at`, reopening clears it. Note input is 1–1000 plain-text characters and creates `note_added` event with encrypted note.

Every controller starts from `FeedbackTicket::where('user_id', auth('api')->id())`; list supports exact status/channel/category/date filters and pagination. List resource excludes contact/content/visitor hash; detail resource decrypts contact/content for owner and returns attachment metadata plus authorized download URLs. Attachment controller additionally scopes by `user_id`, strips CR/LF and path separators from decrypted original name, and streams only from `feedback_private`.

- [ ] **Step 4: Run API, XSS and download tests**

Run: `serve/bin/test-env php artisan test tests/Feature/FeedbackTicketApiTest.php tests/Feature/FeedbackAttachmentTest.php`

Expected: PASS；foreign user/admin accounts cannot read another owner through normal API; notes render as text; no direct disk path is returned.

- [ ] **Step 5: Commit**

```bash
git add serve/app/Services/FeedbackTicketStateMachine.php serve/app/Services/FeedbackTicketService.php serve/app/Http/Requests/UpdateFeedbackTicketStatusRequest.php serve/app/Http/Requests/StoreFeedbackNoteRequest.php serve/app/Http/Resources/FeedbackTicketListResource.php serve/app/Http/Resources/FeedbackTicketResource.php serve/app/Http/Controllers/Api/FeedbackTicketController.php serve/app/Http/Controllers/Api/FeedbackAttachmentController.php serve/routes/api.php serve/tests/Feature/FeedbackTicketApiTest.php
git diff --cached --check
git commit -m "feat: manage feedback ticket lifecycle"
```

### Task 9: 实现保存期限和个人信息清理

**Files:**
- Create: `serve/app/Console/Commands/PurgeExpiredFeedback.php`
- Modify: `serve/app/Console/Kernel.php`
- Create: `serve/tests/Feature/FeedbackPurgeCommandTest.php`

**Interfaces:**
- Produces: `app:feedback-purge` idempotent command and daily 02:30 Asia/Shanghai schedule。

- [ ] **Step 1: Write failing purge tests**

```php
public function test_purge_removes_private_evidence_and_personal_text_but_keeps_anonymous_counts(): void
{
    Storage::fake('feedback_private');
    $ticket = $this->expiredFeedbackTicket(retentionDays: 30);
    $attachment = $this->attachPrivateFixture($ticket);
    $this->artisan('app:feedback-purge')->assertExitCode(0);
    Storage::disk('feedback_private')->assertMissing($attachment->path);
    $ticket->refresh();
    $this->assertNull($ticket->contact);
    $this->assertNull($ticket->content);
    $this->assertNotNull($ticket->anonymized_at);
    $this->assertDatabaseHas('feedback_tickets', ['id' => $ticket->id, 'public_no' => $ticket->public_no]);
}
```

Also test visitor hash null after 30 days, fresh tickets untouched, repeated command idempotent and storage delete failure leaves personal row for retry instead of claiming success.

- [ ] **Step 2: Run tests to verify red**

Run: `serve/bin/test-env php artisan test tests/Feature/FeedbackPurgeCommandTest.php`

Expected: FAIL because command is missing.

- [ ] **Step 3: Implement bounded purge and scheduler**

The command chunks by ticket ID. First null `visitor_hash` for submissions older than 30 days. For each non-anonymized ticket older than its channel retention cutoff: delete every private file with throw-on-error, then in a transaction delete attachment rows, null ticket contact/content/visitor hash, null all event notes, set `anonymized_at`, and retain only category/status/timestamps/public number for counts. A file failure increments failure count, leaves DB personal data intact for retry, and returns exit 1 if any failures occurred.

Schedule: `$schedule->command('app:feedback-purge')->dailyAt('02:30')->timezone('Asia/Shanghai')->withoutOverlapping(120);` Existing membership and link-health schedules remain unchanged.

- [ ] **Step 4: Run purge and schedule regressions**

Run: `serve/bin/test-env php artisan test tests/Feature/FeedbackPurgeCommandTest.php tests/Feature/VipExpiredCommandTest.php tests/Feature/LinkHealthCheckTest.php && serve/bin/test-env php artisan schedule:list | rg 'app:feedback-purge|app:vip-expired|app:links-health-check'`

Expected: PASS and all three schedules are listed.

- [ ] **Step 5: Commit**

```bash
git add serve/app/Console/Commands/PurgeExpiredFeedback.php serve/app/Console/Kernel.php serve/tests/Feature/FeedbackPurgeCommandTest.php
git diff --cached --check
git commit -m "feat: purge expired feedback personal data"
```

### Task 10: 建立管理端渠道页面

**Files:**
- Create: `admin/src/models/feedback.ts`
- Create: `admin/src/api/feedback.ts`
- Create: `admin/src/views/feedback/index.vue`
- Create: `admin/src/views/feedback/components/ChannelForm.vue`
- Modify: `admin/src/router/apiRouter.ts`
- Modify: `admin/src/utils/http.ts`

**Interfaces:**
- Produces: `ApiFeedbackChannels`, create/update/status/test-notification functions; `http.patch`; menu `/feedback`。

- [ ] **Step 1: Add compile-time contract usages before components exist**

```ts
export interface FeedbackChannel {
  id: number
  domain_id: number
  name: string
  operator_name: string
  categories: string[]
  contact_required: boolean
  retention_days: number
  status: boolean
  share_url: string | null
  domain_available: boolean
  webhook_configured: boolean
}
```

Import the interfaces/functions from the new view so `vue-tsc` must resolve the exact API contract.

- [ ] **Step 2: Run type check to verify red**

Run: `npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm --dir admin type-check`

Expected: FAIL because feedback modules do not exist.

- [ ] **Step 3: Implement typed API, menu, list and channel form**

Add `patch<T,D>()` to `http.ts` using the same request wrapper as `post/put`. Add a top-level “客诉受理” menu for `admin` and `vip`, route `/feedback`, reusing `menu-link` icons. Channel list shows name/operator/domain status/share link/status/Webhook boolean; actions are edit, enable/disable, copy, test notification. Form fields match backend limits; Webhook input starts blank on edit, omission preserves the secret, explicit “清除机器人” sends `webhook_url:null`. Save success always reloads detail/list; copy uses the server-returned `share_url` only.

- [ ] **Step 4: Run type check and production build**

Run: `npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm --dir admin type-check && npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm --dir admin build-only`

Expected: PASS；no route resolves to 404 component and no client code contains `qyapi.weixin.qq.com` test keys.

- [ ] **Step 5: Commit**

```bash
git add admin/src/models/feedback.ts admin/src/api/feedback.ts admin/src/views/feedback/index.vue admin/src/views/feedback/components/ChannelForm.vue admin/src/router/apiRouter.ts admin/src/utils/http.ts
git diff --cached --check
git commit -m "feat: manage feedback channels in admin"
```

### Task 11: 建立管理端工单工作台

**Files:**
- Create: `admin/src/views/feedback/components/TicketList.vue`
- Create: `admin/src/views/feedback/components/TicketDrawer.vue`
- Modify: `admin/src/views/feedback/index.vue`
- Modify: `admin/src/api/feedback.ts`
- Modify: `admin/src/models/feedback.ts`

**Interfaces:**
- Produces: status/channel/category/date filters, authorized detail/download, note and state actions。

- [ ] **Step 1: Add failing TypeScript contract usage**

```ts
export type FeedbackTicketStatus = 'pending' | 'processing' | 'resolved' | 'closed'
export interface FeedbackTicketListItem {
  id: number
  public_no: string
  category: string
  status: FeedbackTicketStatus
  submitted_at: string
  notification_status: 'pending' | 'sent' | 'failed' | null
}
```

Reference `TicketList` and `TicketDrawer` from `feedback/index.vue` before creating the components.

- [ ] **Step 2: Run type check to verify red**

Run: `npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm --dir admin type-check`

Expected: FAIL on missing ticket components/contracts.

- [ ] **Step 3: Implement ticket UI without unsafe HTML**

List supports backend filters/pagination and shows public number, channel, category, status, notification and time. Drawer renders content/note with interpolation or `textContent`, never `v-html`; contact appears only in detail. Status buttons follow the exact state machine and reload detail after success. Notes are 1–1000 characters. Detail resource returns authorized decrypted `original_name` but never `disk/path`；downloads call `http.request('GET', url, {responseType:'blob'})`, create/revoke an object URL, set the anchor filename to that resource value, and never derive a URL or filename from a storage path.

At ≤700px the drawer uses full viewport width, 16px inputs and ≥44px submit/status controls; ticket table becomes labeled cards without horizontal overflow.

- [ ] **Step 4: Run type, build and unsafe-render scan**

Run: `npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm --dir admin type-check && npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm --dir admin build-only && ! rg -n 'v-html' admin/src/views/feedback`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add admin/src/views/feedback/components/TicketList.vue admin/src/views/feedback/components/TicketDrawer.vue admin/src/views/feedback/index.vue admin/src/api/feedback.ts admin/src/models/feedback.ts
git diff --cached --check
git commit -m "feat: add feedback ticket workbench"
```

### Task 12: 自动化浏览器闭环、文档和发布前总门

**Files:**
- Modify: `admin/package.json`
- Modify: `admin/pnpm-lock.yaml`
- Modify: `admin/vite.config.ts`
- Create: `admin/playwright.config.ts`
- Create: `admin/e2e/global-setup.ts`
- Create: `admin/e2e/feedback.spec.ts`
- Create: `serve/tests/Support/seed-feedback-e2e.php`
- Modify: `README.md`
- Create: `docs/feedback-operations.md`
- Modify: `docs/compliance/license-inventory.md`
- Modify: `docs/superpowers/specs/2026-09-04-wecom-feedback-saas-design.md`

**Interfaces:**
- Produces: reproducible full-stack browser command and operator runbook; marks spec implementation status without claiming deployment。

- [ ] **Step 1: Install pinned browser test dependency and write failing E2E**

Run: `npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm --dir admin add -D @playwright/test@1.62.1`

`seed-feedback-e2e.php` boots Laravel only after `TestEnvironmentGuard::assertSafe()`, runs migrations/seeders, creates an active member/password, enabled `https://127.0.0.1` domain and outputs JSON containing only test username/password/channel code. It never runs when `APP_ENV` or sentinel differs from the test wrapper.

E2E flow:

```ts
import { writeFile } from 'node:fs/promises'
import { expect, test, type Page } from '@playwright/test'

const state = JSON.parse(process.env.FEEDBACK_E2E_STATE ?? '{}') as {
  username: string
  password: string
  channel_code: string
}
const backend = 'http://127.0.0.1:8090'
const admin = 'http://127.0.0.1:4174'

async function loginToAdmin(page: Page, username: string, password: string) {
  await page.goto(`${admin}/#/login`)
  await page.fill('input[placeholder="请输入账号"]', username)
  await page.fill('input[placeholder="请输入密码"]', password)
  await page.click('button:has-text("登录")')
  await expect(page).toHaveURL(/#\/(vip-home|admin-home)/)
}

test('mobile submit reaches tenant ticket workbench', async ({ page }, testInfo) => {
  const fixturePath = testInfo.outputPath('feedback.png')
  await writeFile(fixturePath, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64'))
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto(`${backend}/f/${state.channel_code}`)
  await page.selectOption('[name=category]', { index: 1 })
  await page.fill('[name=content]', '这是一条浏览器端客诉受理验收记录')
  await page.setInputFiles('[name="attachments[]"]', fixturePath)
  await page.check('[name=privacy_accepted]')
  await page.click('[data-testid=feedback-submit]')
  await expect(page.getByTestId('feedback-public-no')).toBeVisible()
  const publicNo = await page.getByTestId('feedback-public-no').textContent()
  await loginToAdmin(page, state.username, state.password)
  await page.goto(`${admin}/#/feedback`)
  await page.getByText(publicNo ?? '').click()
  await expect(page.getByText('这是一条浏览器端客诉受理验收记录')).toBeVisible()
})
```

- [ ] **Step 2: Run E2E to verify red**

Run: `docker compose -f compose.test.yaml up -d --wait && npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm --dir admin exec playwright test e2e/feedback.spec.ts`

Expected: FAIL until Playwright config, guarded seed and webServer commands are complete.

- [ ] **Step 3: Complete deterministic Playwright setup and operations docs**

Playwright config runs from `admin/`. It starts backend with `cd ../serve && PUBLIC_ORIGIN=https://127.0.0.1 ALLOWED_SHARE_HOSTS=127.0.0.1 bin/test-env php artisan serve --host=127.0.0.1 --port=8090` and Vite with `VITE_PROXY_PATH=/api VITE_API_URL=http://127.0.0.1:8090 VITE_PUBLIC_PATH=/ npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm dev --mode e2e --host 127.0.0.1 --port 4174`. Add an E2E-only branch in `vite.config.ts`: when mode is `e2e`, proxy `/api` to the origin without stripping `/api`; existing development rewrite remains unchanged. Global setup invokes `../serve/bin/test-env php ../serve/tests/Support/seed-feedback-e2e.php`, captures stdout without printing the test password, and exposes its JSON via `process.env.FEEDBACK_E2E_STATE`. The suite covers desktop + 390px, duplicate-submit retry, channel create/copy, ticket status/note, private attachment download and zero horizontal overflow；Webhook 失败/重试由隔离的 `Http::fake()` 功能测试覆盖，不让浏览器测试连接真实企微端点。

`docs/feedback-operations.md` documents environment keys, private storage permissions, queue/scheduler checks, domain/HTTPS prerequisites, Webhook rotation, retention command, backup/restore and rollback evidence. README describes only “商家客诉受理”，not competitor claims. License inventory adds Playwright as Apache-2.0 dev-only and confirms no production dependency change. Update spec status to “实现完成，待外部部署验收” only after all checks pass.

- [ ] **Step 4: Run the complete release gate**

Run:

```bash
docker compose -f compose.test.yaml up -d --wait
serve/bin/test-env php artisan migrate:fresh --seed
serve/bin/test-env php artisan test
npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm --dir admin type-check
npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm --dir admin build-only
npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm --dir admin exec playwright test
composer audit --working-dir=serve --locked --no-interaction
npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm --dir admin audit --prod
git diff --check
git status --short
```

Expected: all tests/builds/audits pass or every lower-severity advisory is recorded; no high/critical unresolved advisory; status contains only the planned documentation edits before commit. Run `rg -n -i '(webhook/send\?key=|BEGIN .*PRIVATE KEY|api[_-]?key\s*[=:]\s*[^[:space:]]+)' --glob '!*.lock' --glob '!docs/superpowers/plans/*' .` and inspect every match before staging.

- [ ] **Step 5: Commit and verify branch**

```bash
git add admin/package.json admin/pnpm-lock.yaml admin/vite.config.ts admin/playwright.config.ts admin/e2e/global-setup.ts admin/e2e/feedback.spec.ts serve/tests/Support/seed-feedback-e2e.php README.md docs/feedback-operations.md docs/compliance/license-inventory.md docs/superpowers/specs/2026-09-04-wecom-feedback-saas-design.md
git diff --cached --check
git commit -m "test: close feedback SaaS acceptance loop"
git log --oneline origin/codex/full-function-implementation..HEAD
```

### Task 13: 推送、测试环境和企业微信真实验收门

**Files:**
- Modify after evidence: `/Users/apple/公司计划/任务单/010-企业微信客诉受理SaaS.md`
- Modify after evidence: `/Users/apple/公司计划/00-决策与执行日志.md`

**Interfaces:**
- Consumes: fully passing task branch commit, existing domain pool, user-approved test group and explicit production approval。
- Produces: remote branch/PR evidence, public URL evidence, WeCom field evidence and final task delivery record。

- [ ] **Step 1: Push and verify remote code before deployment**

Run: `git push origin codex/wecom-feedback-saas && git ls-remote --heads origin codex/wecom-feedback-saas`

Expected: remote SHA exactly equals local `git rev-parse HEAD`; Draft PR lists only planned feature files and commits.

- [ ] **Step 2: Run read-only environment gate**

Before any server mutation, inspect current deployment target, selected enabled domain, DNS A/AAAA, certificate, Nginx route ownership, PHP 8.3, MySQL backup path, Redis queue worker, scheduler and private storage permissions. Record exact observed values without printing credentials. If no tested release workflow exists for the current target, stop as deployment-blocked instead of improvising production commands.

- [ ] **Step 3: Obtain the separate production confirmation**

Present code/test/remote/environment evidence and the exact selected domain and release SHA. Continue only after the user explicitly approves production deployment for that SHA and target.

- [ ] **Step 4: Deploy through the verified release workflow and prove each lane**

Back up database/application first, deploy the exact remote SHA, run migrations, restart/reload only the app-owned PHP/queue/scheduler/Nginx units, then verify: public GET, one idempotent POST, private attachment authorization, admin workbench, queue delivery and rollback readiness. A successful push or HTTP 200 alone is not acceptance.

- [ ] **Step 5: Configure WeCom through verified UI actions**

Using a fresh CUA session, snapshot before every action and verify after every save. If admin login is expired, pause only for the user to scan. Create/select the user-designated test group robot, enter its Webhook directly in the platform without exposing it to chat/logs, add the “售后反馈” webpage field, and insert the generated channel URL for the test member. Do not modify unrelated contacts, groups or fields.

- [ ] **Step 6: Complete real-client acceptance and task handoff**

Have the user open the member profile from another WeChat account, submit one clearly marked test ticket and confirm the test group notification. Verify the same public number in the platform workbench, move it through the status flow, and confirm unauthorized attachment access fails. Update task 010 to “待验收” with paths, exact commit, PR, domain, test timestamps and remaining external issues; append one execution-log row and commit/push only those two company-plan files.
