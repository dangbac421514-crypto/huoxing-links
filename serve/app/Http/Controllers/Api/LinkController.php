<?php

namespace App\Http\Controllers\Api;

use App\Enums\LinkType;
use App\Enums\SwitchType;
use App\Enums\UserType;
use App\Enums\UVLimitType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\LinkResolutionException;
use App\Exceptions\MiniProgramForbidden;
use App\Models\Link;
use App\Services\EntitlementService;
use App\Services\LinkAccessPolicy;
use App\Services\LinkShareUrl;
use App\Services\MiniProgramReferencePolicy;
use App\Services\QrRotationService;
use App\Support\LinkError;
use App\Support\LinkTypeParser;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpFoundation\Response;
use Ugly\Base\Http\Controllers\FormController;
use Ugly\Base\Services\FormService;

class LinkController extends FormController
{
    public function index(Request $request): JsonResponse
    {
        $user = auth('api')->user();
        $query = Link::search(['type' => '=', 'title' => 'like'], ['user:id,username'])
            ->withCount(['visitLogs as visit_uv_count' => function ($query) {
                $query->select(DB::raw('COUNT(DISTINCT visitor_hash)'));
            }])
            ->when(! $this->isAdmin($user), fn ($query) => $query->where('user_id', $user->id))
            ->when($request->filled('username'), fn ($query) => $query->whereHas(
                'user',
                fn ($query) => $query->where('username', 'like', '%'.$request->string('username')->toString().'%'),
            ))
            ->orderByDesc('id');

        return $this->paginate($query, fn (Link $link): array => $this->publicLink($link));
    }

    protected function form(): FormService
    {
        $rule = [
            'title' => 'required|string|max:80',
            'type' => ['required', new Enum(LinkType::class)],
            'icon' => 'required',
            'description' => 'nullable|string|max:500',
            'remark' => 'nullable|string',
            'expired_at' => 'prohibited',
        ];
        if (request('type') == LinkType::LANDING_MINI->value) {
            unset($rule['title'], $rule['description']);
        }

        $form = FormService::make(Link::class);
        $form->validate(fn () => array_merge($rule, $this->getConfigRules()), [
            'config.wx.qr.*.name.required' => '请填写二维码名称',
            'config.wx.qr.*.sort.required' => '请填写二维码排序',
            'config.wx.qr.*.path.required' => '请上传二维码',
            'config.url.starts_with' => '请正确上传草料图片类生成的二维码图片',
        ]);

        $actor = auth('api')->user();
        $form->policy(function (FormService $form) use ($actor): bool {
            $type = LinkTypeParser::parse(request()->input('type'));
            if (! $type) {
                return false;
            }

            $snapshot = null;
            if (! $this->isAdmin($actor)) {
                $snapshot = app(EntitlementService::class)->assertActive(
                    $actor,
                    CarbonImmutable::now('Asia/Shanghai'),
                );
                if (! in_array('*', $snapshot->allowTypes, true) && ! in_array($type->name, $snapshot->allowTypes, true)) {
                    throw new BusinessRuleException(LinkError::LINK_TYPE_FORBIDDEN, '当前套餐不支持该类型链接！');
                }
                if ($form->isCreate() && $snapshot->linkLimit <= Link::query()->where('user_id', $actor->id)->count()) {
                    throw new BusinessRuleException('LINK_LIMIT_EXCEEDED', '拥有的链接数量已达上限！');
                }
                if (
                    $type === LinkType::LANDING_MINI
                    && ! $snapshot->curIndex
                    && (
                        data_get($form->safeFormData, 'config.wx.avatar')
                        || data_get($form->safeFormData, 'config.wx.title')
                        || data_get($form->safeFormData, 'config.wx.sub_title')
                    )
                ) {
                    throw new BusinessRuleException('CUSTOM_LANDING_FORBIDDEN', '当前套餐不允许自定义落地页！');
                }
            }

            if (in_array($type, [LinkType::MINI_PROGRAM, LinkType::LANDING_MINI], true)) {
                try {
                    app(MiniProgramReferencePolicy::class)->assertAllowed(
                        $actor,
                        (int) data_get($form->safeFormData, 'config.min_id'),
                    );
                } catch (MiniProgramForbidden $exception) {
                    throw new BusinessRuleException($exception->errorCode, $exception->getMessage(), 403);
                }
            }

            if ($form->isEdit() && $form->getModel()->user_id !== $actor->id && ! $this->isAdmin($actor)) {
                throw new BusinessRuleException('LINK_FORBIDDEN', '无权操作该链接', 403);
            }

            return true;
        });

        $form->saving(function (FormService $form) use ($actor): void {
            $type = LinkTypeParser::parse(
                $form->safeFormData['type'] ?? $form->getModel()->getRawOriginal('type'),
            );

            if ($type === LinkType::LANDING_MINI) {
                $config = $form->safeFormData['config'] ?? null;
                if (is_array($config) && isset($config['wx']) && is_array($config['wx'])) {
                    if (isset($config['wx']['qr']) && is_array($config['wx']['qr'])) {
                        foreach ($config['wx']['qr'] as $index => $item) {
                            if (is_array($item)) {
                                $config['wx']['qr'][$index]['visit_uv'] = 0;
                            }
                        }
                    }
                    $form->safeFormData['config'] = $config;
                }

                if ($form->isCreate()) {
                    $wx = is_array($config['wx'] ?? null) ? $config['wx'] : [];
                    $form->safeFormData['title'] = $wx['title'] ?? '';
                    $form->safeFormData['description'] = $wx['sub_title'] ?? '';
                }
            }

            if ($form->isCreate()) {
                $form->safeFormData['status'] = 1;
                $form->safeFormData['manual_status'] = 1;
                $form->safeFormData['health_status'] = 1;
                $form->safeFormData['expired_at'] = null;
                $form->safeFormData['user_id'] = $actor->id;
            }
        });

        return $form;
    }

