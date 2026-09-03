<?php

namespace App\Services;

use App\DTO\QrSelection;
use App\Enums\LinkType;
use App\Enums\SwitchType;
use App\Enums\UVLimitType;
use App\Exceptions\QrUnavailable;
use App\Models\Link;
use App\Support\LinkTypeParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class QrRotationService
{
    private const TIMEZONE = 'Asia/Shanghai';

    private const MAX_SORT = 200;

    private const NO_SELECTION = '__QR_UNAVAILABLE__';

    /**
     * The candidate filtering, selection, counter increment, cursor update,
     * and daily TTL update must happen in one Redis transaction boundary.
     */
    private const RESERVATION_SCRIPT = <<<'LUA'
local mode = ARGV[1]
local now = tonumber(ARGV[2])
local candidate_count = tonumber(ARGV[3])
local scope = ARGV[4]
local last = tonumber(redis.call('GET', KEYS[2]) or '-1') or -1
local random_start = 0

if mode == 'random' then
    random_start = math.random(candidate_count) - 1
end

for offset = 0, candidate_count - 1 do
    local index
    if mode == 'sequence' then
        index = (last + offset + 1) % candidate_count
    else
        index = (random_start + offset) % candidate_count
    end

    local argument_index = 5 + (index * 3)
    local sort = ARGV[argument_index]
    local limit = tonumber(ARGV[argument_index + 1])
    local expires_at = tonumber(ARGV[argument_index + 2])
    local used = tonumber(redis.call('HGET', KEYS[1], sort) or '0') or 0

    if (expires_at == 0 or expires_at > now) and (limit < 0 or used < limit) then
        redis.call('HINCRBY', KEYS[1], sort, 1)
        redis.call('SET', KEYS[2], index)
        if scope == 'daily' then
            redis.call('EXPIRE', KEYS[1], 172800)
            redis.call('EXPIRE', KEYS[2], 172800)
        else
            redis.call('PERSIST', KEYS[1])
            redis.call('PERSIST', KEYS[2])
        end
        return sort
    end
end

return '__QR_UNAVAILABLE__'
LUA;

    public function reserve(Link $link, CarbonImmutable $at): QrSelection
    {
        try {
            $specification = $this->specification($link, $at);
            $candidates = $specification['candidates'];
            $scope = $specification['scope'];
            $counterKey = 'link:qr:'.$link->getKey().':'.($scope === 'accumulate' ? 'accumulate' : $scope);
            $cursorKey = 'link:qr:'.$link->getKey().':cursor:'.$scope;

            $arguments = [
                $specification['mode'],
                (string) $at->getTimestamp(),
                (string) count($candidates),
                $scope === 'accumulate' ? 'accumulate' : 'daily',
            ];
            foreach ($candidates as $candidate) {
                $arguments[] = (string) $candidate['sort'];
                $arguments[] = (string) $candidate['limit'];
                $arguments[] = (string) $candidate['expires_at'];
            }

            $result = Redis::connection('default')->eval(
                self::RESERVATION_SCRIPT,
                2,
                $counterKey,
                $cursorKey,
                ...$arguments,
            );

            if ($result === false || $result === null || $result === self::NO_SELECTION) {
                throw new QrUnavailable;
            }

            $sort = $this->returnedSort($result, $candidates);
            if ($sort === null) {
                throw new QrUnavailable;
            }

            return new QrSelection(
                $sort,
                $specification['by_sort'][$sort]['path'],
                $specification['by_sort'][$sort]['name'],
            );
        } catch (QrUnavailable $exception) {
            throw $exception;
        } catch (Throwable) {
            // Malformed persisted configuration and infrastructure errors both
            // fail closed without echoing configuration or Redis details.
            throw new QrUnavailable;
        }
    }

    public function forget(Link $link): void
    {
        $id = $link->getKey();
        if (! is_int($id) && ! (is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id))) {
            return;
        }

        $base = 'link:qr:'.$id;
        Redis::connection('default')->del(
            $base.':accumulate',
            $base.':cursor:accumulate',
        );
    }

    /**
     * Validate landing QR configuration without reserving a code or reading
     * counters. A configured, non-expired candidate is enough for health;
     * whether its counter is currently full is a runtime availability concern.
     */
    public function configurationHealthy(Link $link, CarbonImmutable $at): bool
    {
        try {
            $specification = $this->specification($link, $at);
            foreach ($specification['candidates'] as $candidate) {
                if ($candidate['expires_at'] === 0 || $candidate['expires_at'] > $at->getTimestamp()) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /**
     * @return array{
     *     mode: string,
     *     scope: string,
     *     candidates: list<array{sort:int, limit:int, expires_at:int, path:string, name:?string}>,
     *     by_sort: array<int, array{sort:int, limit:int, expires_at:int, path:string, name:?string}>
     * }
     */
    private function specification(Link $link, CarbonImmutable $at): array
    {
        if (! $link->exists || ! $link->getKey()) {
            throw new QrUnavailable;
        }

        if (LinkTypeParser::parse($link->getRawOriginal('type')) !== LinkType::LANDING_MINI) {
            throw new QrUnavailable;
        }

        $config = $link->getAttribute('config');
        $wx = is_array($config) ? ($config['wx'] ?? null) : null;
        if (! is_array($wx)) {
            throw new QrUnavailable;
        }

        $switchType = $this->enumValue($wx['switch_type'] ?? null, SwitchType::class);
        $limitType = $this->enumValue($wx['uv_limit_type'] ?? null, UVLimitType::class);
        $qrCodes = $wx['qr'] ?? null;
        if ($switchType === null || $limitType === null || ! is_array($qrCodes) || $qrCodes === []) {
            throw new QrUnavailable;
        }

        $bySort = [];
        foreach ($qrCodes as $qrCode) {
            if (! is_array($qrCode)) {
                throw new QrUnavailable;
            }

            $sort = $this->integerValue($qrCode['sort'] ?? null);
            if ($sort === null || $sort < 0 || $sort > self::MAX_SORT || array_key_exists($sort, $bySort)) {
                throw new QrUnavailable;
            }

            $path = $qrCode['path'] ?? null;
            if (! is_string($path) || trim($path) === '') {
                throw new QrUnavailable;
            }

            $name = $qrCode['name'] ?? null;
            if ($name !== null && ! is_scalar($name)) {
                throw new QrUnavailable;
            }

            $limit = $this->limitValue($qrCode);
            $expiresAt = $this->expiryValue($qrCode);
            if ($limit === null || $expiresAt === null) {
                throw new QrUnavailable;
            }

            $bySort[$sort] = [
                'sort' => $sort,
                'limit' => $limit,
                'expires_at' => $expiresAt,
                'path' => $path,
                'name' => $name === null ? null : (string) $name,
            ];
        }

        ksort($bySort, SORT_NUMERIC);
        $localAt = $at->setTimezone(self::TIMEZONE);
        $scope = $limitType === UVLimitType::DAILY->value
            ? 'daily:'.$localAt->format('Ymd')
            : 'accumulate';

        return [
            'mode' => $switchType === SwitchType::RANDOM->value ? 'random' : 'sequence',
            'scope' => $scope,
            'candidates' => array_values($bySort),
            'by_sort' => $bySort,
        ];
    }

    /** @param array<string, mixed> $candidate */
    private function limitValue(array $candidate): ?int
    {
        if (! array_key_exists('uv_limit_num', $candidate) || $candidate['uv_limit_num'] === null || $candidate['uv_limit_num'] === '') {
            return -1;
        }

        $limit = $this->integerValue($candidate['uv_limit_num']);

        return $limit !== null && $limit >= 1 ? $limit : null;
    }

    /** @param array<string, mixed> $candidate */
    private function expiryValue(array $candidate): ?int
    {
        if (! array_key_exists('expired_at', $candidate) || $candidate['expired_at'] === null || $candidate['expired_at'] === '') {
            return 0;
        }

        $raw = $candidate['expired_at'];
        if (! is_string($raw) || ! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $raw)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $raw, self::TIMEZONE);
        $errors = CarbonImmutable::getLastErrors();
        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $raw
        ) {
            return null;
        }

        return $date->addDay()->startOfDay()->getTimestamp();
    }

    private function enumValue(mixed $value, string $enumClass): ?int
    {
        $integer = $this->integerValue($value);
        if ($integer === null) {
            return null;
        }

        return $enumClass::tryFrom($integer)?->value;
    }

    private function integerValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || ! preg_match('/^-?[0-9]+$/D', $value)) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false ? null : (int) $integer;
    }

    /**
     * @param  list<array{sort:int, limit:int, expires_at:int, path:string, name:?string}>  $candidates
     */
    private function returnedSort(mixed $result, array $candidates): ?int
    {
        $sort = $this->integerValue(is_int($result) ? $result : (is_string($result) ? $result : null));
        if ($sort === null) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if ($candidate['sort'] === $sort) {
                return $sort;
            }
        }

        return null;
    }
}
