<?php

namespace App\DTO;

use Illuminate\Http\UploadedFile;

final readonly class FeedbackSubmissionData
{
    /** @param list<UploadedFile> $attachments */
    public function __construct(
        public string $category,
        public string $content,
        public ?string $contact,
        public string $idempotencyKey,
        public string $visitorHash,
        public array $attachments = [],
    ) {}
}
