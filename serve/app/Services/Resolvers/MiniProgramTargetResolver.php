<?php

namespace App\Services\Resolvers;

use App\Contracts\MiniProgramSchemeGenerator;
use App\DTO\TargetResult;
use App\DTO\VisitorContext;
use App\Enums\LinkType;
use App\Exceptions\LinkResolutionException;
use App\Exceptions\MiniProgramForbidden;
use App\Models\Link;
use App\Services\MiniProgramReferencePolicy;
use App\Services\WeixinSchemePolicy;
use App\Support\LinkError;
use App\Support\LinkTypeParser;
use Throwable;

final class MiniProgramTargetResolver implements TargetResolver
{
    public function __construct(
        private readonly MiniProgramReferencePolicy $minis,
        private readonly MiniProgramSchemeGenerator $schemes,
        private readonly WeixinSchemePolicy $schemePolicy,
    ) {}

    public function resolve(Link $link, VisitorContext $visitor): TargetResult
    {
        try {
            $this->assertType($link);
            $config = $link->getAttribute('config');
            if (! is_array($config)) {
                throw new \RuntimeException('invalid link configuration');
            }
            $code = $link->getAttribute('code');
            if (! is_string($code) || $code === '') {
                throw new \RuntimeException('invalid link code');
            }
            $miniId = $this->positiveInteger($config['min_id'] ?? null);
            if ($miniId === null) {
                throw new \RuntimeException('invalid mini-program reference');
            }

            $mini = $this->minis->assertAllowed($link->user, $miniId);
            $path = array_key_exists('url', $config) && $config['url'] !== null && $config['url'] !== ''
                ? $config['url']
                : $mini->getAttribute('url');
            if (! is_string($path) || ! $this->validPagePath($path)) {
                throw new \RuntimeException('invalid mini-program path');
            }

            $query = http_build_query(['code' => $code], '', '&', PHP_QUERY_RFC3986);
            $target = $this->schemePolicy->assert($this->schemes->generate($mini, $path, $query));

            return $this->result($link, $target);
        } catch (MiniProgramForbidden $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LinkResolutionException(LinkError::MINI_PROGRAM_EXTERNAL_ERROR, '小程序跳转暂不可用', 502);
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
        if ($type !== LinkType::MINI_PROGRAM) {
            throw new \RuntimeException('wrong link type');
        }
    }

    private function validPagePath(string $path): bool
    {
        return preg_match('/^pages\/[A-Za-z0-9_\/-]+$/D', $path) === 1
            && ! str_contains($path, '..')
            && ! str_contains($path, '//')
            && preg_match('/[\x00-\x20\x7f?#]/', $path) !== 1;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }
        $result = filter_var($value, FILTER_VALIDATE_INT);

        return $result === false ? null : (int) $result;
    }

    private function result(Link $link, string $target): TargetResult
    {
        return new TargetResult(
            (string) $link->getAttribute('title'),
            (string) ($link->getAttribute('description') ?? ''),
            $link->getAttribute('icon') === null ? null : (string) $link->getAttribute('icon'),
            $target,
        );
    }
}
