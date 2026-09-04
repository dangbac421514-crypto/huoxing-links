<?php

namespace App\Console\Commands;

use App\Models\FeedbackTicket;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToDeleteFile;
use Throwable;

final class PurgeExpiredFeedback extends Command
{
    protected $signature = 'app:feedback-purge';

    protected $description = '清理超过保存期限的客诉附件和个人信息';

    /** Independent of channel retention; platform privacy rule. */
    private const VISITOR_HASH_RETENTION_DAYS = 30;

    private const CHUNK_SIZE = 100;

    public function handle(): int
    {
        $now = CarbonImmutable::now((string) config('app.timezone', 'Asia/Shanghai'));

        $this->nullExpiredVisitorHashes($now);

        $purged = 0;
        $failures = 0;
        FeedbackTicket::query()
            ->with(['channel', 'attachments'])
            ->whereNull('anonymized_at')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($tickets) use ($now, &$purged, &$failures): void {
                foreach ($tickets as $ticket) {
                    if (! $this->isPastRetention($ticket, $now)) {
                        continue;
                    }
                    if ($this->purgeTicket($ticket, $now)) {
                        $purged++;
                    } else {
                        $failures++;
                    }
                }
            });

        $summary = "purged={$purged} failed={$failures}";
        if ($failures > 0) {
            $this->error($summary);

            return self::FAILURE;
        }

        $this->info($summary);

        return self::SUCCESS;
    }

    private function nullExpiredVisitorHashes(CarbonImmutable $now): void
    {
        $cutoff = $now->subDays(self::VISITOR_HASH_RETENTION_DAYS);
        FeedbackTicket::query()
            ->whereNotNull('visitor_hash')
            ->where('submitted_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($tickets): void {
                FeedbackTicket::query()
                    ->whereIn('id', $tickets->pluck('id'))
                    ->update(['visitor_hash' => null]);
            });
    }

    private function isPastRetention(FeedbackTicket $ticket, CarbonImmutable $now): bool
    {
        $channel = $ticket->channel;
        if ($channel === null) {
            return false;
        }

        $cutoff = $now->subDays((int) $channel->retention_days);
        $submittedAt = $ticket->submitted_at;
        if ($submittedAt === null) {
            return false;
        }

        return $submittedAt->lte($cutoff);
    }

    private function purgeTicket(FeedbackTicket $ticket, CarbonImmutable $now): bool
    {
        try {
            $this->deletePrivateFiles($ticket);
            $this->anonymizeTicket($ticket, $now);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function deletePrivateFiles(FeedbackTicket $ticket): void
    {
        foreach ($ticket->attachments as $attachment) {
            $path = (string) $attachment->path;
            if ($path === '') {
                continue;
            }
            $deleted = Storage::disk((string) $attachment->disk)->delete($path);
            if ($deleted === false) {
                throw UnableToDeleteFile::atLocation($path, 'delete returned false');
            }
        }
    }

    private function anonymizeTicket(FeedbackTicket $ticket, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($ticket, $now): void {
            $locked = FeedbackTicket::query()
                ->whereKey($ticket->id)
                ->whereNull('anonymized_at')
                ->lockForUpdate()
                ->first();
            if ($locked === null) {
                return;
            }

            $locked->attachments()->delete();
            $locked->events()->update(['note' => null]);
            $locked->forceFill([
                'contact' => null,
                'content' => null,
                'visitor_hash' => null,
                'anonymized_at' => $now,
            ])->save();
        });
    }
}
