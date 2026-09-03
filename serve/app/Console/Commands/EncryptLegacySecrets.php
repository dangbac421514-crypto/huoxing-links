<?php

namespace App\Console\Commands;

use App\Services\ProtectedSecretAuditService;
use App\Services\SanitizedLinkVisitRecorder;
use App\Services\SecretConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class EncryptLegacySecrets extends Command
{
    protected $signature = 'app:encrypt-legacy-secrets';

    protected $description = '将历史明文受保护配置加密并生成受保护备份';

    public function handle(SecretConfigService $secrets, ProtectedSecretAuditService $audits): int
    {
        $legacy = [];

        foreach ($secrets->secretSlugs() as $slug) {
            $raw = DB::table('sys_configs')->where('slug', $slug)->value('value');

            if (filled($raw) && ! str_starts_with($raw, 'enc:v1:')) {
                $legacy['sys_config:'.$slug] = $raw;
            }
        }

        foreach (DB::table('mini_programs')->select(['id', 'secret'])->whereNotNull('secret')->get() as $row) {
            if (! filled($row->secret) || $this->isEncryptedMiniProgramSecret($row->secret)) {
                continue;
            }

            $legacy['mini_program:'.$row->id] = $row->secret;
        }

        if ($legacy !== []) {
            $this->writeBackup($legacy);
        }

        DB::transaction(function () use ($secrets, $audits): void {
            foreach ($secrets->secretSlugs() as $slug) {
                $raw = DB::table('sys_configs')->where('slug', $slug)->value('value');

                if (filled($raw) && ! str_starts_with($raw, 'enc:v1:')) {
                    DB::table('sys_configs')->where('slug', $slug)->update([
                        'value' => 'enc:v1:'.Crypt::encryptString($raw),
                    ]);
                    $audits->record('protected_secret.migrated', $slug, null, 'legacy_migration', ['operation' => 'encrypt_legacy']);
                }
            }

            foreach (DB::table('mini_programs')->select(['id', 'secret'])->whereNotNull('secret')->get() as $row) {
                if (! filled($row->secret) || $this->isEncryptedMiniProgramSecret($row->secret)) {
                    continue;
                }

                DB::table('mini_programs')->where('id', $row->id)->update([
                    'secret' => Crypt::encryptString($row->secret),
                ]);
                $audits->record(
                    'protected_secret.migrated',
                    'mini_program:'.$row->id.':secret',
                    null,
                    'legacy_migration',
                    ['operation' => 'encrypt_legacy', 'resource_type' => 'mini_program', 'resource_id' => (int) $row->id],
                );
            }

            $this->scrubLegacyVisitLogs();
        });

        $forget = static fn (): bool => Cache::forget('_db_system_config_');
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($forget);
        } else {
            $forget();
        }

        return self::SUCCESS;
    }

    private function isEncryptedMiniProgramSecret(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function writeBackup(array $legacy): string
    {
        $directory = storage_path('app/private/secret-backups');
        File::ensureDirectoryExists($directory, 0700);
        chmod($directory, 0700);
        $path = $directory.'/'.now()->format('YmdHis').'-'.Str::uuid().'.json.enc';
        $contents = json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        File::put($path, Crypt::encryptString($contents));
        chmod($path, 0600);

        return $path;
    }

    private function scrubLegacyVisitLogs(): void
    {
        $sanitizer = app(SanitizedLinkVisitRecorder::class);

        DB::table('link_visit_logs')
            ->select(['id', 'cache'])
            ->whereNotNull('cache')
            ->chunkById(100, function ($rows) use ($sanitizer): void {
                foreach ($rows as $row) {
                    try {
                        $cache = json_decode($row->cache, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        continue;
                    }

                    if (! is_array($cache) || ! isset($cache['params']) || ! is_array($cache['params']) || ! array_key_exists('secret', $cache['params'])) {
                        continue;
                    }

                    $sanitized = $sanitizer->sanitize($cache);
                    DB::table('link_visit_logs')->where('id', $row->id)->update([
                        'cache' => json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ]);
                }
            });
    }
}
