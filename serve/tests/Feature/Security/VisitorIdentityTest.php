<?php

namespace Tests\Feature\Security;

use App\DTO\VisitorContext;
use App\DTO\VisitorIdentity;
use App\Exceptions\LinkResolutionException;
use App\Services\VisitorIdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

final class VisitorIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.visitor_hash_key' => str_repeat('h', 32)]);
    }

    public function test_cookie_identity_is_server_issued_and_ignores_device_uid(): void
    {
        $request = Request::create('/api/link-target/x', 'GET', ['device_uid' => 'attacker-controlled'], [], [], [
            'HTTP_DEVICE_UID' => 'header-controlled',
        ]);
        $request->cookies->set('visitor_id', 'query-or-body-never-used');
        $identity = app(VisitorIdentityService::class)->resolve($request);

        $this->assertTrue(Str::isUuid($identity->visitorId));
        $this->assertNotSame('attacker-controlled', $identity->visitorId);
        $this->assertNotSame('header-controlled', $identity->visitorId);
        $this->assertTrue($identity->setCookie);
        $this->assertSame(hash_hmac('sha256', $identity->visitorId, str_repeat('h', 32)), $identity->hash);

        $cookie = app(VisitorIdentityService::class)->cookie($identity);
        $this->assertSame('visitor_id', $cookie->getName());
        $this->assertSame($identity->visitorId, $cookie->getValue());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_LAX, strtolower((string) $cookie->getSameSite()));
        $this->assertEqualsWithDelta(365 * 24 * 60 * 60, $cookie->getMaxAge(), 5);
    }

    public function test_valid_cookie_is_reused_without_reissuing_and_invalid_cookie_is_replaced(): void
    {
        $service = app(VisitorIdentityService::class);
        $valid = (string) Str::uuid();
        $request = Request::create('/');
        $request->cookies->set('visitor_id', $valid);

        $identity = $service->resolve($request);
        $this->assertSame($valid, $identity->visitorId);
        $this->assertFalse($identity->setCookie);

        foreach (['', 'not-a-uuid', '123e4567-e89b-12d3-a456-42661417400', '123e4567-e89b-12d3-a456-426614174000x'] as $invalid) {
            $request->cookies->set('visitor_id', $invalid);
            $identity = $service->resolve($request);
            $this->assertTrue(Str::isUuid($identity->visitorId));
            $this->assertNotSame($invalid, $identity->visitorId);
            $this->assertTrue($identity->setCookie);
        }
    }

    public function test_cookie_rejects_an_invalid_dto_visitor_id_without_signing_it(): void
    {
        try {
            app(VisitorIdentityService::class)->cookie(new VisitorIdentity('not-a-uuid', 'hash-1', true));
            $this->fail('invalid DTO visitor id received a cookie');
        } catch (LinkResolutionException $exception) {
            $this->assertSame('VISITOR_ID_INVALID', $exception->errorCode);
            $this->assertSame('Visitor identity is invalid.', $exception->getMessage());
        }
    }

    public function test_hash_key_must_be_independent_and_at_least_32_bytes(): void
    {
        foreach (['', str_repeat('x', 31)] as $key) {
            config(['app.visitor_hash_key' => $key]);
            try {
                app(VisitorIdentityService::class)->resolve(Request::create('/'));
                $this->fail('weak visitor hash key was accepted');
            } catch (LinkResolutionException $exception) {
                $this->assertSame('VISITOR_HASH_KEY_INVALID', $exception->errorCode);
                $this->assertSame('Visitor hash configuration is invalid.', $exception->getMessage());
                if ($key !== '') {
                    $this->assertStringNotContainsString($key, $exception->getMessage());
                }
            }
        }
    }

    public function test_identity_converts_to_context_without_a_token_and_anonymous_is_deterministic(): void
    {
        $identity = new VisitorIdentity('visitor-1', 'hash-1', false);
        $context = VisitorContext::fromIdentity($identity);

        $this->assertSame('visitor-1', $context->visitorId);
        $this->assertSame('hash-1', $context->hash);
        $this->assertNull($context->token);
        $first = VisitorContext::anonymous();
        $second = VisitorContext::anonymous();
        $this->assertSame($first->visitorId, $second->visitorId);
        $this->assertSame($first->hash, $second->hash);
        $this->assertSame($first->token, $second->token);
        $this->assertSame('anonymous', $first->visitorId);
        $this->assertTrue($first->isAnonymous());
        $this->assertNull($first->token);
    }
}
