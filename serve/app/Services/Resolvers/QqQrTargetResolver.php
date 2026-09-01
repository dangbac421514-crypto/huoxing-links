<?php

namespace App\Services\Resolvers;

use App\DTO\TargetResult;
use App\DTO\VisitorContext;
use App\Enums\LinkType;
use App\Exceptions\LinkResolutionException;
use App\Models\Link;
use App\Services\Http\SafeHttpClient;
use App\Services\Http\UrlPolicy;
use App\Services\WeixinSchemePolicy;
use App\Support\LinkError;
use App\Support\LinkTypeParser;
use Throwable;

final class QqQrTargetResolver implements TargetResolver
{
    public function __construct(
        private readonly SafeHttpClient $http,
        private readonly UrlPolicy $policy,
        private readonly WeixinSchemePolicy $schemePolicy,
    ) {}

    public function resolve(Link $link, VisitorContext $visitor): TargetResult
    {
        try {
            $rawType = $link->getRawOriginal('type');
            $type = LinkTypeParser::parse($rawType);
            if (! $type) {
                $attributeType = $link->getAttribute('type');
                if ($attributeType instanceof LinkType) {
                    $type = $attributeType;
                }
            }
            if ($type !== LinkType::QR_QQ) {
                throw new \RuntimeException('wrong link type');
            }
            $config = $link->getAttribute('config');
            $url = is_array($config) ? ($config['url'] ?? null) : null;
            if (! is_string($url) || $url === '') {
                throw new \RuntimeException('invalid QQ QR URL');
            }
            $uri = $this->policy->assertExternal($url, ['ym.link']);
            if ($uri->getPath() === '' || $uri->getPath() === '/' || $uri->getQuery() !== '') {
                throw new \RuntimeException('invalid QQ QR path');
            }

            $html = $this->http->getText((string) $uri, ['ym.link']);
            $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            for ($attempt = 0; $attempt < 3 && str_contains($html, '\\/'); $attempt++) {
                $html = str_replace('\\/', '/', $html);
            }
            $matches = [];
            preg_match_all('#(?<![A-Za-z0-9])weixin://dl/business/\?t=([A-Za-z0-9._~+/%=-]+)(?![A-Za-z0-9._~+/%=-])#', $html, $matches);
            if (count($matches[0] ?? []) !== 1 || ! isset($matches[1][0]) || $matches[1][0] === '') {
                throw new \RuntimeException('QQ QR scheme count is invalid');
            }
            $target = $this->schemePolicy->assert('weixin://dl/business/?t='.$matches[1][0]);

            return new TargetResult(
                (string) $link->getAttribute('title'),
                (string) ($link->getAttribute('description') ?? ''),
                $link->getAttribute('icon') === null ? null : (string) $link->getAttribute('icon'),
                $target,
            );
        } catch (Throwable) {
            throw new LinkResolutionException(LinkError::QQ_QR_EXTERNAL_ERROR, '腾讯优码跳转暂不可用', 502);
        }
    }
}
