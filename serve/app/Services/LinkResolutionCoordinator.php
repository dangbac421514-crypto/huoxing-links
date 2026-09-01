<?php

namespace App\Services;

use App\DTO\AccessDecision;
use App\DTO\CoordinatorResponse;
use App\DTO\TargetResult;
use App\DTO\VisitorContext;
use App\DTO\VisitorIdentity;
use App\Enums\LinkType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\LinkResolutionException;
use App\Exceptions\QrUnavailable;
use App\Models\Link;
use App\Services\Resolvers\TargetResolverRegistry;
use App\Support\LinkError;
use App\Support\LinkTypeParser;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Single orchestration boundary for the two public link endpoints.
 *
 * Policy and account UV accounting run before any cache/provider operation.
 * Resolver output is reduced to the public TargetResult fields before it is
 * cached or written to a visit log.
 */
final class LinkResolutionCoordinator
{
    private const GENERIC_ERROR = 'LINK_RESOLUTION_ERROR';

    private const MAX_VISITOR_TOKEN_LENGTH = 4096;

    /** @var array<string, string> */
    private const SAFE_MESSAGES = [
        LinkError::LINK_NOT_FOUND => '链接不存在',
        LinkError::LINK_DISABLED => '链接暂不可用',
        LinkError::USER_DISABLED => '账号已停用',
        LinkError::MEMBERSHIP_EXPIRED => '会员已到期',
        LinkError::QUOTA_EXCEEDED => '当前访问已达上限',
        LinkError::MINI_PROGRAM_FORBIDDEN => '无权使用该小程序',
        LinkError::LINK_TYPE_FORBIDDEN => '当前套餐不支持该链接类型',
        LinkError::LINK_TYPE_UNSUPPORTED => '暂不支持该链接类型',
        LinkError::VISITOR_TOKEN_INVALID => '访问凭证无效或已过期',
        LinkError::QR_UNAVAILABLE => '当前暂无可用二维码',
        LinkError::UNSAFE_URL => '目标地址暂不可用',
        LinkError::MINI_PROGRAM_EXTERNAL_ERROR => '小程序跳转暂不可用',
        LinkError::KING_DOC_EXTERNAL_ERROR => '金山文档跳转暂不可用',
        LinkError::CLI_QR_EXTERNAL_ERROR => '草料二维码跳转暂不可用',
        LinkError::WORK_WECHAT_EXTERNAL_ERROR => '企业微信跳转暂不可用',
        LinkError::LANDING_MINI_EXTERNAL_ERROR => '落地小程序跳转暂不可用',
        LinkError::QQ_QR_EXTERNAL_ERROR => '腾讯优码跳转暂不可用',
        LinkError::VISITOR_TOKEN_KEY_INVALID => '服务配置暂不可用',
        LinkError::VISITOR_HASH_KEY_INVALID => '服务配置暂不可用',
        LinkError::VISITOR_ID_INVALID => '服务配置暂不可用',
        self::GENERIC_ERROR => '链接暂时无法访问，请稍后重试',
    ];

    /** @var array<string, int> */
    private const SAFE_STATUSES = [
        LinkError::LINK_NOT_FOUND => 404,
        LinkError::LINK_DISABLED => 403,
        LinkError::USER_DISABLED => 403,
        LinkError::MEMBERSHIP_EXPIRED => 403,
        LinkError::MINI_PROGRAM_FORBIDDEN => 403,
        LinkError::LINK_TYPE_FORBIDDEN => 403,
        LinkError::LINK_TYPE_UNSUPPORTED => 422,
        LinkError::QUOTA_EXCEEDED => 429,
        LinkError::VISITOR_TOKEN_INVALID => 422,
        LinkError::QR_UNAVAILABLE => 422,
        LinkError::UNSAFE_URL => 502,
        LinkError::MINI_PROGRAM_EXTERNAL_ERROR => 502,
        LinkError::KING_DOC_EXTERNAL_ERROR => 502,
        LinkError::CLI_QR_EXTERNAL_ERROR => 502,
        LinkError::WORK_WECHAT_EXTERNAL_ERROR => 502,
        LinkError::LANDING_MINI_EXTERNAL_ERROR => 502,
        LinkError::QQ_QR_EXTERNAL_ERROR => 502,
        LinkError::VISITOR_TOKEN_KEY_INVALID => 500,
        LinkError::VISITOR_HASH_KEY_INVALID => 500,
        LinkError::VISITOR_ID_INVALID => 500,
        self::GENERIC_ERROR => 500,
    ];

