<?php

namespace App\Services\Resolvers;

use App\DTO\TargetResult;
use App\DTO\VisitorContext;
use App\Enums\LinkType;
use App\Exceptions\LinkResolutionException;
use App\Models\Link;
use App\Services\Http\SafeHttpClient;
use App\Services\WeixinSchemePolicy;
use App\Support\LinkError;
use App\Support\LinkTypeParser;
use Throwable;

final class KingDocTargetResolver implements TargetResolver
{
    private const ENDPOINT = 'https://account.kdocs.cn/api/v3/miniprogram/urllink';

    public function __construct(
        private readonly SafeHttpClient $http,
        private readonly WeixinSchemePolicy $schemePolicy,
    ) {}

    public function resolve(Link $link, VisitorContext $visitor): TargetResult
    {
        try {
            $this->assertType($link);
            $id = $this->linkId($link);
            $query = $this->endpointQuery($id);
            $payload = $this->http->getJson(self::ENDPOINT.'?'.$query, ['account.kdocs.cn']);
            $urlLink = $payload['url_link'] ?? null;
            if (! is_string($urlLink) || $urlLink === '') {
                throw new \RuntimeException('missing provider URL');
            }

            $body = $this->http->getText($urlLink, ['account.kdocs.cn', 'kdocs.cn', 'www.kdocs.cn']);
            $target = $this->extractScheme($body);
            $target = $this->schemePolicy->assert($target);

            return new TargetResult(
                (string) $link->getAttribute('title'),
                (string) ($link->getAttribute('description') ?? ''),
                $link->getAttribute('icon') === null ? null : (string) $link->getAttribute('icon'),
                $target,
            );
        } catch (Throwable) {
            throw new LinkResolutionException(LinkError::KING_DOC_EXTERNAL_ERROR, '金山文档跳转暂不可用', 502);
        }
    }

    private function assertType(Link $link): void
    {
        $rawType = $link->getRawOriginal('type');
        $type = LinkTypeParser::parse($rawType);
        if (! $type) {
            $attributeType = $link->getAttribute('type');
            if ($attributeType instanceof LinkType) {
                $type = $attributeType;
            }
        }
        if ($type !== LinkType::KING_DOC) {
            throw new \RuntimeException('wrong link type');
        }
    }

    private function linkId(Link $link): string
    {
        $config = $link->getAttribute('config');
        $url = is_array($config) ? ($config['url'] ?? null) : null;
        if (! is_string($url) || preg_match('~^https://kdocs\.cn/l/([A-Za-z0-9]+)$~D', $url, $matches) !== 1) {
            throw new \RuntimeException('invalid Kdocs URL');
        }

        return $matches[1];
    }

    private function endpointQuery(string $id): string
    {
        $nested = http_build_query([
            'url' => 'pages/preview/preview?from=wxminiprogram&fid=256465035925&sid='.$id.'&fname='.urlencode('扫码加微信.docx'),
            'scene' => '102',
            'jump_from' => 'wechatlogin_guide_passive',
            'comp' => 'docx',
            'dw' => '1',
        ], '', '&', PHP_QUERY_RFC3986);

        return http_build_query([
            'appid' => 'wx5b97b0686831c076',
            'path' => 'pages/navigate/navigate',
            'query' => $nested,
            'env_version' => 'release',
            'is_expire' => 'true',
            'expire_time' => (string) (time() + 7200),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function extractScheme(string $body): string
    {
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $fields = [];
        preg_match_all('~(?:^|[\s,{])(?:["\']?)url_scheme(?:["\']?)\s*:~s', $body, $fields);
        if (count($fields[0] ?? []) !== 1) {
            throw new \RuntimeException('invalid Kdocs response');
        }
        $matches = [];
        preg_match_all('~(?:^|[\s,{])(?:["\']?)url_scheme(?:["\']?)\s*:\s*(["\'])(.*?)\1~s', $body, $matches, PREG_SET_ORDER);
        if (count($matches) !== 1 || ! isset($matches[0][2]) || ! is_string($matches[0][2]) || $matches[0][2] === '') {
            throw new \RuntimeException('invalid Kdocs response');
        }

        $value = $matches[0][2];
        for ($attempt = 0; $attempt < 3 && str_contains($value, '\\/'); $attempt++) {
            $value = str_replace('\\/', '/', $value);
        }

        return $value;
    }
}
