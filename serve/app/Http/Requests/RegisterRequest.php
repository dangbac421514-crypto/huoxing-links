<?php

namespace App\Http\Requests;

use App\Enums\CodeMode;
use App\Services\SystemConfig;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
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

        return [
            'username' => [
                'required',
                $mode === CodeMode::SMS ? 'regex:/^1[3-9]\d{9}$/' : 'email',
                'unique:users,username',
            ],
            'password' => 'required|min:6|confirmed',
            'code' => ['required', 'digits:6'],
            'referral_code' => 'nullable|string|exists:users,referral_code', // 推荐码
        ];
    }

    public function messages(): array
    {
        $mode = CodeMode::fromConfiguration(SystemConfig::get('send_code_mode'));
        $txt = $mode === CodeMode::Email ? '邮箱' : '手机号';

        return [
            'username.required' => "请输入{$txt}！",
            'username.regex' => '手机号格式错误！',
            'username.email' => '不是有效的邮箱！',
            'username.unique' => "{$txt}已存在！",
            'password.required' => '请输入密码！',
            'password.confirmed' => '密码不一致！',
            'password.min' => '密码不少于6位！',
            'code.required' => '请输入验证码！',
            'code.digits' => '验证码格式错误！',
            'agent_id.required' => '请选择代理套餐！',
            'referral_code.required' => '请输入推荐码！',
        ];
    }
}
