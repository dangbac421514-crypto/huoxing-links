<?php

namespace App\Services\Http;

use App\Exceptions\UnsafeUrl;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

final class CappedSinkStream implements StreamInterface
{
    use StreamDecoratorTrait { __construct as private initialize; }

    private const MAX_BYTES = 1048576;

    protected StreamInterface $stream;

    private int $written = 0;

    private bool $rejectedWrite = false;

    public function __construct()
    {
        $this->initialize(Utils::streamFor(Utils::tryFopen('php://temp', 'w+')));
    }

    public function write($string): int
    {
        if (! is_string($string)) {
            throw new UnsafeUrl;
        }

        $length = strlen($string);
        if ($length > self::MAX_BYTES - $this->written) {
            $this->rejectedWrite = true;
            throw new UnsafeUrl;
        }

        $written = $this->stream->write($string);
        $this->written += $written;

        return $written;
    }

    public function hasRejectedWrite(): bool
    {
        return $this->rejectedWrite;
    }
}
