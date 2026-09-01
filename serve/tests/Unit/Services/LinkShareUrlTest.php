<?php

namespace Tests\Unit\Services;

use App\Enums\LinkType;
use App\Exceptions\LinkResolutionException;
use App\Models\Domain;
use App\Services\LinkShareUrl;
use App\Support\LinkError;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CreatesLinkFixtures;
use Tests\TestCase;

final class LinkShareUrlTest extends TestCase
{
    use CreatesLinkFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.public_origin', 'https://short.example');
        Config::set('app.allowed_share_hosts', ['short.example', 'custom.example']);
    }

    public function test_every_link_type_gets_the_same_non_empty_canonical_url(): void
    {
        foreach (LinkType::cases() as $type) {
            $link = $this->linkForType($type);
            $this->assertSame('https://short.example/?code='.$link->code, app(LinkShareUrl::class)->for($link));
        }
    }

    public function test_selected_enabled_domain_is_preferred_and_invalid_selected_domain_falls_back(): void
    {
        $domain = Domain::query()->create(['url' => 'https://CUSTOM.example:443/', 'title' => 'Custom', 'enable' => true]);
        $link = $this->linkForType(LinkType::WORK_WECHAT, ['domain_id' => $domain->id]);
        $this->assertSame('https://custom.example/?code='.$link->code, app(LinkShareUrl::class)->for($link));

        $domain->update(['url' => 'https://custom.example/path']);
        $this->assertSame('https://short.example/?code='.$link->code, app(LinkShareUrl::class)->for($link->fresh()));
    }

    public function test_invalid_origin_shapes_are_rejected_without_an_empty_url(): void
    {
        $link = $this->linkForType(LinkType::LANDING_MINI);
        foreach ([
            'http://short.example',
            'https://user:pass@short.example',
            'https://short.example/?query=1',
            'https://short.example/#fragment',
            'https://short.example:8443',
            'https://short.example/path',
            'https://other.example',
        ] as $origin) {
            Config::set('app.public_origin', $origin);
            try {
                app(LinkShareUrl::class)->for($link);
                $this->fail('invalid origin accepted: '.$origin);
            } catch (LinkResolutionException $exception) {
                $this->assertSame(LinkError::SHARE_ORIGIN_UNAVAILABLE, $exception->errorCode);
            }
        }
    }

    public function test_qr_landing_target_accepts_only_the_exact_generated_same_origin_url(): void
    {
        $link = $this->linkForType(LinkType::LANDING_MINI);
        $urls = app(LinkShareUrl::class);
        $target = $urls->qrLandingFor($link, 'signed+/=token');

        $this->assertSame(
            'https://short.example/qr/'.$link->code.'?visitor_token=signed%2B%2F%3Dtoken',
            $target,
        );
        $urls->assertQrLandingTarget($link, $target);

        foreach ([
            'https://other.example/qr/'.$link->code.'?visitor_token=signed%2B%2F%3Dtoken',
            'https://short.example/qr/wrong123?visitor_token=signed%2B%2F%3Dtoken',
            $target.'&extra=1',
            $target.'#fragment',
            'https://short.example/qr/'.$link->code.'?visitor_token=signed+/=token',
        ] as $mutated) {
            try {
                $urls->assertQrLandingTarget($link, $mutated);
                $this->fail('mutated QR landing URL was accepted: '.$mutated);
            } catch (LinkResolutionException $exception) {
                $this->assertSame(LinkError::UNSAFE_URL, $exception->errorCode);
            }
        }
    }
}
