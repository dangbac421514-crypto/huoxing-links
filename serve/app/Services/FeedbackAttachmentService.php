<?php

namespace App\Services;

use App\Models\FeedbackAttachment;
use App\Models\FeedbackTicket;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class FeedbackAttachmentService
{
    public const DISK = 'feedback_private';

    private const MAX_FILES = 3;

    /** @var array<string, string> */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const UNSUPPORTED_MESSAGE = '图片凭证格式不受支持';

    /**
     * @param  list<UploadedFile>  $files
     * @return Collection<int, FeedbackAttachment>
     */
    public function storeForTicket(FeedbackTicket $ticket, array $files): Collection
    {
        $files = array_values(array_filter(
            $files,
            static fn (mixed $file): bool => $file instanceof UploadedFile && $file->isValid(),
        ));

        if (count($files) > self::MAX_FILES) {
            throw ValidationException::withMessages([
                'attachments' => self::UNSUPPORTED_MESSAGE,
            ]);
        }

        $storedPaths = [];
        $created = collect();

        try {
            foreach ($files as $index => $file) {
                $inspected = $this->inspect($file, $index);
                $path = $ticket->user_id.'/'.$ticket->id.'/'.(string) Str::uuid().'.'.$inspected['extension'];
                $written = Storage::disk(self::DISK)->put($path, $this->contents($file));
                if ($written === false) {
                    throw new \RuntimeException('Failed to store feedback attachment.');
                }
                $storedPaths[] = $path;
                $created->push(FeedbackAttachment::query()->create([
                    'user_id' => $ticket->user_id,
                    'feedback_ticket_id' => $ticket->id,
                    'disk' => self::DISK,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime' => $inspected['mime'],
                    'size' => $inspected['size'],
                    'sha256' => $inspected['sha256'],
                ]));
            }
        } catch (Throwable $exception) {
            $this->deleteStored($storedPaths);
            throw $exception;
        }

        return $created;
    }

    /** @param list<string> $paths */
    public function deleteStored(array $paths): void
    {
        $disk = Storage::disk(self::DISK);
        foreach (array_unique($paths) as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }
            $disk->delete($path);
        }
    }

    /**
     * @return array{mime: string, extension: string, size: int, sha256: string}
     */
    private function inspect(UploadedFile $file, int $index): array
    {
        $pathname = $file->getRealPath();
        if (! is_string($pathname) || $pathname === '' || ! is_file($pathname)) {
            $this->reject($index);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($pathname);
        $extension = is_string($mime) ? (self::MIME_EXTENSIONS[$mime] ?? null) : null;
        if ($extension === null) {
            $this->reject($index);
        }

        $size = filesize($pathname);
        if ($size === false) {
            $this->reject($index);
        }

        $sha256 = hash_file('sha256', $pathname);
        if (! is_string($sha256) || strlen($sha256) !== 64) {
            throw new \RuntimeException('Failed to hash feedback attachment.');
        }

        return [
            'mime' => $mime,
            'extension' => $extension,
            'size' => $size,
            'sha256' => $sha256,
        ];
    }

    private function contents(UploadedFile $file): string
    {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw new \RuntimeException('Failed to read feedback attachment.');
        }

        return $contents;
    }

    private function reject(int $index): never
    {
        throw ValidationException::withMessages([
            'attachments.'.$index => self::UNSUPPORTED_MESSAGE,
        ]);
    }
}
