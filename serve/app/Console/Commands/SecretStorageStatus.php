<?php

namespace App\Console\Commands;

use App\Services\SecretConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class SecretStorageStatus extends Command
{
    protected $signature = 'app:secret-storage-status {--json : 输出机器可读 JSON}';

    protected $description = '报告受保护配置的存储格式状态';

    public function handle(SecretConfigService $secrets): int
    {
        $plaintext = 0;
        $encrypted = 0;
        $compatible = true;

        foreach ($secrets->secretSlugs() as $slug) {
            $raw = DB::table('sys_configs')->where('slug', $slug)->value('value');

            if (! filled($raw)) {
                continue;
            }

            if (str_starts_with($raw, 'enc:v1:')) {
                $encrypted++;
                if (! $secrets->canDecryptStoredValue($raw)) {
                    $compatible = false;
                }
            } else {
                $plaintext++;
                $compatible = false;
            }
        }

        foreach (DB::table('mini_programs')->select(['secret'])->whereNotNull('secret')->get() as $row) {
            if (! filled($row->secret)) {
                continue;
            }

            if (! $this->looksLikeEncryptedValue($row->secret)) {
                $plaintext++;
                $compatible = false;

                continue;
            }

            $encrypted++;
            try {
                Crypt::decryptString($row->secret);
            } catch (\Throwable) {
                $compatible = false;
            }
        }

        $status = [
            'plaintext_count' => $plaintext,
            'encrypted_count' => $encrypted,
            'compatible' => $compatible,
        ];

        $this->line(json_encode($status, JSON_THROW_ON_ERROR));

        return $compatible ? self::SUCCESS : self::FAILURE;
    }

    private function looksLikeEncryptedValue(string $value): bool
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }

        try {
            $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return is_array($payload)
            && is_string($payload['iv'] ?? null)
            && is_string($payload['value'] ?? null)
            && is_string($payload['mac'] ?? null);
    }
}
