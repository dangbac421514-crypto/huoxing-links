<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Domain;
use App\Models\FeedbackChannel;
use App\Support\FeedbackError;

final class FeedbackShareUrl
{
    public function __construct(private readonly ShareOriginPolicy $origins) {}

    public function for(FeedbackChannel $channel): string
    {
        $channel->loadMissing('domain');
        $domain = $channel->domain;
        if (! $domain instanceof Domain || ! (bool) $domain->enable) {
            throw new BusinessRuleException(FeedbackError::DOMAIN_UNAVAILABLE, '所选域名暂不可用', 503);
        }

        $origin = $this->origins->forDomain($domain);
        if ($origin === null) {
            throw new BusinessRuleException(FeedbackError::DOMAIN_UNAVAILABLE, '所选域名暂不可用', 503);
        }

        return $origin.'/f/'.$channel->getAttribute('code');
    }
}