    public function __construct(
        private readonly LinkAccessPolicy $policy,
        private readonly VisitorIdentityService $identity,
        private readonly UsageMeter $usageMeter,
        private readonly TargetResolverRegistry $resolvers,
        private readonly LandingSelectionStore $selections,
        private readonly VisitorTokenService $tokens,
        private readonly SanitizedLinkVisitRecorder $visits,
        private readonly PublicTargetCache $cache,
    ) {}

    public function target(Request $request, string $code): CoordinatorResponse
    {
        $link = Link::query()->where('code', $code)->first();
        if (! $link) {
            return $this->error(LinkError::LINK_NOT_FOUND);
        }

        $at = CarbonImmutable::now('Asia/Shanghai');
        try {
            $decision = $this->policy->check($link, $at);
        } catch (Throwable $exception) {
            return $this->unexpected($link, $exception);
        }

        if (! $decision->allowed) {
            return $this->decisionError($link, $decision);
        }

        $identity = null;
        try {
            $identity = $this->identity->resolve($request);
            $this->assertIdentity($identity);

            $owner = $link->user;
            if (! $owner) {
                throw new LinkResolutionException(LinkError::USER_DISABLED, '账号已停用', 403);
            }

            // Account UV is intentionally consumed before cache/provider work.
            $this->usageMeter->consume($owner, $identity->visitorId, $at);

            $type = LinkTypeParser::parse($link->getRawOriginal('type'));
            if (! $type) {
                throw new LinkResolutionException(LinkError::LINK_TYPE_UNSUPPORTED, '链接类型暂不支持', 422);
            }

            $publicTarget = null;
            $resolved = null;
            if ($type !== LinkType::LANDING_MINI) {
                $publicTarget = $this->cache->get($link);
            }

            if ($publicTarget === null) {
                $resolved = $this->resolvers->for($type)->resolve(
                    $link,
                    VisitorContext::fromIdentity($identity),
                );
                $publicTarget = $this->visits->publicTarget($resolved);
                $this->assertPublicTarget($publicTarget);

                if ($type !== LinkType::LANDING_MINI) {
                    $this->cache->put($link, $publicTarget);
                }
            }

            if ($type === LinkType::LANDING_MINI) {
                if (! $resolved instanceof TargetResult || ! is_string($resolved->visitorToken) || $resolved->visitorToken === '') {
                    throw new LinkResolutionException(LinkError::LANDING_MINI_EXTERNAL_ERROR, '落地小程序跳转暂不可用', 502);
                }
                $publicTarget['visitorToken'] = $resolved->visitorToken;
            }

            $this->assertPublicTarget($publicTarget);
            $this->visits->createSanitized(
                (int) $link->getKey(),
                (int) $owner->getKey(),
                $identity->hash,
                $this->identity->hashForLog('ip', $request->getClientIp()),
                $this->identity->hashForLog('user_agent', $request->userAgent()),
                $publicTarget,
            );

            return $this->withIdentityCookie(CoordinatorResponse::success($publicTarget), $identity);
        } catch (BusinessRuleException|LinkResolutionException $exception) {
            if ($this->isKnownException($exception)) {
                return $this->withIdentityCookie($this->knownException($exception), $identity);
            }

            return $this->withIdentityCookie($this->unexpected($link, $exception), $identity);
        } catch (Throwable $exception) {
            return $this->withIdentityCookie($this->unexpected($link, $exception), $identity);
        }
    }

    public function showQr(Request $request, string $code): CoordinatorResponse
    {
        $link = Link::query()->where('code', $code)->first();
        if (! $link) {
            return $this->error(LinkError::LINK_NOT_FOUND);
        }

        $at = CarbonImmutable::now('Asia/Shanghai');
        try {
            $decision = $this->policy->check($link, $at);
        } catch (Throwable $exception) {
            return $this->unexpected($link, $exception);
        }
        if (! $decision->allowed) {
            return $this->decisionError($link, $decision);
        }

        $type = LinkTypeParser::parse($link->getRawOriginal('type'));
        if ($type !== LinkType::LANDING_MINI) {
            return $this->error(LinkError::LINK_TYPE_FORBIDDEN);
        }

        try {
            $query = $request->query();
            if (
                count($query) !== 1
                || ! array_key_exists('visitor_token', $query)
                || ! is_string($query['visitor_token'])
                || $query['visitor_token'] === ''
                || strlen($query['visitor_token']) > self::MAX_VISITOR_TOKEN_LENGTH
            ) {
                throw new LinkResolutionException(LinkError::VISITOR_TOKEN_INVALID, '访问凭证无效或已过期', 422);
            }

            $token = $query['visitor_token'];
            $this->tokens->verify($token, $code, $at);
            $selection = $this->selections->get($token);
            if (! is_array($selection) || (int) ($selection['id'] ?? 0) !== (int) $link->getKey()) {
                throw new QrUnavailable;
            }

            return CoordinatorResponse::success($this->showQrData($link, $selection));
        } catch (BusinessRuleException|LinkResolutionException $exception) {
            if ($this->isKnownException($exception)) {
                return $this->knownException($exception);
            }

            return $this->unexpected($link, $exception);
        } catch (Throwable $exception) {
            return $this->unexpected($link, $exception);
        }
    }

