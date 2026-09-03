<?php

namespace App\Services\Resolvers;

use App\Contracts\MiniProgramSchemeGenerator;
use App\DTO\TargetResult;
use App\DTO\VisitorContext;
use App\Enums\LinkType;
use App\Enums\MiniType;
use App\Exceptions\LinkResolutionException;
use App\Exceptions\MiniProgramForbidden;
use App\Exceptions\QrUnavailable;
use App\Models\Link;
use App\Services\LandingSelectionStore;
use App\Services\MiniProgramReferencePolicy;
use App\Services\QrRotationService;
use App\Services\VisitorTokenService;
use App\Services\WeixinSchemePolicy;
use App\Support\LinkError;
use App\Support\LinkTypeParser;
use Carbon\CarbonImmutable;
use Throwable;

final class LandingMiniTargetResolver implements TargetResolver
{
    private const PAGE = 'pages/views/tools/news';

    public function __construct(
        private readonly MiniProgramReferencePolicy $minis,
        private readonly QrRotationService $qrs,
        private readonly VisitorTokenService $tokens,
        private readonly LandingSelectionStore $selections,
        private readonly MiniProgramSchemeGenerator $schemes,
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
            if ($type !== LinkType::LANDING_MINI || $visitor->isAnonymous() || trim($visitor->visitorId) === '') {
                throw new \RuntimeException('landing visitor or link is invalid');
            }

            $config = $link->getAttribute('config');
            if (! is_array($config)) {
                throw new \RuntimeException('invalid landing configuration');
            }
            $code = $link->getAttribute('code');
            if (! is_string($code) || $code === '') {
                throw new \RuntimeException('invalid link code');
            }
            $miniId = $this->positiveInteger($config['min_id'] ?? null);
            if ($miniId === null) {
                throw new \RuntimeException('invalid landing mini reference');
            }
            $mini = $this->minis->assertAllowed($link->user, $miniId);
            if (! (bool) $mini->getAttribute('is_pre_min') || $mini->getAttribute('type') !== MiniType::LANDING) {
                throw new \RuntimeException('landing mini is not an official pool entry');
            }

            // One instant is shared by reservation, token expiry and the
            // cache's logical selection boundary.
            $at = CarbonImmutable::now('Asia/Shanghai');
            $selection = $this->qrs->reserve($link, $at);
            $token = $this->tokens->issue($code, $visitor->visitorId, $at->addMinutes(10));
            $query = http_build_query([
                'code' => $code,
                'visitor_token' => $token,
            ], '', '&', PHP_QUERY_RFC3986);
            $target = $this->schemePolicy->assert($this->schemes->generate($mini, self::PAGE, $query));

            $wx = $config['wx'] ?? [];
            $wx = is_array($wx) ? $wx : [];
            $this->selections->put($token, [
                'id' => $link->getKey(),
                'avatar' => $wx['avatar'] ?? null,
                'title' => $wx['title'] ?? $link->getAttribute('title'),
                'sub_title' => $wx['sub_title'] ?? $link->getAttribute('description'),
                'qr' => $selection->path,
                'path' => $selection->path,
                'name' => $selection->name,
                'sort' => $selection->sort,
            ]);

            return new TargetResult(
                (string) $link->getAttribute('title'),
                (string) ($link->getAttribute('description') ?? ''),
                $link->getAttribute('icon') === null ? null : (string) $link->getAttribute('icon'),
                $target,
                null,
                $token,
            );
        } catch (QrUnavailable|MiniProgramForbidden $exception) {
            throw $exception;
        } catch (LinkResolutionException $exception) {
            if ($exception->errorCode === LinkError::VISITOR_TOKEN_KEY_INVALID) {
                throw $exception;
            }

            throw new LinkResolutionException(LinkError::LANDING_MINI_EXTERNAL_ERROR, '落地小程序跳转暂不可用', 502);
        } catch (Throwable) {
            throw new LinkResolutionException(LinkError::LANDING_MINI_EXTERNAL_ERROR, '落地小程序跳转暂不可用', 502);
        }
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
}
