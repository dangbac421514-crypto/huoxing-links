<?php

namespace App\Services;

use App\Enums\FeedbackTicketStatus;
use App\Exceptions\BusinessRuleException;
use App\Support\FeedbackError;

final class FeedbackTicketStateMachine
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        FeedbackTicketStatus::PENDING->value => [
            FeedbackTicketStatus::PROCESSING->value,
        ],
        FeedbackTicketStatus::PROCESSING->value => [
            FeedbackTicketStatus::RESOLVED->value,
        ],
        FeedbackTicketStatus::RESOLVED->value => [
            FeedbackTicketStatus::CLOSED->value,
            FeedbackTicketStatus::PROCESSING->value,
        ],
        FeedbackTicketStatus::CLOSED->value => [],
    ];

    public static function assertTransition(FeedbackTicketStatus $from, FeedbackTicketStatus $to): void
    {
        $allowed = self::ALLOWED[$from->value] ?? [];
        if (! in_array($to->value, $allowed, true)) {
            throw new BusinessRuleException(FeedbackError::INVALID_STATE, '工单状态流转无效', 422);
        }
    }
}