    private function assertIdentity(VisitorIdentity $identity): void
    {
        if (
            ! Str::isUuid($identity->visitorId)
            || $identity->visitorId === 'anonymous'
            || preg_match('/\A[0-9a-f]{64}\z/i', $identity->hash) !== 1
        ) {
            throw new LinkResolutionException(LinkError::VISITOR_ID_INVALID, 'Visitor identity is invalid.', 500);
        }
    }

    /** @param array<string, scalar|null> $publicTarget */
    private function assertPublicTarget(array $publicTarget): void
    {
        foreach (['title', 'description', 'icon', 'target'] as $field) {
            if (! array_key_exists($field, $publicTarget) || ! (is_scalar($publicTarget[$field]) || $publicTarget[$field] === null)) {
                throw new LinkResolutionException(self::GENERIC_ERROR, 'Invalid public target.', 500);
            }
        }
        if (! is_string($publicTarget['target']) || $publicTarget['target'] === '') {
            throw new LinkResolutionException(self::GENERIC_ERROR, 'Invalid public target.', 500);
        }
    }

    private function decisionError(Link $link, AccessDecision $decision): CoordinatorResponse
    {
        $code = $decision->errorCode ?: self::GENERIC_ERROR;
        if ($code === self::GENERIC_ERROR || ! array_key_exists($code, self::SAFE_STATUSES)) {
            return $this->unexpected($link, new \RuntimeException('Unknown access decision.'));
        }

        return $this->error($code);
    }

    private function knownException(BusinessRuleException|LinkResolutionException $exception): CoordinatorResponse
    {
        $code = match ($exception->errorCode) {
            'NO_ENTITLEMENT' => LinkError::MEMBERSHIP_EXPIRED,
            'INVALID_VISITOR' => LinkError::VISITOR_ID_INVALID,
            default => $exception->errorCode,
        };

        return $this->error($code, self::SAFE_STATUSES[$code]);
    }

    private function isKnownException(BusinessRuleException|LinkResolutionException $exception): bool
    {
        return $exception->errorCode !== self::GENERIC_ERROR
            && (array_key_exists($exception->errorCode, self::SAFE_STATUSES)
                || in_array($exception->errorCode, ['NO_ENTITLEMENT', 'INVALID_VISITOR'], true));
    }

    private function error(string $code, ?int $status = null): CoordinatorResponse
    {
        $code = array_key_exists($code, self::SAFE_STATUSES) ? $code : self::GENERIC_ERROR;
        $status ??= self::SAFE_STATUSES[$code];

        return CoordinatorResponse::error($code, $status, self::SAFE_MESSAGES[$code]);
    }

    private function unexpected(Link $link, Throwable $exception): CoordinatorResponse
    {
        Log::error('link_resolution.unexpected', [
            'link_id' => (int) $link->getKey(),
            'exception_class' => $exception::class,
        ]);

        return $this->error(self::GENERIC_ERROR, 500);
    }

    private function withIdentityCookie(CoordinatorResponse $response, ?VisitorIdentity $identity): CoordinatorResponse
    {
        if (! $identity?->setCookie) {
            return $response;
        }

        try {
            return $response->withCookie($this->identity->cookie($identity));
        } catch (Throwable) {
            // An invalid cookie cannot be allowed to replace the stable
            // business response with an internal exception.
            return $response;
        }
    }

    /** @param array<string, scalar|null> $selection */
    private function showQrData(Link $link, array $selection): array
    {
        $qr = $selection['qr'] ?? ($selection['path'] ?? null);
        if (! is_string($qr) || trim($qr) === '') {
            throw new QrUnavailable;
        }

        return [
            'id' => (int) $link->getKey(),
            'avatar' => $this->publicAsset($selection['avatar'] ?? null),
            'title' => $this->safeString($selection['title'] ?? null),
            'sub_title' => $this->safeString($selection['sub_title'] ?? null),
            'qr' => $this->publicAsset($qr),
        ];
    }

    private function safeString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function publicAsset(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }
        if (preg_match('~^(?:https?:)?//~i', $value) === 1) {
            return $value;
        }

        return Storage::url($value);
    }
}
