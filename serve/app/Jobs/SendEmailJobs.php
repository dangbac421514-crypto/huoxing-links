<?php

namespace App\Jobs;

use App\Contracts\EmailGateway;
use App\Jobs\Middleware\RateLimited;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class SendEmailJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $to;

    public $title;

    private string $encryptedCode;

    public $tries = 1;

    public $timeout = 60;

    public function __construct(string $to, string $code, ?string $title = null)
    {
        $this->to = $to;
        $this->title = $title ?? '邮箱验证码';
        $this->encryptedCode = Crypt::encryptString($code);
    }

    public function middleware()
    {
        return [new RateLimited];
    }

    /**
     * Execute the job.
     */
    public function handle(EmailGateway $gateway): void
    {
        $gateway->send($this->to, Crypt::decryptString($this->encryptedCode), $this->title);
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
