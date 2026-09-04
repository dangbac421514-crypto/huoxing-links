<?php

namespace Tests\Unit\Services;

use App\Models\Domain;
use App\Services\ShareOriginPolicy;
use Tests\TestCase;

final class ShareOriginPolicyTest extends TestCase
{
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
}