    public function show($id): JsonResponse
    {
        $link = $this->scopedQuery()->findOrFail($id);

        return $this->success($this->publicLink($link));
    }

    public function update($id): JsonResponse
    {
        // Scope the resource before FormService validates or evaluates any
        // business payload, so foreign tenants cannot probe validation state.
        $this->scopedQuery()->findOrFail($id);

        return parent::update($id);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $payload = $request->json()->all();
        if (! is_array($payload) || count($payload) !== 1 || ! array_key_exists('manual_status', $payload) || ! is_bool($payload['manual_status'])) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'manual_status 必须是布尔值且请求只能包含该字段',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $link = $this->scopedQuery()->findOrFail($id);
        $link->forceFill(['manual_status' => $payload['manual_status']])->save();

        return response()->json(['data' => $this->publicLink($link->fresh())]);
    }

    public function destroy($id): JsonResponse
    {
        $link = $this->scopedQuery()->find($id);
        if (! $link) {
            // Keep the delete endpoint non-enumerating: foreign and absent
            // rows share the same idempotent response.
            return response()->json(null, Response::HTTP_NO_CONTENT);
        }

        if (LinkTypeParser::parse($link->getRawOriginal('type')) === LinkType::LANDING_MINI) {
            app(QrRotationService::class)->forget($link);
        }
        $link->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function link_list(): JsonResponse
    {
        $list = Link::query()->where('user_id', auth('api')->id())
            ->orderByDesc('type')->orderByDesc('id')
            ->get(['id', 'icon', 'title', 'type']);

        return $this->success($list);
    }

    private function scopedQuery()
    {
        $user = auth('api')->user();

        return Link::query()->when(! $this->isAdmin($user), fn ($query) => $query->where('user_id', $user->id));
    }

    /** @return array<string, mixed> */
    private function publicLink(Link $link): array
    {
        $decision = app(LinkAccessPolicy::class)->check($link, CarbonImmutable::now('Asia/Shanghai'));
        $rawType = $link->getRawOriginal('type');
        $knownType = LinkTypeParser::parse($rawType) !== null;
        if ($knownType) {
            $data = $link->toArray();
        } else {
            // Eloquent enum casts throw ValueError for dirty legacy values;
            // serialize raw, non-secret fields explicitly for this boundary.
            $data = $link->getAttributes();
            $data['type'] = LinkTypeParser::normalize($rawType);
            $data['config'] = is_string($data['config'] ?? null)
                ? (json_decode($data['config'], true) ?: [])
                : (is_array($data['config'] ?? null) ? $data['config'] : []);
        }
        $data['manual_status'] = (bool) $link->manual_status;
        $data['health_status'] = (bool) $link->health_status;
        $data['effective_status'] = $decision->allowed && $knownType;
        try {
            $data['share_link'] = app(LinkShareUrl::class)->for($link);
        } catch (LinkResolutionException $exception) {
            throw new BusinessRuleException($exception->errorCode, $exception->getMessage(), $exception->status);
        }

        return $data;
    }

    private function isAdmin($user): bool
    {
        $type = $user?->getAttribute('type');

        return $type === UserType::Admin
            || ((is_int($type) || is_string($type)) && (int) $type === UserType::Admin->value);
    }

    /** @return array<string, mixed> */
    private function getConfigRules(): array
    {
        $type = request()->integer('type');
        $isLanding = $type === LinkType::LANDING_MINI->value;
        $rules = [
            'config.domain_id' => ['nullable', Rule::exists('domains', 'id')->where('enable', true)],
            'config.url' => 'required_unless:type,'.LinkType::LANDING_MINI->value,
        ];

        if (in_array($type, [LinkType::MINI_PROGRAM->value, LinkType::LANDING_MINI->value], true)) {
            $rules['config.min_id'] = 'required|integer';
            $rules['config.url'] = '';
        }
        if ($type === LinkType::CLI_QR->value) {
            $rules['config.url'] = 'required|url|starts_with:https://qr61.cn/';
        }
        if ($type === LinkType::QR_QQ->value) {
            $rules['config.url'] = 'required|url|starts_with:https://ym.link/';
        }
        if (! $isLanding) {
            return $rules;
        }

        return array_merge($rules, [
            'config.wx' => ['required', 'array'],
            'config.wx.avatar' => 'nullable|string',
            'config.wx.title' => 'nullable|string',
            'config.wx.sub_title' => 'nullable|string',
            'config.wx.qr' => ['required', 'array', 'min:1'],
            'config.wx.qr.*' => ['required', 'array'],
            'config.wx.qr.*.sort' => ['required', 'integer', 'min:0', 'max:200', 'distinct'],
            'config.wx.qr.*.name' => ['nullable', 'string'],
            'config.wx.qr.*.path' => ['required', 'string'],
            'config.wx.qr.*.uv_limit_num' => ['nullable', 'integer', 'min:1'],
            // This is display-only legacy data. Accept it for client
            // compatibility, then overwrite it to zero in the saving hook.
            'config.wx.qr.*.visit_uv' => 'nullable',
            'config.wx.qr.*.expired_at' => 'nullable|date_format:Y-m-d',
            'config.wx.switch_type' => ['required', new Enum(SwitchType::class)],
            'config.wx.uv_limit_type' => ['required', new Enum(UVLimitType::class)],
        ]);
    }
}
