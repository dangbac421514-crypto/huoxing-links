<?php

namespace App\Http\Requests;

use App\Enums\CodeMode;
use App\Services\SystemConfig;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SMSCaptchaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $mode = CodeMode::fromConfiguration(SystemConfig::get('send_code_mode'));
        $rules = [
            'tel' => [
                'required',
                $mode === CodeMode::SMS ? 'regex:/^1[3-9]\d{9}$/' : 'email',
            ],
            'purpose' => ['required', 'in:register,reset_password'],
        ];

        if ((bool) SystemConfig::get('verify_code_is_open')) {
            $rules['captcha'] = ['required', 'string'];
            $rules['key'] = ['required', 'string'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'tel.required' => '请输入手机号！',
            'tel.regex' => '手机号格式错误！',
            'tel.email' => '不是有效的邮箱！',
            'captcha.required' => '请输入验证码！',
            'purpose.required' => '验证码用途无效！',
            'purpose.in' => '验证码用途无效！',
        ];
    }
}
