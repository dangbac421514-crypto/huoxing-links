<?php

namespace Tests\Support;

final class DeterministicCaptchaBuilderFactory
{
    public function __construct(private readonly string $answer) {}

    public function __invoke(?string $phrase = null): DeterministicCaptchaBuilder
    {
        return new DeterministicCaptchaBuilder($phrase ?? $this->answer);
    }
}

final class DeterministicCaptchaBuilder
{
    private string $imageType = 'jpeg';

    public function __construct(private readonly string $phrase) {}

    public function setImageType(?string $imageType = null): self
    {
        $this->imageType = (string) $imageType;

        return $this;
    }

    public function build(): self
    {
        return $this;
    }

    public function getPhrase(): string
    {
        return $this->phrase;
    }

    public function inline(): string
    {
        return 'data:image/'.$this->imageType.';base64,'.base64_encode('deterministic-captcha');
    }
}
