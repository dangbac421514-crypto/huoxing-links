<?php

namespace App\Services\Resolvers;

use App\DTO\TargetResult;
use App\DTO\VisitorContext;
use App\Enums\LinkType;
use App\Exceptions\LinkResolutionException;
use App\Models\Link;
use App\Services\Http\UrlPolicy;
use App\Support\LinkError;
use App\Support\LinkTypeParser;
use Throwable;

final class WorkWechatTargetResolver implements TargetResolver
{
    public function __construct(private readonly UrlPolicy $policy) {}

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
            if ($type !== LinkType::WORK_WECHAT) {
                throw new \RuntimeException('wrong link type');
            }
            $config = $link->getAttribute('config');
            $url = is_array($config) ? ($config['url'] ?? null) : null;
            if (! is_string($url) || $url === '') {
                throw new \RuntimeException('invalid Work WeChat URL');
            }

            $uri = $this->policy->assertExternal($url, ['work.weixin.qq.com']);
            if ($uri->getPath() === '' || $uri->getPath() === '/') {
                throw new \RuntimeException('Work WeChat URL must have a path');
            }

            return new TargetResult(
                (string) $link->getAttribute('title'),
                (string) ($link->getAttribute('description') ?? ''),
                $link->getAttribute('icon') === null ? null : (string) $link->getAttribute('icon'),
                (string) $uri,
            );
        } catch (Throwable) {
            throw new LinkResolutionException(LinkError::WORK_WECHAT_EXTERNAL_ERROR, '企业微信跳转暂不可用', 502);
        }
    }
}
