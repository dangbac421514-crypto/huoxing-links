<?php

namespace App\Console\Commands;

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

    public function handle(SecretConfigService $secrets): int
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

        DB::transaction(function () use ($secrets): void {
            foreach ($secrets->secretSlugs() as $slug) {
                $raw = DB::table('sys_configs')->where('slug', $slug)->value('value');

                if (filled($raw) && ! str_starts_with($raw, 'enc:v1:')) {
                    DB::table('sys_configs')->where('slug', $slug)->update([
                        'value' => 'enc:v1:'.Crypt::encryptString($raw),
                    ]);
                }
            }

            foreach (DB::table('mini_programs')->select(['id', 'secret'])->whereNotNull('secret')->get() as $row) {
                if (! filled($row->secret) || $this->isEncryptedMiniProgramSecret($row->secret)) {
                    continue;
                }

                DB::table('mini_programs')->where('id', $row->id)->update([
                    'secret' => Crypt::encryptString($row->secret),
                ]);
            }
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
}
