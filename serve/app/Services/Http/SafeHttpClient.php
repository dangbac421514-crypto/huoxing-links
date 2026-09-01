<?php

namespace App\Services\Http;

use App\Exceptions\UnsafeUrl;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Throwable;

final class SafeHttpClient
{
    private const MAX_BODY_BYTES = 1048576;

    private const MAX_REDIRECTS = 3;

    public function __construct(private readonly UrlPolicy $policy) {}

    /** @param list<mixed> $allowedHosts */
    public function getText(string $url, array $allowedHosts): string
    {
        return $this->readBody($this->request($url, $allowedHosts));
    }

    /** @param list<mixed> $allowedHosts */
    public function getJson(string $url, array $allowedHosts): array
    {
        $body = $this->getText($url, $allowedHosts);
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new UnsafeUrl;
        }

        if (! is_array($decoded)) {
            throw new UnsafeUrl;
        }

        return $decoded;
    }

    /** @param list<mixed> $allowedHosts */
    private function request(string $url, array $allowedHosts): Response
    {
        $uri = $this->policy->assertExternal($url, $allowedHosts);
        $redirects = 0;

        while (true) {
            $response = $this->send((string) $uri);
            $status = $response->status();
            if (! in_array($status, [301, 302, 303, 307, 308], true)) {
                if ($status < 200 || $status >= 300) {
                    $response->close();
                    throw new UnsafeUrl;
                }

                return $response;
            }

            if ($redirects >= self::MAX_REDIRECTS) {
                $response->close();
                throw new UnsafeUrl;
            }

            $location = $response->header('Location');
            $response->close();
            if ($location === '' || preg_match('/[\x00-\x20\x7f]/', $location) === 1 || preg_match('/%(?![0-9a-fA-F]{2})/', $location) === 1) {
                throw new UnsafeUrl;
            }

            try {
                $uri = $this->policy->assertExternal(
                    (string) UriResolver::resolve($uri, new Uri($location)),
                    $allowedHosts,
                );
            } catch (UnsafeUrl $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new UnsafeUrl;
            }
            $redirects++;
        }
    }

    private function send(string $url): Response
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $response = Http::connectTimeout(3)
                    ->timeout(8)
                    ->withoutRedirecting()
                    ->withOptions(['stream' => true])
                    ->get($url);
            } catch (ConnectionException|TransferException) {
                if ($attempt < 2) {
                    continue;
                }
                throw new UnsafeUrl;
            } catch (Throwable) {
                throw new UnsafeUrl;
            }

            if ($response->status() >= 500) {
                $response->close();
                if ($attempt < 2) {
                    continue;
                }
                throw new UnsafeUrl;
            }

            return $response;
        }

        throw new UnsafeUrl;
    }

    private function readBody(Response $response): string
    {
        try {
            $length = $response->header('Content-Length');
            if ($length !== '') {
                if (! ctype_digit($length) || strlen($length) > 7 || (int) $length > self::MAX_BODY_BYTES) {
                    $response->close();
                    throw new UnsafeUrl;
                }
            }

            $stream = $response->toPsrResponse()->getBody();
            $body = $this->readBoundedStream($stream);
            $response->close();

            return $body;
        } catch (UnsafeUrl $exception) {
            $response->close();
            throw $exception;
        } catch (Throwable) {
            $response->close();
            throw new UnsafeUrl;
        }
    }

    private function readBoundedStream(StreamInterface $stream): string
    {
        $body = '';
        while (! $stream->eof()) {
            $chunk = $stream->read(min(8192, self::MAX_BODY_BYTES + 1 - strlen($body)));
            if ($chunk === '') {
                if ($stream->eof()) {
                    break;
                }
                throw new UnsafeUrl;
            }
            $body .= $chunk;
            if (strlen($body) > self::MAX_BODY_BYTES) {
                throw new UnsafeUrl;
            }
        }

        return $body;
    }
}
