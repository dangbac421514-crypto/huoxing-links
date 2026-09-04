<?php

namespace Tests\Feature;

use App\Models\FeedbackAttachment;
use App\Models\FeedbackTicket;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Exception;
use RuntimeException;
use Tests\Concerns\CreatesFeedbackFixtures;
use Tests\TestCase;

final class FeedbackAttachmentTest extends TestCase
{
    use CreatesFeedbackFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('feedback_private');
        Queue::fake();
    }

    public function test_valid_image_is_private_and_failed_batch_leaves_no_ticket_or_file(): void
    {
        $channel = $this->feedbackChannelFor($this->activeFeedbackUser());
        $valid = UploadedFile::fake()->image('evidence.png', 800, 600)->size(100);
        $response = $this->postToChannelHost($channel, $this->validSubmission(['attachments' => [$valid]]))->assertCreated();
        $attachment = FeedbackAttachment::query()->firstOrFail();
        Storage::disk('feedback_private')->assertExists($attachment->path);
        $this->assertStringNotContainsString('evidence.png', $attachment->getRawOriginal('original_name'));

        $this->assertSame('evidence.png', $attachment->original_name);
        $this->assertArrayNotHasKey('original_name', $attachment->toArray());
        $this->assertSame('feedback_private', $attachment->disk);
        $this->assertSame('image/png', $attachment->mime);
        $this->assertSame($channel->user_id, $attachment->user_id);
        $ticket = FeedbackTicket::query()->firstOrFail();
        $this->assertSame($ticket->id, $attachment->feedback_ticket_id);
        $this->assertMatchesRegularExpression(
            sprintf('#^%d/%d/[0-9a-f-]{36}\.png$#', $ticket->user_id, $ticket->id),
            $attachment->path,
        );
        $this->assertStringNotContainsString('evidence.png', $attachment->path);
        $stored = Storage::disk('feedback_private')->get($attachment->path);
        $this->assertSame(strlen($stored), $attachment->size);
        $this->assertSame(hash('sha256', $stored), $attachment->sha256);
        $body = $response->getContent();
        $this->assertStringNotContainsString('/storage/', $body);
        $this->assertStringNotContainsString($attachment->path, $body);
        $this->assertStringNotContainsString('evidence.png', $body);
        $response->assertJsonMissingPath('path')
            ->assertJsonMissingPath('url')
            ->assertJsonMissingPath('original_name')
            ->assertJsonMissingPath('attachments');
        Queue::assertNothingPushed();
    }

    public function test_fourth_file_is_rejected_without_ticket_or_file(): void
    {
        $files = [];
        for ($index = 1; $index <= 4; $index++) {
            $files[] = UploadedFile::fake()->image('evidence-'.$index.'.png', 40, 40);
        }
        $this->assertAttachmentRejected($files, 'attachments');
    }

    public function test_file_larger_than_five_megabytes_is_rejected(): void
    {
        $oversized = UploadedFile::fake()->image('evidence.png', 80, 60)->size(5121);
        $this->assertAttachmentRejected([$oversized]);
    }

    public function test_svg_renamed_as_png_is_rejected(): void
    {
        $svg = UploadedFile::fake()->createWithContent(
            'evidence.png',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            .'<script>alert(1)</script></svg>',
        );
        $this->assertAttachmentRejected([$svg]);
    }

    public function test_html_upload_is_rejected(): void
    {
        $html = UploadedFile::fake()->createWithContent(
            'page.html',
            '<html><body><p>not an image</p></body></html>',
        );
        $this->assertAttachmentRejected([$html]);
    }

    public function test_html_renamed_as_png_is_rejected(): void
    {
        $html = UploadedFile::fake()->createWithContent(
            'evidence.png',
            '<html><body><p>not an image</p></body></html>',
        );
        $this->assertAttachmentRejected([$html]);
    }

    public function test_image_with_6001px_dimension_is_rejected(): void
    {
        $huge = UploadedFile::fake()->image('evidence.png', 6001, 10);
        $this->assertAttachmentRejected([$huge]);
    }

    public function test_php_rejected_upload_is_unprocessable_and_creates_no_rows(): void
    {
        $invalid = new UploadedFile(
            '/dev/null',
            'evidence.png',
            'image/png',
            UPLOAD_ERR_INI_SIZE,
            true,
        );
        $this->assertFalse($invalid->isValid());
        $this->assertSame(UPLOAD_ERR_INI_SIZE, $invalid->getError());
        $this->assertAttachmentRejected([$invalid]);
    }

    public function test_second_file_storage_failure_rolls_back_ticket_and_deletes_first_file(): void
    {
        $real = Storage::disk('feedback_private');
        $writes = 0;
        $mock = Mockery::mock(Filesystem::class);
        $mock->shouldReceive('put')->andReturnUsing(function ($path, $contents, $options = []) use ($real, &$writes) {
            $writes++;
            if ($writes === 2) {
                throw new RuntimeException('simulated storage failure');
            }

            return $real->put($path, $contents, $options);
        });
        $mock->shouldReceive('delete')->andReturnUsing(fn ($path) => $real->delete($path));
        Storage::set('feedback_private', $mock);

        $channel = $this->feedbackChannelFor($this->activeFeedbackUser());
        $first = UploadedFile::fake()->image('one.png', 40, 40);
        $second = UploadedFile::fake()->image('two.png', 40, 40);

        try {
            $this->withoutExceptionHandling();
            $this->postToChannelHost($channel, $this->validSubmission([
                'attachments' => [$first, $second],
            ]));
            $this->fail('Expected the second stored file to fail.');
        } catch (Exception $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated storage failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('feedback_tickets', 0);
        $this->assertDatabaseCount('feedback_attachments', 0);
        $this->assertDatabaseCount('feedback_events', 0);
        $this->assertSame([], $real->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_idempotent_replay_does_not_store_duplicate_attachments(): void
    {
        $channel = $this->feedbackChannelFor($this->activeFeedbackUser());
        $payload = $this->validSubmission([
            'attachments' => [UploadedFile::fake()->image('evidence.png', 80, 60)],
        ]);
        $this->postToChannelHost($channel, $payload)->assertCreated();
        $this->postToChannelHost($channel, $payload)->assertOk();
        $this->assertDatabaseCount('feedback_tickets', 1);
        $this->assertDatabaseCount('feedback_attachments', 1);
        $this->assertCount(1, Storage::disk('feedback_private')->allFiles());
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function assertAttachmentRejected(array $files, string $errorKey = 'attachments.0'): void
    {
        $channel = $this->feedbackChannelFor($this->activeFeedbackUser());
        $this->postToChannelHost($channel, $this->validSubmission(['attachments' => $files]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorKey]);
        $this->assertDatabaseCount('feedback_tickets', 0);
        $this->assertDatabaseCount('feedback_attachments', 0);
        $this->assertDatabaseCount('feedback_events', 0);
        Storage::disk('feedback_private')->assertEmpty();
        Queue::assertNothingPushed();
    }
}
