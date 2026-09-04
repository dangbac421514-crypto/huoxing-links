<?php

namespace App\DTO;

use App\Models\FeedbackTicket;

final readonly class FeedbackSubmissionResult
{
    public function __construct(
        public FeedbackTicket $ticket,
        public bool $created,
    ) {}
}
