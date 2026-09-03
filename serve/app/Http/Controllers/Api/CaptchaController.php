<?php

namespace App\Http\Controllers\Api;

use App\Enums\CodeMode;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SMSCaptchaRequest;
use App\Services\ImageCaptchaService;
use App\Services\SystemConfig;
use App\Services\VerificationCodeService;
use Illuminate\Http\JsonResponse;
use Ugly\Base\Traits\ApiResource;

class CaptchaController extends Controller
{
    use ApiResource;

    // 图片验证码
    public function image(ImageCaptchaService $captchas): JsonResponse
    {
        return $this->success($captchas->issue());
    }

    // 短信验证码
    public function sms(
        SMSCaptchaRequest $request,
        VerificationCodeService $codes,
        ImageCaptchaService $images,
    ): JsonResponse {
        $mode = CodeMode::fromConfiguration(SystemConfig::get('send_code_mode'));
        if ((bool) SystemConfig::get('verify_code_is_open')
            && ! $images->verify(
                $request->string('key')->toString(),
                $request->string('captcha')->toString(),
            )) {
            throw new BusinessRuleException('IMAGE_CAPTCHA_INVALID', '图片验证码错误');
        }

        $template = (string) config(
            $mode === CodeMode::SMS
                ? 'services.ali_sms.template_code'
                : 'services.mail.template_code',
        );
        $codes->send(
            $mode,
            $request->string('tel')->toString(),
            $request->ip(),
            $request->string('purpose')->toString(),
            $template,
        );

        return $this->success([]);
    }
}
