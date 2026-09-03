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

final class CliQrTargetResolver implements TargetResolver
{
    private const ENDPOINT = 'https://nc.cli.im/api/weixin/getWxUrlScheme/';

    public function __construct(
        private readonly SafeHttpClient $http,
        private readonly WeixinSchemePolicy $schemePolicy,
    ) {}

    public function resolve(Link $link, VisitorContext $visitor): TargetResult
    {
        try {
            $this->assertType($link);
            [$user, $id] = $this->linkParts($link);
            $endpointQuery = http_build_query([
                'query' => 'q=qr61.cn/'.$user.'/'.$id,
                'path' => 'pages/code/code',
                'appid' => 'wx5db79bd23a923e8e',
                'org_coding' => $user,
            ], '', '&', PHP_QUERY_RFC3986);
            $ticket = $this->http->getJson(self::ENDPOINT.'?'.$endpointQuery, ['nc.cli.im']);
            $fetchUrl = $ticket['data']['wx_url_scheme']['fetchUrl'] ?? null;
            if (! is_string($fetchUrl) || $fetchUrl === '') {
                throw new \RuntimeException('missing CLI fetch URL');
            }

            $payload = $this->http->getJson($fetchUrl, ['nc.cli.im']);
            $target = $payload['data']['urlScheme'] ?? null;
            if (! is_string($target) || $target === '') {
                throw new \RuntimeException('missing CLI scheme');
            }
            $target = $this->schemePolicy->assert($target);

            return new TargetResult(
                (string) $link->getAttribute('title'),
                (string) ($link->getAttribute('description') ?? ''),
                $link->getAttribute('icon') === null ? null : (string) $link->getAttribute('icon'),
                $target,
            );
        } catch (Throwable) {
            throw new LinkResolutionException(LinkError::CLI_QR_EXTERNAL_ERROR, '草料二维码跳转暂不可用', 502);
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
        if ($type !== LinkType::CLI_QR) {
            throw new \RuntimeException('wrong link type');
        }
    }

    /** @return array{0:string,1:string} */
    private function linkParts(Link $link): array
    {
        $config = $link->getAttribute('config');
        $url = is_array($config) ? ($config['url'] ?? null) : null;
        if (! is_string($url) || preg_match('~^https://qr61\.cn/([A-Za-z0-9_-]+)/([A-Za-z0-9_-]+)$~D', $url, $matches) !== 1) {
            throw new \RuntimeException('invalid CLI URL');
        }

        return [$matches[1], $matches[2]];
    }
}
